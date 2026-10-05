<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_overview_renders_for_seeded_data(): void
    {
        $milk = Product::create([
            'name' => 'Test Organic Milk',
            'type' => 'milk',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.0,
            'base_price' => 2.50,
            'case_size' => 16,
            'is_active' => true,
        ]);
        $batch = Batch::create([
            'product_id' => $milk->id,
            'production_date' => now()->subDays(2),
            'expiry_date' => now()->addDays(10),
            'raw_milk_litres' => 1500,
            'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => 1500,
            'quantity_remaining' => 1500,
            'unit_weight_kg' => 1.0,
        ]);

        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get('/stock');

        $response->assertOk();
        $response->assertSee('Stock overview');
        $response->assertSee('1L Bottle');
        $response->assertSee('stock-case-grid', escape: false);
    }

    public function test_stock_overview_renders_empty_state(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get('/stock');

        $response->assertOk();
        $response->assertSee('Stock overview');
        $response->assertSee('No sellable milk.');
        $response->assertDontSee('data-expired-note', false);
    }

    public function test_a_batch_expiring_today_is_excluded_like_the_allocator_excludes_it(): void
    {
        $milk = Product::create([
            'name' => 'Test Organic Milk',
            'type' => 'milk',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.0,
            'base_price' => 2.50,
            'case_size' => 16,
            'is_active' => true,
        ]);

        // Same stock, three expiry dates either side of today; distinct
        // production dates keep the batch codes apart.
        $expiries = [
            'yesterday' => [now()->subDay(), 100],
            'today' => [now()->startOfDay(), 200],
            'tomorrow' => [now()->addDay(), 300],
        ];
        $offset = 3;
        foreach ($expiries as [$expiry, $units]) {
            $batch = Batch::create([
                'product_id' => $milk->id,
                'production_date' => now()->subDays($offset++),
                'expiry_date' => $expiry,
                'raw_milk_litres' => 500,
                'status' => 'active',
            ]);
            BatchItem::create([
                'batch_id' => $batch->id,
                'product_variant_id' => $variant->id,
                'quantity_produced' => $units,
                'quantity_remaining' => $units,
                'unit_weight_kg' => 1.0,
            ]);
        }

        $card = app(StockOverviewService::class)->build()['milk'];

        $this->assertCount(1, $card['active_batches']);
        $this->assertCount(1, $card['variants']);
        $this->assertSame(300, $card['variants'][0]['segments']['available']);

        // The allocator agrees by construction: only the tomorrow batch is a candidate.
        $this->assertSame(1, BatchItem::allocatable()->count());
        $this->assertSame(300, (int) BatchItem::allocatable()->sum('quantity_remaining'));
    }

    public function test_an_expired_batch_with_stock_is_named_on_the_card_not_counted_as_sellable(): void
    {
        $milk = Product::create(['name' => 'Test Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'case_size' => 16, 'is_active' => true,
        ]);
        foreach ([[now()->subDays(2), 40, 10], [now()->addDays(5), 60, 3]] as [$expiry, $units, $ago]) {
            $batch = Batch::create([
                'product_id' => $milk->id, 'production_date' => now()->subDays($ago),
                'expiry_date' => $expiry, 'raw_milk_litres' => 100, 'status' => 'active',
            ]);
            BatchItem::create([
                'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
                'quantity_produced' => $units, 'quantity_remaining' => $units, 'unit_weight_kg' => 1.0,
            ]);
        }

        $card = app(StockOverviewService::class)->build()['milk'];
        $this->assertCount(1, $card['active_batches']);
        $this->assertSame(['batches' => 1, 'units' => 40], $card['expired']);

        $this->actingAs(User::factory()->admin()->create())->get('/stock')
            ->assertOk()
            ->assertSee('1 expired batch')
            ->assertSee('still holds 40 units', false)
            ->assertSee('status=expired', false);
    }

    public function test_with_only_expired_stock_the_empty_card_says_where_it_went(): void
    {
        $milk = Product::create(['name' => 'Test Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'is_active' => true,
        ]);
        $batch = Batch::create([
            'product_id' => $milk->id, 'production_date' => now()->subDays(10),
            'expiry_date' => now()->subDay(), 'raw_milk_litres' => 100, 'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => 30, 'quantity_remaining' => 30, 'unit_weight_kg' => 1.0,
        ]);

        $this->actingAs(User::factory()->admin()->create())->get('/stock')
            ->assertOk()
            ->assertSeeInOrder(['No sellable milk.', '1 expired batch', '30 units']);
    }

    public function test_less_than_a_case_reads_loose_and_full_cases_are_counted_down(): void
    {
        $milk = Product::create(['name' => 'Test Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'case_size' => 16, 'is_active' => true,
        ]);
        foreach ([[12, 3], [40, 4]] as [$units, $ago]) {
            $batch = Batch::create([
                'product_id' => $milk->id, 'production_date' => now()->subDays($ago),
                'expiry_date' => now()->addDays(5), 'raw_milk_litres' => 100, 'status' => 'active',
            ]);
            BatchItem::create([
                'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
                'quantity_produced' => $units, 'quantity_remaining' => $units, 'unit_weight_kg' => 1.0,
            ]);
        }

        $this->actingAs(User::factory()->admin()->create())->get('/stock')
            ->assertOk()
            ->assertSee('12 loose — less than a case of 16')
            ->assertSee('2 cases × 16 + 8 loose')
            ->assertDontSee('1 cases × 16');
    }

    public function test_a_written_off_bottle_is_its_own_segment_not_sold(): void
    {
        $milk = Product::create(['name' => 'Test Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'case_size' => 16, 'is_active' => true,
        ]);
        $batch = Batch::create([
            'product_id' => $milk->id, 'production_date' => now()->subDays(2),
            'expiry_date' => now()->addDays(5), 'raw_milk_litres' => 100, 'status' => 'active',
        ]);
        $item = BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => 32, 'quantity_remaining' => 28, 'unit_weight_kg' => 1.0, // 4 sold
        ]);
        $user = User::factory()->admin()->create();
        app(\App\Services\StockWriteOffService::class)->record(
            $item, \App\Models\WriteOffReason::where('code', 'damaged')->firstOrFail(), 3, null, null, today(), $user,
        );

        $segments = app(StockOverviewService::class)->build()['milk']['variants'][0]['segments'];
        $this->assertSame(25, $segments['available']);
        $this->assertSame(3, $segments['written_off']);
        $this->assertSame(4, $segments['sold'], 'written-off units are not sold');

        $this->actingAs($user)->get('/stock')
            ->assertOk()
            ->assertSee('written off')
            ->assertSee('var(--state-written-off)', false);
    }
}
