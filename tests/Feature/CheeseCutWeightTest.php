<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\CheeseCuttingLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pass 2a of docs/planImp/TESTING_AND_FIXES.md finding 2 (+ finding 3): the cut captures
 * the WHEEL's weight as well as the packs'. Pack weight can then never exceed
 * the wheel it came from — a real measured ceiling, not the nominal guess —
 * and the difference is the trim loss Jon reconciles production against.
 */
class CheeseCutWeightTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Product $farmhouse;

    private ProductVariant $wheelVariant;

    private ProductVariant $vacuum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();

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
    }

    /** A ready farmhouse batch produced $daysAgo days ago with $wheels wheels. */
    private function wheels(int $wheels = 5, int $daysAgo = 180): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->farmhouse->id,
            'production_date' => today()->subDays($daysAgo)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->wheelVariant->id,
            'quantity_produced' => $wheels, 'quantity_remaining' => $wheels, 'unit_weight_kg' => 5.000,
        ]);
    }

    /** A sane cut: a 5.120 kg wheel into 18 packs weighing 4.680 kg (0.440 kg trim). */
    private function cut(array $overrides = []): array
    {
        return array_merge([
            'cut_date' => today()->toDateString(),
            'vacuum_pack_variant_id' => $this->vacuum->id,
            'wheel_weight_kg' => 5.120,
            'vacuum_packs_created' => 18,
            'total_weight_kg' => 4.680,
        ], $overrides);
    }

    private function cutPost(BatchItem $wheel, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->office)
            ->from(route('cheese-cutting.create', $wheel))
            ->post(route('cheese-cutting.store', $wheel), $payload);
    }

    // ------------------------------------------------- the wheel is weighed

    public function test_the_wheel_weight_is_required(): void
    {
        $wheel = $this->wheels();

        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => null]))->assertSessionHasErrors('wheel_weight_kg');
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 0]))->assertSessionHasErrors('wheel_weight_kg');
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 'five']))->assertSessionHasErrors('wheel_weight_kg');

        $this->assertSame(0, CheeseCuttingLog::count());
        $this->assertSame(5, $wheel->fresh()->quantity_remaining);
    }

    public function test_a_valid_cut_records_both_weights_and_the_waste_between_them(): void
    {
        $wheel = $this->wheels();

        $this->cutPost($wheel, $this->cut())->assertRedirect(route('cheese-cutting.index'))->assertSessionHasNoErrors();

        $log = CheeseCuttingLog::sole();
        $this->assertSame('5.120', (string) $log->wheel_weight_kg);
        $this->assertSame('4.680', (string) $log->total_weight_kg);
        $this->assertSame(0.44, $log->waste_kg, 'waste = wheel − packs');
        $this->assertSame(4, $wheel->fresh()->quantity_remaining);
        $this->assertSame(18, (int) $log->targetBatchItem->quantity_remaining);
    }

    public function test_the_cut_form_asks_for_the_wheel_weight(): void
    {
        $wheel = $this->wheels();

        $this->actingAs($this->office)->get(route('cheese-cutting.create', $wheel))
            ->assertOk()
            ->assertSee('name="wheel_weight_kg"', false)
            ->assertSee('name="total_weight_kg"', false);
    }

    // ------------------------------------- finding 2: conservation of mass

    public function test_pack_weight_cannot_exceed_the_wheel_weight(): void
    {
        $wheel = $this->wheels();

        // The original probe: a 5 kg wheel into 25 kg of packs.
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'vacuum_packs_created' => 18, 'total_weight_kg' => 25.000]))
            ->assertSessionHasErrors('total_weight_kg');

        // Even a gram over is refused; equal is allowed (a lossless cut is odd, not impossible).
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'total_weight_kg' => 5.001]))
            ->assertSessionHasErrors('total_weight_kg');
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'vacuum_packs_created' => 20, 'total_weight_kg' => 5.000]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CheeseCuttingLog::count());
    }

    public function test_pack_count_times_nominal_pack_weight_cannot_exceed_the_wheel_weight(): void
    {
        $wheel = $this->wheels();

        // 100 × 0.250 kg = 25 kg of nominal packs from a 5 kg wheel, even with a
        // "plausible" total posted alongside.
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'vacuum_packs_created' => 100, 'total_weight_kg' => 4.800]))
            ->assertSessionHasErrors('vacuum_packs_created');

        // 20 × 0.250 = 5.000 — exactly the wheel — is the ceiling.
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'vacuum_packs_created' => 21, 'total_weight_kg' => 4.800]))
            ->assertSessionHasErrors('vacuum_packs_created');
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'vacuum_packs_created' => 20, 'total_weight_kg' => 4.800]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_refused_over_yield_says_why_on_the_form(): void
    {
        $wheel = $this->wheels();

        $response = $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.000, 'total_weight_kg' => 25.000]));

        $this->followRedirects($response)->assertOk()
            ->assertSee('cannot weigh more than the wheel');
    }

    // ------------------------------------------- finding 3: cut_date bounds

    public function test_cut_date_cannot_precede_the_batch_production_date(): void
    {
        $wheel = $this->wheels(daysAgo: 30);
        $production = $wheel->batch->production_date;

        $this->cutPost($wheel, $this->cut(['cut_date' => '2020-01-01']))->assertSessionHasErrors('cut_date');
        $this->cutPost($wheel, $this->cut(['cut_date' => $production->copy()->subDay()->toDateString()]))->assertSessionHasErrors('cut_date');

        $this->cutPost($wheel, $this->cut(['cut_date' => $production->toDateString()]))->assertSessionHasNoErrors();
        $this->cutPost($wheel, $this->cut(['cut_date' => today()->toDateString()]))->assertSessionHasNoErrors();
        $this->cutPost($wheel, $this->cut(['cut_date' => today()->addDay()->toDateString()]))->assertSessionHasErrors('cut_date');

        $this->assertSame(2, CheeseCuttingLog::count());
    }

    // ------------------------------------------ trim loss across the batch

    public function test_the_batch_exposes_trim_loss_across_its_cuts(): void
    {
        $wheel = $this->wheels();
        $this->cutPost($wheel, $this->cut(['wheel_weight_kg' => 5.120, 'total_weight_kg' => 4.680]))->assertSessionHasNoErrors(); // 0.440
        $this->cutPost($wheel->fresh(), $this->cut(['wheel_weight_kg' => 4.900, 'total_weight_kg' => 4.550]))->assertSessionHasNoErrors(); // 0.350

        $batch = $wheel->batch->fresh();
        $this->assertCount(2, $batch->cuttingLogs, 'the batch sees the cuts made from its own wheels');
        $this->assertSame(0.79, $batch->trim_loss_kg);
        $this->assertSame(2, $batch->measured_cuts_count);
        $this->assertSame(0, $batch->unmeasured_cuts_count);
    }

    public function test_the_batch_page_shows_wheel_pack_and_waste_per_cut_and_the_batch_total(): void
    {
        $wheel = $this->wheels();
        $this->cutPost($wheel, $this->cut())->assertSessionHasNoErrors();

        $this->actingAs($this->office)->get(route('batches.show', $wheel->batch))
            ->assertOk()
            ->assertSee('Wheel')
            ->assertSee('5.120 kg')
            ->assertSee('4.680 kg')
            ->assertSee('0.440 kg')
            ->assertSee('Trim loss');
    }

    public function test_batch_cutting_logs_belong_to_the_batch_not_to_a_coincidental_item_id(): void
    {
        // Two batches; only the second is cut. Before the relation fix,
        // Batch::cuttingLogs() joined batches.id to source_batch_item_id.
        $untouched = $this->wheels();
        $cutWheel = $this->wheels();
        $this->cutPost($cutWheel, $this->cut())->assertSessionHasNoErrors();

        $this->assertCount(0, $untouched->batch->fresh()->cuttingLogs);
        $this->assertCount(1, $cutWheel->batch->fresh()->cuttingLogs);
    }

    // --------------------------------------------------- legacy (unweighed)

    public function test_a_legacy_cut_with_no_wheel_weight_is_shown_as_unmeasured_not_invented(): void
    {
        $wheel = $this->wheels();
        $packs = BatchItem::create([
            'batch_id' => $wheel->batch_id, 'product_variant_id' => $this->vacuum->id,
            'quantity_produced' => 6, 'quantity_remaining' => 6, 'unit_weight_kg' => 0.250,
        ]);
        // A pre-pass-2a row: packs weighed, wheel never was.
        CheeseCuttingLog::create([
            'source_batch_item_id' => $wheel->id, 'target_batch_item_id' => $packs->id,
            'cut_date' => today()->subDays(10)->toDateString(), 'vacuum_packs_created' => 6, 'total_weight_kg' => 1.600,
        ]);

        $log = CheeseCuttingLog::sole();
        $this->assertNull($log->wheel_weight_kg);
        $this->assertNull($log->waste_kg, 'no wheel weight ⇒ no waste figure, not a nominal one');

        $batch = $wheel->batch->fresh();
        $this->assertSame(0.0, $batch->trim_loss_kg);
        $this->assertSame(0, $batch->measured_cuts_count);
        $this->assertSame(1, $batch->unmeasured_cuts_count);

        $this->actingAs($this->office)->get(route('batches.show', $batch))
            ->assertOk()
            ->assertSee('not weighed');
    }
}
