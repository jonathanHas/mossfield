<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseCuttingLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pass 2b of docs/planImp/TESTING_AND_FIXES.md finding 2: a cheese batch's finished weight is
 * derived per wheel from the four states every wheel is in — on hand (nominal),
 * dispatched whole (measured at fulfilment), cut (measured at cutting), converted
 * out to Mature (nominal) — and the cut wheel's packs are never summed on top.
 * The reconciliation `on hand + dispatched + cut + converted = produced` is the
 * assertion that would have caught the original double count.
 */
class BatchFinishedWeightTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private User $factory;

    private Customer $shop;

    private Product $farmhouse;

    private ProductVariant $wheelVariant;

    private ProductVariant $vacuum;

    private Product $mature;

    private ProductVariant $matureWheel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();
        $this->factory = User::factory()->factoryWorker()->create();

        $this->shop = Customer::create([
            'name' => 'Weight Shop', 'email' => 'weight@example.ie',
            'address' => '1 Main St', 'city' => 'Birr', 'postal_code' => 'R42', 'country' => 'Ireland',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);

        $this->farmhouse = Product::create([
            'name' => 'Mossfield Farmhouse Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true,
        ]);
        $this->wheelVariant = ProductVariant::create([
            'product_id' => $this->farmhouse->id, 'name' => 'Whole Wheel', 'size' => 'wheel', 'unit' => 'wheel',
            'weight_kg' => 5.000, 'base_price' => 16.00, 'is_variable_weight' => true, 'is_priced_by_weight' => true, 'is_active' => true,
        ]);
        $this->vacuum = ProductVariant::create([
            'product_id' => $this->farmhouse->id, 'name' => 'Vacuum Pack', 'size' => 'pack', 'unit' => 'pack',
            'weight_kg' => 0.250, 'base_price' => 4.50, 'is_variable_weight' => true, 'is_bulk_weighed' => true, 'is_active' => true,
        ]);

        // The release destination (see CheeseConversionController::MATURE_PRODUCT_NAME).
        $this->mature = Product::create([
            'name' => 'Mossfield Mature Cheese', 'type' => 'cheese', 'maturation_days' => 150, 'is_active' => true,
        ]);
        $this->matureWheel = ProductVariant::create([
            'product_id' => $this->mature->id, 'name' => 'Whole Wheel', 'size' => 'wheel', 'unit' => 'wheel',
            'weight_kg' => 5.000, 'base_price' => 20.00, 'is_variable_weight' => true, 'is_priced_by_weight' => true, 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- fixtures

    /** A farmhouse batch 6 months old (ready to sell AND eligible for release) with $wheels wheels. */
    private function wheels(int $wheels = 10, float $rawMilk = 1000): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->farmhouse->id,
            'production_date' => now()->subMonths(6)->toDateString(),
            'raw_milk_litres' => $rawMilk,
            'wheels_produced' => $wheels,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->wheelVariant->id,
            'quantity_produced' => $wheels, 'quantity_remaining' => $wheels, 'unit_weight_kg' => 5.000,
        ]);
    }

    /** Pick $weights wheels off $batchItem through the picking flow (one order, one line). */
    private function pick(BatchItem $batchItem, array $weights, ?ProductVariant $variant = null): OrderAllocation
    {
        $variant ??= $this->wheelVariant;
        $order = Order::create([
            'customer_id' => $this->shop->id, 'order_date' => now()->toDateString(), 'delivery_date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $variant->id,
            'quantity_ordered' => count($weights), 'unit_price' => $variant->base_price,
        ]);

        $this->actingAs($this->factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id, 'quantity' => count($weights), 'weights' => $weights,
        ])->assertSessionHasNoErrors();

        return $item->orderAllocations()->sole();
    }

    private function cut(BatchItem $batchItem, float $wheelKg, int $packs = 18, float $packsKg = 4.680): void
    {
        $this->actingAs($this->office)->post(route('cheese-cutting.store', $batchItem), [
            'cut_date' => today()->toDateString(), 'vacuum_pack_variant_id' => $this->vacuum->id,
            'wheel_weight_kg' => $wheelKg, 'vacuum_packs_created' => $packs, 'total_weight_kg' => $packsKg,
        ])->assertSessionHasNoErrors();
    }

    private function convert(BatchItem $batchItem, int $wheels): void
    {
        $this->actingAs($this->office)->post(route('cheese-conversion.hold', $batchItem), ['quantity' => $wheels])->assertSessionHasNoErrors();
        $this->actingAs($this->office)->post(route('cheese-conversion.release', $batchItem), ['quantity' => $wheels])->assertSessionHasNoErrors();
    }

    private function loaded(Batch $batch): Batch
    {
        return Batch::with([
            'product', 'batchItems.productVariant', 'batchItems.orderAllocations',
            'batchItems.sourceCuttingLogs', 'batchItems.sourceConversionLogs', 'batchItems.targetCuttingLogs',
            'batchItems.writeOffs',
        ])->findOrFail($batch->id);
    }

    // ---------------------------------------------------------------- cases

    public function test_every_wheel_is_in_exactly_one_state_and_they_reconcile_to_produced(): void
    {
        $wheelItem = $this->wheels(10);

        $this->pick($wheelItem, [4.8, 5.2]);                 // 2 dispatched, measured 10.000
        $this->cut($wheelItem->fresh(), 5.120);              // 1 cut, measured 5.120
        $this->convert($wheelItem->fresh(), 1);              // 1 converted, nominal 5.000

        $batch = $this->loaded($wheelItem->batch);
        $wheel = $batch->batchItems->firstWhere('id', $wheelItem->id)->weight_breakdown;

        $this->assertSame('wheel', $wheel['kind']);
        $this->assertSame(10, $wheel['produced']);
        $this->assertSame(6, $wheel['on_hand']);
        $this->assertSame(2, $wheel['dispatched']);
        $this->assertSame(1, $wheel['cut']);
        $this->assertSame(1, $wheel['converted']);
        $this->assertTrue($wheel['reconciles'], '6 + 2 + 1 + 1 = 10');

        $this->assertSame(3, $wheel['weighed'], '2 dispatched + 1 measured cut');
        $this->assertSame(15.12, $wheel['measured_kg']);
        $this->assertSame(35.0, $wheel['nominal_kg'], '(6 on hand + 1 converted) × 5.000');
        $this->assertSame(50.12, $wheel['total_kg']);

        // The 18 packs that came off the cut wheel add nothing — the wheel already did.
        $packs = $batch->batchItems->firstWhere('product_variant_id', $this->vacuum->id)->weight_breakdown;
        $this->assertSame('pack', $packs['kind']);
        $this->assertSame(0.0, $packs['total_kg']);

        $this->assertSame(50.12, $batch->finished_product_weight);
        $this->assertSame(0.05012, round($batch->production_yield, 5));

        $coverage = $batch->weight_coverage;
        $this->assertTrue($coverage['has_wheels']);
        $this->assertSame(10, $coverage['wheels_produced']);
        $this->assertSame(3, $coverage['wheels_weighed']);
        $this->assertSame(15.12, $coverage['measured_kg']);
        $this->assertSame(35.0, $coverage['nominal_kg']);
        $this->assertTrue($coverage['reconciles']);
    }

    /** Write off $qty units of $item (weighed when $kg is given). */
    private function writeOff(BatchItem $item, int $qty, ?float $kg = null): \App\Models\StockWriteOff
    {
        return app(\App\Services\StockWriteOffService::class)->record(
            $item->fresh(), \App\Models\WriteOffReason::where('code', 'spoiled')->firstOrFail(),
            $qty, $kg, null, today(), $this->office,
        );
    }

    public function test_written_off_wheels_are_the_fifth_state_and_still_count_in_the_weight(): void
    {
        $wheelItem = $this->wheels(10);

        $this->pick($wheelItem, [4.8, 5.2]);                 // 2 dispatched, measured 10.000
        $this->cut($wheelItem->fresh(), 5.120);              // 1 cut, measured 5.120
        $this->convert($wheelItem->fresh(), 1);              // 1 converted, nominal 5.000
        $this->writeOff($wheelItem, 1, 4.900);               // 1 written off, measured 4.900
        $this->writeOff($wheelItem, 1);                      // 1 written off, unweighed → nominal
        $reversed = $this->writeOff($wheelItem, 1);          // reversed: not a write-off
        app(\App\Services\StockWriteOffService::class)->reverse($reversed, $this->office);

        $batch = $this->loaded($wheelItem->batch);
        $wheel = $batch->batchItems->firstWhere('id', $wheelItem->id)->weight_breakdown;

        $this->assertSame(4, $wheel['on_hand']);
        $this->assertSame(2, $wheel['dispatched']);
        $this->assertSame(1, $wheel['cut']);
        $this->assertSame(1, $wheel['converted']);
        $this->assertSame(2, $wheel['written_off']);
        $this->assertTrue($wheel['reconciles'], '4 + 2 + 1 + 1 + 2 = 10');

        $this->assertSame(4, $wheel['weighed'], '2 dispatched + 1 measured cut + 1 weighed write-off');
        $this->assertSame(20.02, $wheel['measured_kg']);
        $this->assertSame(30.0, $wheel['nominal_kg'], '(4 on hand + 1 converted + 1 unweighed write-off) × 5.000');

        // The make produced them: written-off wheels stay in the finished weight.
        $this->assertSame(50.02, $batch->finished_product_weight);
        $this->assertSame(4, $batch->weight_coverage['wheels_weighed']);
        $this->assertTrue($batch->weight_coverage['reconciles']);
    }

    public function test_a_milk_write_off_is_counted_but_does_not_change_the_weight(): void
    {
        $milk = Product::create(['name' => 'Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle', 'weight_kg' => 1.000, 'base_price' => 2.00, 'is_active' => true,
        ]);
        $batch = Batch::create(['product_id' => $milk->id, 'production_date' => today()->toDateString(), 'raw_milk_litres' => 200, 'status' => 'active']);
        $item = BatchItem::create(['batch_id' => $batch->id, 'product_variant_id' => $bottle->id, 'quantity_produced' => 200, 'quantity_remaining' => 200, 'unit_weight_kg' => 1.000]);
        $this->writeOff($item, 10);

        $breakdown = $this->loaded($batch)->batchItems->first()->weight_breakdown;
        $this->assertSame(10, $breakdown['written_off']);
        $this->assertSame(190, $breakdown['on_hand']);
        $this->assertSame(200.0, $breakdown['nominal_kg'], 'produced × unit — they were made');
        $this->assertSame(200.0, $this->loaded($batch)->finished_product_weight);
    }

    public function test_an_unloaded_write_off_relation_reads_as_none_not_a_query(): void
    {
        $wheelItem = $this->wheels(4);
        $this->writeOff($wheelItem, 1);

        $item = BatchItem::with(['batch.product', 'productVariant', 'orderAllocations', 'sourceCuttingLogs', 'sourceConversionLogs', 'targetCuttingLogs'])
            ->findOrFail($wheelItem->id);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $breakdown = $item->weight_breakdown;
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertSame(0, $queries);
        $this->assertSame(0, $breakdown['written_off']);
    }

    public function test_a_cut_never_adds_the_pack_weight(): void
    {
        $wheelItem = $this->wheels(10);
        $before = $this->loaded($wheelItem->batch);
        $this->assertSame(50.0, $before->finished_product_weight, '10 × 5.000 nominal');

        // A wheel weighed at exactly nominal, cut into 4.68 kg of packs.
        $this->cut($wheelItem, 5.000);

        $after = $this->loaded($wheelItem->batch);
        $this->assertSame(50.0, $after->finished_product_weight, 'unchanged — not 54.68');
        $this->assertSame(5.0, $after->weight_coverage['measured_kg']);
        $this->assertSame(45.0, $after->weight_coverage['nominal_kg']);

        // A cut moves the figure only by (measured − nominal) of that one wheel.
        $this->cut($wheelItem->fresh(), 4.900);
        $this->assertSame(49.9, $this->loaded($wheelItem->batch)->finished_product_weight);
    }

    public function test_an_unweighed_legacy_cut_falls_back_to_nominal(): void
    {
        $wheelItem = $this->wheels(10);
        $packs = BatchItem::create([
            'batch_id' => $wheelItem->batch_id, 'product_variant_id' => $this->vacuum->id,
            'quantity_produced' => 6, 'quantity_remaining' => 6, 'unit_weight_kg' => 0.250,
        ]);
        // Pre-pass-2a shape: packs weighed, wheel never was.
        CheeseCuttingLog::create([
            'source_batch_item_id' => $wheelItem->id, 'target_batch_item_id' => $packs->id,
            'cut_date' => today()->subDays(10)->toDateString(), 'vacuum_packs_created' => 6, 'total_weight_kg' => 1.600,
        ]);
        $wheelItem->decrement('quantity_remaining', 1);

        $batch = $this->loaded($wheelItem->batch);
        $wheel = $batch->batchItems->firstWhere('id', $wheelItem->id)->weight_breakdown;

        $this->assertSame(1, $wheel['cut']);
        $this->assertSame(0, $wheel['weighed']);
        $this->assertSame(50.0, $batch->finished_product_weight, '9 on hand + 1 unweighed cut, all nominal');
        $this->assertSame(0, $batch->weight_coverage['wheels_weighed']);
        $this->assertTrue($batch->weight_coverage['reconciles']);
    }

    public function test_packs_not_traceable_to_a_cut_still_count_nominally(): void
    {
        $wheelItem = $this->wheels(10);
        // 24 packs on the item, but the cut logs only account for 18 of them.
        $packs = BatchItem::create([
            'batch_id' => $wheelItem->batch_id, 'product_variant_id' => $this->vacuum->id,
            'quantity_produced' => 24, 'quantity_remaining' => 24, 'unit_weight_kg' => 0.250,
        ]);
        CheeseCuttingLog::create([
            'source_batch_item_id' => $wheelItem->id, 'target_batch_item_id' => $packs->id,
            'cut_date' => today()->toDateString(), 'vacuum_packs_created' => 18, 'wheel_weight_kg' => 5.000, 'total_weight_kg' => 4.680,
        ]);
        $wheelItem->decrement('quantity_remaining', 1);

        $batch = $this->loaded($wheelItem->batch);
        $packBreakdown = $batch->batchItems->firstWhere('id', $packs->id)->weight_breakdown;

        $this->assertSame(1.5, $packBreakdown['total_kg'], '6 unexplained packs × 0.250, nominal');
        $this->assertSame(51.5, $batch->finished_product_weight, '9 × 5 + 5.000 measured cut + 1.5 residual packs');

        // Explain the other 6 and the residual disappears.
        CheeseCuttingLog::create([
            'source_batch_item_id' => $wheelItem->id, 'target_batch_item_id' => $packs->id,
            'cut_date' => today()->toDateString(), 'vacuum_packs_created' => 6, 'wheel_weight_kg' => 5.000, 'total_weight_kg' => 1.600,
        ]);
        $wheelItem->decrement('quantity_remaining', 1);
        $this->assertSame(0.0, $this->loaded($wheelItem->batch)->batchItems->firstWhere('id', $packs->id)->weight_breakdown['total_kg']);
    }

    public function test_dispatched_wheels_use_the_measured_total_even_without_a_unit_list(): void
    {
        $wheelItem = $this->wheels(10);
        $allocation = $this->pick($wheelItem, [4.87, 4.2]);

        // The pre-pass-2a shape: a measured total with no per-unit list.
        $allocation->forceFill(['unit_weights_kg' => null])->save();
        $this->assertSame('9.070', (string) $allocation->fresh()->actual_weight_kg);

        $batch = $this->loaded($wheelItem->batch);
        $wheel = $batch->batchItems->firstWhere('id', $wheelItem->id)->weight_breakdown;

        $this->assertSame(2, $wheel['dispatched']);
        $this->assertSame(2, $wheel['weighed']);
        $this->assertSame(9.07, $wheel['measured_kg']);
        $this->assertSame(49.07, $batch->finished_product_weight, '8 × 5.000 + 9.070');
    }

    public function test_a_milk_batch_is_wholly_nominal_and_its_yield_is_bottled_over_raw(): void
    {
        $milk = Product::create(['name' => 'Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle', 'weight_kg' => 1.000, 'base_price' => 2.00, 'is_active' => true,
        ]);
        $batch = Batch::create(['product_id' => $milk->id, 'production_date' => today()->toDateString(), 'raw_milk_litres' => 500, 'status' => 'active']);
        BatchItem::create(['batch_id' => $batch->id, 'product_variant_id' => $bottle->id, 'quantity_produced' => 432, 'quantity_remaining' => 300, 'unit_weight_kg' => 1.000]);

        $batch = $this->loaded($batch);
        $this->assertSame('unit', $batch->batchItems->first()->weight_breakdown['kind']);
        $this->assertSame(432.0, $batch->finished_product_weight, 'produced × unit weight, as before');
        // Computed, no longer a hard-coded 1.0 (OPEN_QUESTIONS #5, F-06).
        $this->assertSame(0.864, round($batch->production_yield, 3));
        $this->assertFalse($batch->weight_coverage['has_wheels']);
        $this->assertSame(432.0, $batch->weight_coverage['nominal_kg']);
    }

    public function test_the_batch_page_states_how_much_is_measured(): void
    {
        $wheelItem = $this->wheels(10);
        $this->pick($wheelItem, [4.8, 5.2]);
        $this->cut($wheelItem->fresh(), 5.120);

        $this->actingAs($this->office)->get(route('batches.show', $wheelItem->batch))
            ->assertOk()
            ->assertSee('~50.12 kg')
            ->assertSee('3 of 10 wheels weighed')
            ->assertSee('Yield ~5.0%')
            ->assertSee('On hand (nominal)')
            ->assertSee('~35.00 kg'); // 7 on hand × 5.000, the wheel row
    }

    public function test_a_wholly_measured_batch_drops_the_tilde(): void
    {
        $wheelItem = $this->wheels(2);
        $this->pick($wheelItem, [4.8, 5.2]);

        $batch = $this->loaded($wheelItem->batch);
        $this->assertSame(0.0, $batch->weight_coverage['nominal_kg']);
        $this->actingAs($this->office)->get(route('batches.show', $batch))
            ->assertOk()
            ->assertSee('2 of 2 wheels weighed')
            ->assertDontSee('~10.00 kg')
            ->assertSee('10.00 kg');
    }

    public function test_a_mature_batch_uses_the_same_derivation(): void
    {
        $wheelItem = $this->wheels(10);
        $this->convert($wheelItem, 1);

        $matureBatch = Batch::where('source_batch_id', $wheelItem->batch_id)->firstOrFail();
        $matureItem = $matureBatch->batchItems()->firstOrFail();

        $loaded = $this->loaded($matureBatch);
        $this->assertSame(5.0, $loaded->finished_product_weight, '1 converted-in wheel, nominal');
        $this->assertSame(0, $loaded->weight_coverage['wheels_weighed']);
        $this->assertSame(1, $loaded->weight_coverage['wheels_produced']);
        $this->assertNull($loaded->production_yield, 'no raw milk on a converted batch');

        // Backdate readiness so it can be picked, then sell it weighed.
        $matureBatch->forceFill(['ready_date' => today()->subDay()->toDateString()])->save();
        $this->pick($matureItem, [4.65], $this->matureWheel);

        $loaded = $this->loaded($matureBatch);
        $this->assertSame(4.65, $loaded->finished_product_weight);
        $this->assertSame(1, $loaded->weight_coverage['wheels_weighed']);
        $this->assertTrue($loaded->weight_coverage['reconciles']);
    }

    public function test_the_stock_overview_counts_converted_wheels_not_conversion_logs(): void
    {
        $wheelItem = $this->wheels(10);
        $this->convert($wheelItem, 3); // one log, three wheels

        $row = collect(app(StockOverviewService::class)->build()['cheese']['wheels'])
            ->first(fn ($r) => $r['batch_code'] === $wheelItem->batch->batch_code);

        $this->assertSame(3, $row['segments']['converted']);
        $this->assertSame(7, $row['segments']['available']);
    }
}
