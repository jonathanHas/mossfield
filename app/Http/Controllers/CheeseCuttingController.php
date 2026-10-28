<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheeseCutRequest;
use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseCuttingLog;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CheeseCuttingController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', CheeseCuttingLog::class);

        // Get all cheese batches that are ready to cut with FREE whole wheels.
        // The reservation is the units still owed (quantity_allocated −
        // quantity_fulfilled) over ALL allocations — never gated on
        // fulfilled_at (see BatchItem::getHoldableQuantityAttribute).
        $readyBatches = Batch::with([
            'product',
            'batchItems' => fn ($q) => $q->with('productVariant')
                ->withCount('sourceCuttingLogs')
                ->withSum('sourceConversionLogs as converted_wheels', 'wheels_converted')
                // Active write-offs only — a reversed write-off isn't one
                // (a legitimate filter, unlike fulfilled_at on reservations).
                ->withSum(['writeOffs as written_off_units' => fn ($q) => $q->whereNull('reversed_at')], 'quantity')
                ->withSum(
                    'orderAllocations as quantity_currently_allocated',
                    DB::raw('quantity_allocated - quantity_fulfilled')
                ),
        ])
            ->whereHas('product', function ($q) {
                $q->where('type', 'cheese');
            })
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ready_date')
                    ->orWhere('ready_date', '<=', now());
            })
            ->whereHas('batchItems', function ($q) {
                $q->where('quantity_remaining', '>', 0)
                    ->whereHas('productVariant', function ($pv) {
                        $pv->where('name', 'like', '%wheel%');
                    });
            })
            ->orderBy('production_date', 'asc')
            ->get();

        // Free wheels per item from the sums already loaded — the same
        // expression as available_quantity, without a SUM per row. A batch
        // whose wheels are all held or reserved is not "ready to cut", however
        // many physically remain, so it drops off the list here.
        $readyWithWheels = $readyBatches->count();

        $readyBatches = $readyBatches->filter(function (Batch $batch) {
            foreach ($batch->batchItems as $item) {
                $item->setAttribute('free_quantity', max(0, (int) $item->quantity_remaining
                    - (int) ($item->quantity_currently_allocated ?? 0)
                    - (int) $item->quantity_maturing))
                    ->syncOriginalAttribute('free_quantity');
            }

            return $batch->batchItems->contains(fn (BatchItem $item) => $item->free_quantity > 0
                && str_contains(strtolower($item->productVariant->name), 'wheel'));
        })->values();

        // For the empty state: ready batches existed but every wheel was
        // reserved or held — a different message from "nothing has matured".
        $spokenFor = $readyBatches->isEmpty() && $readyWithWheels > 0;

        return view('cheese-cutting.index', compact('readyBatches', 'spokenFor'));
    }

    public function create(Request $request, BatchItem $batchItem): View
    {
        $this->authorize('create', CheeseCuttingLog::class);

        // Ensure this is a wheel from a cheese batch
        if ($batchItem->batch->product->type !== 'cheese' ||
            ! str_contains(strtolower($batchItem->productVariant->name), 'wheel')) {
            abort(404, 'Only cheese wheels can be cut.');
        }

        // Get vacuum pack variants for this product
        $vacuumPackVariants = ProductVariant::where('product_id', $batchItem->batch->product_id)
            ->where('name', 'like', '%vacuum%')
            ->where('is_active', true)
            ->get();

        // A cut started from the batch page goes back there, not to the index.
        $returnTo = $request->query('return') === 'batch'
            ? route('batches.show', $batchItem->batch).'#cutting'
            : route('cheese-cutting.index');

        return view('cheese-cutting.create', [
            'batchItem' => $batchItem,
            'vacuumPackVariants' => $vacuumPackVariants,
            'returnTo' => $returnTo,
            'returnParam' => $request->query('return') === 'batch' ? 'batch' : null,
            // One token per rendered form: Back + submit re-posts it and is refused.
            'formToken' => (string) Str::uuid(),
        ]);
    }

    public function store(CheeseCutRequest $request, BatchItem $batchItem): RedirectResponse
    {
        // Rules (wheel weighed before cutting; packs ≤ wheel; packs × nominal
        // ≤ wheel; cut_date within [production, today]) live in CheeseCutRequest,
        // as does the policy check (its authorize() runs before this body).
        $this->authorize('create', CheeseCuttingLog::class);
        $validated = $request->validated();
        $formToken = $validated['form_token'] ?? null;

        if ($formToken && CheeseCuttingLog::where('form_token', $formToken)->exists()) {
            return $this->repeatSubmission($request, $batchItem);
        }

        // Ensure we have enough free wheels to cut — wheels reserved by an order
        // or set aside for maturing aren't cuttable. Redirect to the form
        // explicitly rather than back(): a refusal must land where the operator
        // can read it, whatever page the request came from.
        if ($batchItem->available_quantity < 1) {
            return redirect()->route('cheese-cutting.create', array_filter([
                'batchItem' => $batchItem, 'return' => $request->input('return') === 'batch' ? 'batch' : null,
            ]))
                ->withErrors(['error' => 'No free wheels remaining to cut (remaining wheels are allocated or set aside for maturing).']);
        }

        try {
            $this->cut($validated, $batchItem, $formToken);
        } catch (UniqueConstraintViolationException) {
            // Two near-simultaneous posts of the same form.
            return $this->repeatSubmission($request, $batchItem);
        }

        return $this->afterCut($request, $batchItem)
            ->with('success', 'Cheese wheel cut successfully! Created '.$validated['vacuum_packs_created'].' vacuum packs.');
    }

    private function cut(array $validated, BatchItem $batchItem, ?string $formToken): void
    {
        DB::transaction(function () use ($validated, $batchItem, $formToken) {
            // Get or create batch item for vacuum packs in the same batch
            $vacuumPackBatchItem = BatchItem::firstOrCreate(
                [
                    'batch_id' => $batchItem->batch_id,
                    'product_variant_id' => $validated['vacuum_pack_variant_id'],
                ],
                [
                    'quantity_produced' => 0,
                    'quantity_remaining' => 0,
                    'unit_weight_kg' => ProductVariant::find($validated['vacuum_pack_variant_id'])->weight_kg,
                ]
            );

            // Update quantities
            $vacuumPackBatchItem->increment('quantity_produced', $validated['vacuum_packs_created']);
            $vacuumPackBatchItem->increment('quantity_remaining', $validated['vacuum_packs_created']);

            // Reduce wheel count by 1
            $batchItem->decrement('quantity_remaining', 1);

            // Create cutting log
            CheeseCuttingLog::create([
                'source_batch_item_id' => $batchItem->id,
                'target_batch_item_id' => $vacuumPackBatchItem->id,
                'cut_date' => $validated['cut_date'],
                'vacuum_packs_created' => $validated['vacuum_packs_created'],
                'wheel_weight_kg' => $validated['wheel_weight_kg'],
                'total_weight_kg' => $validated['total_weight_kg'],
                'notes' => $validated['notes'] ?? null,
                'form_token' => $formToken,
            ]);
        });
    }

    private function repeatSubmission(Request $request, BatchItem $batchItem): RedirectResponse
    {
        return $this->afterCut($request, $batchItem)
            ->with('success', 'Already recorded — this was a repeat submission.');
    }

    /** Where a cut lands: the batch page when it was started there, else the index. */
    private function afterCut(Request $request, BatchItem $batchItem): RedirectResponse
    {
        return $request->input('return') === 'batch'
            ? redirect()->route('batches.show', $batchItem->batch)->withFragment('cutting')
            : redirect()->route('cheese-cutting.index');
    }
}
