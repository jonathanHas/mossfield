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
        $response->assertSee('No active milk batches');
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
}
