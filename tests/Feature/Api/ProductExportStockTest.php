<?php

namespace Tests\Feature\Api;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `stock_available` on GET /api/products is what the mossorders portal shows
 * customers. It is the eighth free-stock surface (docs/planImp/TESTING_AND_FIXES.md,
 * 2026-09-12 browser pass, finding 10) and must agree with /products by
 * construction: same query, same exclusions — expired/closed/unready batches,
 * units still owed to orders, and the maturing hold all contribute nothing.
 */
class ProductExportStockTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'export-stock-test-token';

    private Product $milk;

    private ProductVariant $bottle;

    private Product $cheese;

    private ProductVariant $wheel;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.office.api_token', self::TOKEN);
        Config::set('services.office.allowed_ips', '');

        $this->milk = Product::create(['name' => 'Export Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $this->milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'base_price' => 2.00,
            'is_active' => true,
        ]);

        $this->cheese = Product::create([
            'name' => 'Export Cheese',
            'type' => 'cheese',
            'maturation_days' => 30,
            'is_active' => true,
        ]);
        $this->wheel = ProductVariant::create([
            'product_id' => $this->cheese->id,
            'name' => 'Whole Wheel',
            'size' => '4kg',
            'unit' => 'wheel',
            'weight_kg' => 4.000,
            'base_price' => 12.00,
            'is_variable_weight' => true,
            'is_priced_by_weight' => true,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- fixtures

    private function stock(int $quantity, ?ProductVariant $variant = null, array $batchOverrides = []): BatchItem
    {
        $variant ??= $this->bottle;

        $batch = Batch::create([
            'product_id' => $variant->product_id,
            'production_date' => today()->subDays(2)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        if ($batch->ready_date && $batch->ready_date->isFuture()) {
            $batch->forceFill(['ready_date' => today()->subDay()->toDateString()]);
        }
        $batch->forceFill($batchOverrides)->save();

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_weight_kg' => $variant->weight_kg,
        ]);
    }

    private function reserve(ProductVariant $variant, BatchItem $batchItem, int $quantity, int $picked = 0): void
    {
        $shop = Customer::firstOrCreate(
            ['email' => 'export@example.ie'],
            [
                'name' => 'Export Shop',
                'address' => '1 Main St',
                'city' => 'Birr',
                'postal_code' => 'R42 AB12',
                'country' => 'Ireland',
                'payment_terms' => 'net_30',
                'is_active' => true,
            ],
        );

        $order = Order::create([
            'customer_id' => $shop->id,
            'order_date' => today()->toDateString(),
            'delivery_date' => today()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => $quantity,
            'unit_price' => $variant->base_price,
        ]);

        $allocation = $item->allocateFromBatchItem($batchItem, $quantity);

        if ($picked > 0) {
            $item->fulfillAllocation($allocation, $picked, $variant->is_variable_weight ? $picked * 4.0 : null);
        }
    }

    private function exportedStock(ProductVariant $variant): int
    {
        $response = $this->getJson('/api/products', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('office_variant_id', $variant->id);
        $this->assertNotNull($row, 'variant is present in the export');

        return (int) $row['stock_available'];
    }

    // ---------------------------------------------------------------- cases

    public function test_an_expired_but_still_active_batch_contributes_nothing(): void
    {
        $this->stock(10);
        // Expired weeks ago but never closed — exactly how batch 16 sat in prod.
        $this->stock(100, null, ['expiry_date' => today()->subWeeks(10)->toDateString()]);

        $this->assertSame(10, $this->exportedStock($this->bottle));
    }

    public function test_a_batch_expiring_today_is_already_expired(): void
    {
        $this->stock(10);
        $this->stock(100, null, ['expiry_date' => today()->toDateString()]);

        $this->assertSame(10, $this->exportedStock($this->bottle));
    }

    public function test_a_reserved_unit_contributes_nothing(): void
    {
        $batchItem = $this->stock(20);
        $this->reserve($this->bottle, $batchItem, 10, picked: 4);

        // 16 physically remain, 6 still owed ⇒ 10 sellable to anyone else.
        $this->assertSame(10, $this->exportedStock($this->bottle));
    }

    public function test_a_held_wheel_contributes_nothing(): void
    {
        $this->stock(10, $this->wheel)->forceFill(['quantity_maturing' => 3])->save();

        $this->assertSame(7, $this->exportedStock($this->wheel));
    }

    public function test_cheese_before_its_ready_date_still_contributes_nothing(): void
    {
        $this->stock(10, $this->wheel);
        $this->stock(100, $this->wheel, ['ready_date' => today()->addDays(30)->toDateString()]);

        $this->assertSame(10, $this->exportedStock($this->wheel));
    }

    public function test_the_export_equals_the_products_figure_for_the_same_variant(): void
    {
        $live = $this->stock(30, $this->wheel);
        $this->stock(50, $this->wheel, ['expiry_date' => today()->subDay()->toDateString()]);
        $this->stock(10, $this->wheel)->forceFill(['quantity_maturing' => 7])->save();
        $this->reserve($this->wheel, $live, 8, picked: 3);

        $productsFigure = $this->wheel->fresh()->total_stock;

        $this->assertSame(25, $productsFigure, 'sanity: 27 remain − 5 owed, plus 3 un-held');
        $this->assertSame($productsFigure, $this->exportedStock($this->wheel));
    }

    public function test_stock_is_computed_in_one_query_for_the_whole_export(): void
    {
        foreach (range(1, 5) as $i) {
            $variant = ProductVariant::create([
                'product_id' => $this->milk->id,
                'name' => "Variant $i",
                'size' => "{$i}L",
                'unit' => 'bottle',
                'base_price' => 1.00,
                'is_active' => true,
            ]);
            $this->stock(5, $variant);
        }

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/api/products', ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        // Variants + products + one batched free-stock query (its batch/product
        // eager loads and the reservation subselect ride inside it) — never a
        // stock query per variant.
        $this->assertLessThanOrEqual(6, $queries, "expected a fixed number of queries, ran $queries");
    }
}
