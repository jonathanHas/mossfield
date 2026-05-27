<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
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
}
