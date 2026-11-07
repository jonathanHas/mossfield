<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One rule, three screens: money on the shared /stock and /products screens
 * is behind the see-financials gate (OPEN_QUESTIONS #6). The floor keeps every
 * count, and the €/kg tag (no figure) stays.
 */
class FloorPricesTest extends TestCase
{
    use RefreshDatabase;

    private Product $milk;

    private Product $cheese;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milk = Product::create(['name' => 'Floor Test Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $this->milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'case_size' => 16, 'is_active' => true,
        ]);
        $milkBatch = Batch::create([
            'product_id' => $this->milk->id, 'production_date' => now()->subDays(2),
            'expiry_date' => now()->addDays(8), 'raw_milk_litres' => 100, 'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $milkBatch->id, 'product_variant_id' => $bottle->id,
            'quantity_produced' => 40, 'quantity_remaining' => 40, 'unit_weight_kg' => 1.0,
        ]);

        $this->cheese = Product::create([
            'name' => 'Floor Test Cheese', 'type' => 'cheese', 'maturation_days' => 60, 'is_active' => true,
        ]);
        $wheel = ProductVariant::create([
            'product_id' => $this->cheese->id, 'name' => 'Whole Wheel', 'size' => '4kg', 'unit' => 'wheel',
            'weight_kg' => 4.0, 'base_price' => 12.50, 'is_variable_weight' => true,
            'is_priced_by_weight' => true, 'is_active' => true,
        ]);
        $cheeseBatch = Batch::create([
            'product_id' => $this->cheese->id, 'production_date' => now()->subDays(90),
            'raw_milk_litres' => 100, 'status' => 'active',
        ]);
        BatchItem::create([
            'batch_id' => $cheeseBatch->id, 'product_variant_id' => $wheel->id,
            'quantity_produced' => 7, 'quantity_remaining' => 7, 'unit_weight_kg' => 4.0,
        ]);
    }

    private function assertNoEuroFigure(TestResponse $response): void
    {
        $this->assertSame(0, preg_match('/€\s*\d/u', $response->getContent()), 'no € followed by a digit');
    }

    public function test_the_floor_does_not_see_the_stock_value(): void
    {
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Stock overview')
            ->assertDontSee('€')
            ->assertDontSee('Total stock value');

        $this->actingAs(User::factory()->create())->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Total stock value')
            ->assertSee('€');
    }

    public function test_the_floor_does_not_see_the_price_column_on_the_products_index(): void
    {
        $floor = $this->actingAs(User::factory()->factoryWorker()->create())->get(route('products.index'))
            ->assertOk()
            ->assertDontSee('>Price<', false)
            ->assertDontSee('€2.50')
            ->assertDontSee('€12.50/kg')
            ->assertSee('€/kg')
            ->assertSee('>Stock<', false)
            ->assertSee('>40<', false)
            ->assertSee('>7<', false);
        $this->assertNoEuroFigure($floor);

        $this->actingAs(User::factory()->create())->get(route('products.index'))
            ->assertOk()
            ->assertSee('>Price<', false)
            ->assertSee('€2.50')
            ->assertSee('€12.50/kg');
    }

    public function test_the_floor_does_not_see_the_price_column_on_a_product_page(): void
    {
        $floor = $this->actingAs(User::factory()->factoryWorker()->create())->get(route('products.show', $this->cheese))
            ->assertOk()
            ->assertDontSee('>Price<', false)
            ->assertDontSee('€12.50/kg')
            ->assertSee('€/kg')
            ->assertSee('>Stock<', false)
            ->assertSee('>7<', false);
        $this->assertNoEuroFigure($floor);

        $this->actingAs(User::factory()->create())->get(route('products.show', $this->cheese))
            ->assertOk()
            ->assertSee('>Price<', false)
            ->assertSee('€12.50/kg');
    }
}
