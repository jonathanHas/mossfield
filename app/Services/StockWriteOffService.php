<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\StockWriteOff;
use App\Models\User;
use App\Models\WriteOffReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock that is lost — spoiled, dropped, sampled, expired — leaves through
 * here (OPEN_QUESTIONS #1). The units come off batch_items.quantity_remaining,
 * which every free-stock surface already reads, so nothing else needs to
 * know; the StockWriteOff row is the record, reversible but never deleted.
 */
class StockWriteOffService
{
    /** A weight above this × nominal × quantity is a misplaced decimal point (the cycle-3 bound). */
    private const WEIGHT_BOUND_FACTOR = 3;

    public function record(
        BatchItem $item,
        WriteOffReason $reason,
        int $quantity,
        ?float $weightKg,
        ?string $note,
        CarbonInterface $on,
        User $by,
    ): StockWriteOff {
        if ($quantity < 1) {
            throw new StockException('Write off at least one unit.');
        }
        if (! $reason->is_active) {
            throw new StockException("The reason \"{$reason->label}\" is no longer in use — choose another.");
        }

        return DB::transaction(function () use ($item, $reason, $quantity, $weightKg, $note, $on, $by) {
            /** @var BatchItem $locked */
            $locked = BatchItem::with(['batch', 'productVariant'])->whereKey($item->id)->lockForUpdate()->firstOrFail();

            // Never reserved stock: the bound is holdable (remaining − units
            // still owed to orders). Held (maturing) wheels CAN go — a
            // maturing wheel going mouldy is the main case.
            $holdable = $locked->holdable_quantity;
            if ($quantity > $holdable) {
                $reserved = (int) $locked->quantity_remaining - $holdable;
                throw new StockException($reserved > 0
                    ? "Only {$holdable} units can be written off — {$reserved} are reserved for orders. Release them first."
                    : "Only {$holdable} units can be written off — that's all that's left.");
            }

            $variant = $locked->productVariant;
            if ($weightKg !== null) {
                if (! $variant?->is_variable_weight) {
                    throw new StockException("{$variant?->name} isn't weighed — leave the weight blank.");
                }
                $nominal = (float) ($variant->weight_kg ?? 0);
                if ($nominal > 0 && $weightKg > self::WEIGHT_BOUND_FACTOR * $nominal * $quantity) {
                    $max = round(self::WEIGHT_BOUND_FACTOR * $nominal * $quantity, 3);
                    throw new StockException("{$quantity} × {$variant->name} can't weigh more than {$max} kg — a unit is usually about ".number_format($nominal, 3).' kg. Check the decimal point.');
                }
            }

            $batch = $locked->batch;
            if ($batch->production_date && $on->copy()->startOfDay()->lt($batch->production_date->copy()->startOfDay())) {
                throw new StockException('The write-off date can\'t be before the batch was produced ('.$batch->production_date->format('d/m/Y').').');
            }
            if ($on->copy()->startOfDay()->gt(today())) {
                throw new StockException('The write-off date can\'t be in the future.');
            }

            $stillOwed = (int) $locked->quantity_remaining - $holdable; // units reserved for orders
            $locked->quantity_remaining -= $quantity;

            // A hold can't exceed what's left to hold (remaining − still owed).
            $holdBefore = (int) $locked->quantity_maturing;
            $locked->quantity_maturing = min($holdBefore, max(0, (int) $locked->quantity_remaining - $stillOwed));
            $locked->save();

            $writeOff = StockWriteOff::create([
                'batch_item_id' => $locked->id,
                'write_off_reason_id' => $reason->id,
                'quantity' => $quantity,
                'weight_kg' => $weightKg,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'written_off_on' => $on->toDateString(),
                'written_off_by' => $by->id,
            ]);

            $writeOff->holdReducedTo = $locked->quantity_maturing < $holdBefore ? (int) $locked->quantity_maturing : null;

            return $writeOff;
        });
    }

    /** Put the units back into free stock. Does not restore a maturing hold. */
    public function reverse(StockWriteOff $writeOff, User $by): void
    {
        DB::transaction(function () use ($writeOff, $by) {
            /** @var StockWriteOff $locked */
            $locked = StockWriteOff::whereKey($writeOff->id)->lockForUpdate()->firstOrFail();
            if ($locked->isReversed()) {
                throw new StockException('That write-off was already reversed.');
            }

            /** @var BatchItem $item */
            $item = BatchItem::whereKey($locked->batch_item_id)->lockForUpdate()->firstOrFail();
            if ((int) $item->quantity_remaining + $locked->quantity > (int) $item->quantity_produced) {
                // Can't happen through this service; say so rather than corrupt the count.
                throw new StockException('Reversing this would leave more on hand than the batch produced — the stock record needs checking first.');
            }

            $item->increment('quantity_remaining', $locked->quantity);
            $locked->update(['reversed_at' => now(), 'reversed_by' => $by->id]);
        });

        $writeOff->refresh();
    }

    /**
     * Write off everything that can be written off on an expired batch:
     * each item's holdable units, reason "expired". Reserved units stay
     * (they're owed to orders); items with nothing holdable are skipped.
     *
     * @return Collection<int, StockWriteOff>
     */
    public function writeOffExpired(Batch $batch, ?string $note, User $by): Collection
    {
        if (! $batch->isExpired()) {
            throw new StockException("Batch {$batch->batch_code} hasn't expired.");
        }

        $reason = WriteOffReason::where('code', 'expired')->first();
        if (! $reason) {
            throw new StockException('The "expired" write-off reason is missing — add it under Write-off reasons.');
        }
        if (! $reason->is_active) {
            throw new StockException('The "expired" write-off reason has been deactivated — reactivate it under Write-off reasons.');
        }

        return DB::transaction(function () use ($batch, $note, $by, $reason) {
            $rows = new EloquentCollection;
            foreach ($batch->batchItems()->with('productVariant')->get() as $item) {
                $holdable = $item->holdable_quantity;
                if ($holdable < 1) {
                    continue;
                }
                $rows->push($this->record($item, $reason, $holdable, null, $note, today(), $by));
            }

            if ($rows->isEmpty()) {
                throw new StockException("Nothing on {$batch->batch_code} can be written off — what's left is reserved for orders.");
            }

            return $rows;
        });
    }
}
