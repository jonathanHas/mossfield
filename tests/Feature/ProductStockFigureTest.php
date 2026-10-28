<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The variant "Stock" figure on /products, /products/{product} and the
 * master-detail sibling list is the seventh free-stock surface (see
 * docs/planImp/TESTING_AND_FIXES.md, 2026-09-12 browser pass, finding 1). It must agree
 * with the other six: only active, non-expired, ready-to-sell batches count,
 * and the units still owed to orders and the maturing hold are subtracted —
 * exactly what BatchItem::available_quantity says for each allocatable item.
 */
class ProductStockFigureTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Product $milk;

    private ProductVariant $bottle;

    private Product $cheese;

    private ProductVariant $wheel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->admin()->create();

        $this->milk = Product::create(['name' => 'Figure Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $this->milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'base_price' => 2.00,
            'is_active' => true,
        ]);

        $this->cheese = Product::create([
            'name' => 'Figure Cheese',
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

        // Cheese batches get a ready_date from maturation_days; backdate it so
        // the stock is sellable today unless the test says otherwise.
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
            ['email' => 'figure@example.ie'],
            [
                'name' => 'Figure Shop',
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

    /** The figure as each of the three product surfaces renders it. */
    private function assertFigureReads(int $expected, ProductVariant $variant, string $message = ''): void
    {
        $product = $variant->product;
        $this->actingAs($this->office);

        $indexVariant = $this->get(route('products.index'))->assertOk()
            ->viewData('products')->flatten(1)->firstWhere('id', $product->id)
            ->variants->firstWhere('id', $variant->id);
        $this->assertSame($expected, $indexVariant->total_stock, "/products: $message");

        $showVariant = $this->get(route('products.show', $product))->assertOk()
            ->viewData('product')->variants->firstWhere('id', $variant->id);
        $this->assertSame($expected, $showVariant->total_stock, "/products/{product}: $message");

        // The sibling list on another product's page carries the same figure.
        $other = $product->is($this->milk) ? $this->cheese : $this->milk;
        $listVariant = $this->get(route('products.show', $other))->assertOk()
            ->viewData('productList')->firstWhere('id', $product->id)
            ->variants->firstWhere('id', $variant->id);
        $this->assertSame($expected, $listVariant->total_stock, "sibling list: $message");

        // And the accessor's own fallback, for anything that reads a variant cold.
        $this->assertSame($expected, $variant->fresh()->total_stock, "accessor fallback: $message");
    }

    // ---------------------------------------------------------------- cases

    public function test_expired_batches_do_not_count_towards_the_product_stock_figure(): void
    {
        $this->stock(10);
        $this->stock(100, null, ['expiry_date' => today()->subDay()->toDateString()]);

        $this->assertFigureReads(10, $this->bottle, 'one live batch of 10 and one expired batch of 100');
    }

    public function test_a_batch_expiring_today_is_already_expired(): void
    {
        $this->stock(10);
        $this->stock(100, null, ['expiry_date' => today()->toDateString()]);

        // Strict `>` — the same rule BatchItem::allocatable() and Batch::isExpired() apply.
        $this->assertFigureReads(10, $this->bottle, 'expiry today is excluded, like the allocator');
    }

    public function test_batches_no_longer_active_do_not_count(): void
    {
        $this->stock(10);
        $this->stock(100, null, ['status' => 'expired']);

        $this->assertFigureReads(10, $this->bottle, 'only status=active contributes');
    }

    public function test_wheels_held_for_maturing_are_not_free_stock(): void
    {
        $batchItem = $this->stock(10, $this->wheel);
        $batchItem->forceFill(['quantity_maturing' => 2])->save();

        $this->assertFigureReads(8, $this->wheel, '10 wheels with 2 on maturing hold');
    }

    public function test_cheese_that_has_not_reached_its_ready_date_is_not_free_stock(): void
    {
        $this->stock(10, $this->wheel);
        $this->stock(100, $this->wheel, ['ready_date' => today()->addDays(30)->toDateString()]);

        $this->assertFigureReads(10, $this->wheel, 'a still-maturing cheese batch is not sellable');
    }

    public function test_units_owed_to_orders_are_subtracted_but_picked_units_are_not_subtracted_twice(): void
    {
        $batchItem = $this->stock(20);

        // Reserve 10, pick 4: 16 physically remain, 6 still owed ⇒ 10 free.
        $this->reserve($this->bottle, $batchItem, 10, picked: 4);

        $this->assertSame(16, $batchItem->fresh()->quantity_remaining);
        $this->assertFigureReads(10, $this->bottle, 'reservation is allocated − fulfilled, never the whole allocation');
    }

    public function test_the_figure_equals_the_sum_of_available_quantity_over_allocatable_items(): void
    {
        // A mixed bag: live, expired, held, reserved and partly picked.
        $live = $this->stock(30, $this->wheel);
        $this->stock(50, $this->wheel, ['expiry_date' => today()->subDay()->toDateString()]);
        $held = $this->stock(10, $this->wheel);
        $held->forceFill(['quantity_maturing' => 7])->save();
        $this->reserve($this->wheel, $live, 8, picked: 3);

        $expected = BatchItem::allocatable()
            ->where('product_variant_id', $this->wheel->id)
            ->with('batch.product')
            ->get()
            ->filter(fn (BatchItem $i) => $i->isReadyToSell() && ! $i->batch->isExpired())
            ->sum(fn (BatchItem $i) => $i->available_quantity);

        // Live: 30 − 3 picked = 27 remain, 5 still owed ⇒ 22. Held: 10 − 7 ⇒ 3.
        $this->assertSame(22 + 3, $expected, 'sanity check on the reference figure');
        $this->assertFigureReads($expected, $this->wheel, 'the product figure is the bulk form of available_quantity');
    }

    public function test_the_factory_gets_no_edit_link_on_the_products_index(): void
    {
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('products.index'))
            ->assertOk()
            ->assertDontSee(route('products.edit', $this->milk), false);

        $this->actingAs(User::factory()->create())->get(route('products.index'))
            ->assertOk()
            ->assertSee(route('products.edit', $this->milk), false);
    }
}
