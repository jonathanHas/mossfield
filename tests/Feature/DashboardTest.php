<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard batch figures: "ready now" counts only sellable batches, and
 * expired stock (still active, past expiry, units left) gets its own tile
 * (E2E findings F-01, F-02).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private int $day = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $milk = Product::create(['name' => 'Test Milk', 'type' => 'milk', 'is_active' => true]);
        $this->variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'is_active' => true,
        ]);
    }

    private function batch($expiry, int $remaining): Batch
    {
        $batch = Batch::create([
            'product_id' => $this->variant->product_id,
            'production_date' => now()->subDays($this->day--),
            'expiry_date' => $expiry,
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->variant->id,
            'quantity_produced' => 50, 'quantity_remaining' => $remaining, 'unit_weight_kg' => 1.0,
        ]);

        return $batch;
    }

    public function test_ready_now_counts_only_sellable_batches_and_expired_stock_has_a_tile(): void
    {
        $this->batch(now()->addDays(5), 50);   // sellable
        $this->batch(now()->subDay(), 12);     // expired, with stock
        $this->batch(now()->subDays(2), 0);    // expired, empty

        $office = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $office->assertViewHas('readyNowCount', 1);
        $office->assertViewHas('expiredCount', 1);
        $office->assertViewHas('expiredUnits', 12);
        $office->assertSee('1 ready now');
        $office->assertSee('Expired stock');
        $office->assertSee('12 units · write off'); // cycle 7: write-offs exist now
        $office->assertSee(route('batches.index', ['status' => 'expired']), false);

        $this->actingAs(User::factory()->factoryWorker()->create())->get('/dashboard')
            ->assertOk()
            ->assertSee('Expired stock');
    }

    public function test_the_tile_is_absent_when_nothing_is_expired(): void
    {
        $this->batch(now()->addDays(5), 50);
        $this->batch(now()->subDays(2), 0);

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertViewHas('expiredCount', 0)
            ->assertDontSee('Expired stock');
    }

    public function test_the_expired_filter_on_batches_includes_active_batches_past_expiry(): void
    {
        $fresh = $this->batch(now()->addDays(5), 50);
        $stale = $this->batch(now()->subDay(), 12);

        $this->actingAs(User::factory()->create())->get(route('batches.index', ['status' => 'expired']))
            ->assertOk()
            ->assertSee($stale->batch_code)
            ->assertDontSee($fresh->batch_code);
    }
}
