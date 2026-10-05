<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockWriteOffRequest;
use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\StockWriteOff;
use App\Models\WriteOffReason;
use App\Services\StockException;
use App\Services\StockWriteOffService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Write-offs from the batch page (office/admin). The arithmetic and the
 * refusals live in StockWriteOffService; this turns them into flashes.
 */
class StockWriteOffController extends Controller
{
    public function __construct(private StockWriteOffService $writeOffs) {}

    public function store(StockWriteOffRequest $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validated();
        $item = BatchItem::with('productVariant')->findOrFail($validated['batch_item_id']);
        $reason = WriteOffReason::findOrFail($validated['write_off_reason_id']);

        try {
            $writeOff = $this->writeOffs->record(
                $item,
                $reason,
                (int) $validated['quantity'],
                isset($validated['weight_kg']) ? (float) $validated['weight_kg'] : null,
                $validated['note'] ?? null,
                CarbonImmutable::parse($validated['written_off_on']),
                $request->user(),
            );
        } catch (StockException $e) {
            return $this->toPanel($batch)->withErrors(['write_off' => $e->getMessage()])->withInput();
        }

        $message = "Written off {$writeOff->quantity} × {$item->productVariant->name} — {$reason->label}.";
        if ($writeOff->holdReducedTo !== null) {
            $message .= " The maturing hold was reduced to {$writeOff->holdReducedTo}.";
        }

        return $this->toPanel($batch)->with('success', $message);
    }

    public function storeExpired(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorize('create', StockWriteOff::class);
        $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        // What stays behind because it's owed to orders, for the flash.
        $batch->load('batchItems.productVariant');
        $kept = $batch->batchItems
            ->map(fn (BatchItem $item) => [$item, (int) $item->quantity_remaining - $item->holdable_quantity])
            ->filter(fn ($pair) => $pair[1] > 0);

        try {
            $rows = $this->writeOffs->writeOffExpired(
                $batch,
                $request->input('note') ?: 'Expired — written off from the batch page',
                $request->user(),
            );
        } catch (StockException $e) {
            return $this->toPanel($batch)->withErrors(['write_off' => $e->getMessage()]);
        }

        $rows->load('batchItem.productVariant');
        $message = 'Wrote off expired stock: '.$rows
            ->map(fn (StockWriteOff $row) => $row->quantity.' × '.$row->batchItem->productVariant->name)
            ->implode(', ').'.';
        if ($kept->isNotEmpty()) {
            $message .= ' Kept (reserved for orders): '.$kept
                ->map(fn ($pair) => $pair[1].' × '.$pair[0]->productVariant->name)
                ->implode(', ').'.';
        }

        return $this->toPanel($batch)->with('success', $message);
    }

    public function reverse(Request $request, StockWriteOff $writeOff): RedirectResponse
    {
        $this->authorize('update', $writeOff);
        $batch = $writeOff->batchItem->batch;

        try {
            $this->writeOffs->reverse($writeOff, $request->user());
        } catch (StockException $e) {
            return $this->toPanel($batch)->withErrors(['write_off' => $e->getMessage()]);
        }

        return $this->toPanel($batch)->with('success', 'Write-off reversed — '.$writeOff->quantity.' '
            .Str::plural('unit', $writeOff->quantity).' returned to free stock.');
    }

    private function toPanel(Batch $batch): RedirectResponse
    {
        return redirect()->route('batches.show', $batch)->withFragment('write-offs');
    }
}
