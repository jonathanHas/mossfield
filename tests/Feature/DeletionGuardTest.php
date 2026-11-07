<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cycle 17: a product, variant or customer that history depends on is never
 * deleted. The model owns the rule (deletionBlocker()), the page greys Delete
 * and says why on hover, the controller refuses with the sentence as a flash.
 */
class DeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Product $milk;

    private ProductVariant $bottle;

    private ProductVariant $twoLitre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->milk = Product::create(['name' => 'Mossfield Guard Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = $this->variant($this->milk, '1L Bottle');
        $this->twoLitre = $this->variant($this->milk, '2L Bottle');
    }

    private function variant(Product $product, string $name): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id, 'name' => $name, 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.00, 'is_active' => true,
        ]);
    }

    private function batchWith(ProductVariant $variant, int $qty = 10): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $variant->product_id, 'production_date' => today()->subDays(2),
            'expiry_date' => today()->addDays(8), 'raw_milk_litres' => 100, 'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => $qty, 'quantity_remaining' => $qty, 'unit_weight_kg' => 1.0,
        ]);
    }

    private function customer(string $name = 'Guard Shop'): Customer
    {
        return Customer::create([
            'name' => $name, 'email' => 'guard@example.ie', 'address' => '1 Main St',
            'country' => 'Ireland', 'payment_terms' => 'net_30', 'is_active' => true,
        ]);
    }

    private function orderLine(ProductVariant $variant, ?Customer $customer = null): OrderItem
    {
        $order = Order::create([
            'customer_id' => ($customer ?? $this->customer())->id,
            'order_date' => today(), 'delivery_date' => today(),
            'status' => 'pending', 'payment_status' => 'pending',
        ]);

        return OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $variant->id,
            'quantity_ordered' => 2, 'unit_price' => 2.00,
        ]);
    }

    private function specialPrice(ProductVariant $variant, ?Customer $customer = null): CustomerSpecialPrice
    {
        return CustomerSpecialPrice::create([
            'customer_id' => ($customer ?? $this->customer())->id,
            'product_variant_id' => $variant->id, 'price' => 1.80,
        ]);
    }

    /** @return array{0: mixed, 1: int} the callback's result and how many queries it ran */
    private function countingQueries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $count];
    }

    // ---- ProductVariant::deletionBlocker() -------------------------------

    public function test_a_bare_variant_can_be_deleted(): void
    {
        $this->assertNull($this->bottle->deletionBlocker());
        $this->assertSame(0, $this->bottle->dependentRecordCount());
    }

    public function test_a_variant_with_a_batch_item_names_it(): void
    {
        $this->batchWith($this->bottle);

        $this->assertSame(
            "Guard Milk · 1L Bottle can't be deleted — 1 batch item depends on it, and deleting it would remove it from past batches and orders. Set the variant inactive instead.",
            $this->bottle->fresh()->deletionBlocker(),
        );
        $this->assertSame(1, $this->bottle->fresh()->dependentRecordCount());
    }

    public function test_a_variant_with_two_order_lines_counts_them(): void
    {
        $this->orderLine($this->bottle);
        $this->orderLine($this->bottle);
        $variant = $this->bottle->fresh();

        $this->assertStringContainsString('— 2 order lines depend on it, and deleting it would remove them from past batches and orders.', $variant->deletionBlocker());
        $this->assertSame(2, $variant->dependentRecordCount());
    }

    public function test_a_variant_held_only_by_a_special_price_is_not_called_history(): void
    {
        $this->specialPrice($this->bottle);

        $blocker = $this->bottle->fresh()->deletionBlocker();
        $this->assertStringContainsString('— 1 customer special price depends on it, and deleting it would remove it. Set the variant inactive instead.', $blocker);
        $this->assertStringNotContainsString('past batches', $blocker);
    }

    public function test_a_variant_names_every_reason_together(): void
    {
        $this->batchWith($this->bottle);
        $this->orderLine($this->bottle);
        $this->specialPrice($this->bottle);
        $variant = $this->bottle->fresh();

        $this->assertStringContainsString('— 1 batch item and 1 order line and 1 customer special price depend on it, and deleting it would remove them from past batches and orders.', $variant->deletionBlocker());
        $this->assertSame(3, $variant->dependentRecordCount());
    }

    public function test_variant_counts_ride_a_with_count_load(): void
    {
        $this->batchWith($this->bottle);
        $this->orderLine($this->bottle);
        $variant = ProductVariant::with('product')->withCount(['batchItems', 'orderItems', 'specialPrices'])->find($this->bottle->id);

        [$blocker, $queries] = $this->countingQueries(fn () => [$variant->deletionBlocker(), $variant->dependentRecordCount()]);

        $this->assertNotNull($blocker[0]);
        $this->assertSame(2, $blocker[1]);
        $this->assertSame(0, $queries);
    }

    // ---- Product::deletionBlocker() --------------------------------------

    public function test_a_product_with_bare_variants_can_be_deleted(): void
    {
        $this->assertNull($this->milk->deletionBlocker());
        $this->assertSame(0, $this->milk->dependentRecordCount());
    }

    public function test_a_product_with_a_batch_names_it_once(): void
    {
        $this->batchWith($this->bottle);
        $product = $this->milk->fresh();

        $this->assertSame(
            "Guard Milk can't be deleted — 1 batch depends on it, and deleting it would destroy that traceability. Set the product inactive instead.",
            $product->deletionBlocker(),
        );
        // The batch's item is inside the batch: one record, not two.
        $this->assertSame(1, $product->dependentRecordCount());
    }

    public function test_a_product_is_held_by_its_second_variants_order_line(): void
    {
        $this->orderLine($this->twoLitre);
        $product = $this->milk->fresh();

        $this->assertStringContainsString('— 1 order line depends on it', $product->deletionBlocker());
        $this->assertSame(1, $product->dependentRecordCount());
    }

    public function test_product_counts_ride_a_with_count_load(): void
    {
        $this->batchWith($this->bottle);
        $this->orderLine($this->twoLitre);
        $product = Product::withCount('batches')
            ->with(['variants' => fn ($q) => $q->withCount(['batchItems', 'orderItems', 'specialPrices'])])
            ->find($this->milk->id);

        [$result, $queries] = $this->countingQueries(fn () => [$product->deletionBlocker(), $product->dependentRecordCount()]);

        $this->assertStringContainsString('— 1 batch and 1 order line depend on it', $result[0]);
        $this->assertSame(2, $result[1]);
        $this->assertSame(0, $queries);
    }

    // ---- /products: the controllers refuse, the pages grey Delete ---------

    public function test_office_cannot_delete_a_product_with_a_batch(): void
    {
        $item = $this->batchWith($this->bottle);

        $this->actingAs(User::factory()->create())
            ->delete(route('products.destroy', $this->milk))
            ->assertRedirect(route('products.show', $this->milk))
            ->assertSessionHas('error', $this->milk->fresh()->deletionBlocker());

        $this->assertModelExists($this->milk);
        $this->assertModelExists($item->batch);
        $this->assertModelExists($item);
    }

    public function test_office_deletes_a_product_nothing_depends_on(): void
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('products.destroy', $this->milk))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($this->milk);
    }

    public function test_office_cannot_delete_a_variant_with_an_order_line(): void
    {
        $line = $this->orderLine($this->bottle);

        $this->actingAs(User::factory()->create())
            ->delete(route('products.variants.destroy', [$this->milk, $this->bottle]))
            ->assertRedirect(route('products.show', $this->milk))
            ->assertSessionHas('error', $this->bottle->fresh()->deletionBlocker());

        $this->assertModelExists($this->bottle);
        $this->assertModelExists($line);
    }

    public function test_a_bare_variant_is_office_only_to_delete(): void
    {
        $this->actingAs(User::factory()->factoryWorker()->create())
            ->delete(route('products.variants.destroy', [$this->milk, $this->bottle]))
            ->assertForbidden();
        $this->assertModelExists($this->bottle);

        $this->actingAs(User::factory()->create())
            ->delete(route('products.variants.destroy', [$this->milk, $this->bottle]))
            ->assertRedirect(route('products.show', $this->milk))
            ->assertSessionHas('success');
        $this->assertModelMissing($this->bottle);
    }

    public function test_a_variant_route_under_the_wrong_product_is_a_404(): void
    {
        $other = Product::create(['name' => 'Mossfield Other', 'type' => 'yoghurt', 'is_active' => true]);
        $tub = $this->variant($other, '500g Tub');

        $this->actingAs(User::factory()->create())
            ->delete(route('products.variants.destroy', [$this->milk, $tub]))
            ->assertNotFound();

        $this->assertModelExists($tub);
    }

    public function test_products_index_greys_delete_on_blocked_rows_only(): void
    {
        $this->batchWith($this->bottle);
        $probe = Product::create(['name' => 'Mossfield Probe', 'type' => 'yoghurt', 'is_active' => true]);
        $this->variant($probe, 'Probe Tub');

        $html = $this->actingAs(User::factory()->create())->get(route('products.index'))->assertOk()->getContent();

        // The product (1 batch) and its 1L variant (1 batch item); the 2L and the probe are free.
        $this->assertSame(2, substr_count($html, 'data-delete-blocked'));
        $this->assertStringContainsString("Can't delete — 1 record depends on it. ".e($this->milk->fresh()->deletionBlocker()), $html);
        $this->assertStringContainsString("Can't delete — 1 record depends on it. ".e($this->bottle->fresh()->deletionBlocker()), $html);
        $this->assertStringContainsString('action="'.route('products.destroy', $probe).'"', $html);
        $this->assertStringNotContainsString('action="'.route('products.destroy', $this->milk).'"', $html);
        $this->assertStringContainsString('action="'.route('products.variants.destroy', [$this->milk, $this->twoLitre]).'"', $html);
        $this->assertStringNotContainsString('action="'.route('products.variants.destroy', [$this->milk, $this->bottle]).'"', $html);
    }

    public function test_product_page_greys_delete_on_blocked_variants_only(): void
    {
        $this->orderLine($this->bottle);

        $html = $this->actingAs(User::factory()->create())->get(route('products.show', $this->milk))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-delete-blocked'));
        $this->assertStringContainsString("Can't delete — 1 record depends on it. ".e("Guard Milk · 1L Bottle can't be deleted — 1 order line depends on it"), $html);
        $this->assertStringContainsString('action="'.route('products.variants.destroy', [$this->milk, $this->twoLitre]).'"', $html);
        $this->assertStringNotContainsString('action="'.route('products.variants.destroy', [$this->milk, $this->bottle]).'"', $html);
    }

    public function test_products_index_costs_the_same_queries_with_more_blocked_rows(): void
    {
        $office = User::factory()->create();
        $this->batchWith($this->bottle);
        $this->orderLine($this->twoLitre);
        $queries = function () use ($office) {
            [, $count] = $this->countingQueries(fn () => $this->actingAs($office)->get(route('products.index'))->assertOk());

            return $count;
        };

        $before = $queries();
        foreach (range(1, 4) as $n) {
            $product = Product::create(['name' => "Mossfield Blocked {$n}", 'type' => 'cheese', 'maturation_days' => 60, 'is_active' => true]);
            $wheel = $this->variant($product, 'Whole Wheel');
            $this->batchWith($wheel);
            $this->orderLine($wheel);
        }
        $after = $queries();

        $this->assertSame($before, $after, "/products ran {$before} queries with one blocked product, {$after} with five");
        // Guard Milk, both its variants, and each new product + its wheel.
        $this->assertSame(11, substr_count($this->actingAs($office)->get(route('products.index'))->getContent(), 'data-delete-blocked'));
    }

    // ---- Customers -------------------------------------------------------

    private function payment(Customer $customer): Payment
    {
        return Payment::create([
            'customer_id' => $customer->id, 'amount' => 50, 'received_at' => today(), 'method' => 'cash',
        ]);
    }

    private function invoice(Customer $customer): void
    {
        DB::table('invoices')->insert([
            'number' => 'INV-GUARD-'.$customer->id, 'customer_id' => $customer->id,
            'issued_at' => today(), 'due_date' => today()->addDays(30), 'bill_to_name' => $customer->name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_customer_with_a_payment_and_no_orders_is_refused_not_a_500(): void
    {
        $shop = $this->customer('Payment Only Shop');
        $this->payment($shop);

        $this->actingAs(User::factory()->create())
            ->delete(route('customers.destroy', $shop))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('error', "Payment Only Shop can't be deleted — 1 payment depends on them. Set the customer inactive instead.");

        $this->assertModelExists($shop);
    }

    public function test_a_customer_with_an_invoice_is_refused(): void
    {
        $shop = $this->customer('Invoiced Shop');
        $this->invoice($shop);

        $this->actingAs(User::factory()->create())
            ->delete(route('customers.destroy', $shop))
            ->assertSessionHas('error', "Invoiced Shop can't be deleted — 1 invoice depends on them. Set the customer inactive instead.");

        $this->assertModelExists($shop);
    }

    public function test_a_customer_with_two_orders_counts_them(): void
    {
        $shop = $this->customer('Two Order Shop');
        $this->orderLine($this->bottle, $shop);
        $this->orderLine($this->bottle, $shop);

        $this->actingAs(User::factory()->create())
            ->delete(route('customers.destroy', ['customer' => $shop, 'search' => 'Two']))
            ->assertRedirect(route('customers.index', ['search' => 'Two']))
            ->assertSessionHas('error', "Two Order Shop can't be deleted — 2 orders depend on them. Set the customer inactive instead.");

        $this->assertModelExists($shop);
        $this->assertSame(2, $shop->fresh()->dependentRecordCount());
    }

    public function test_a_bare_customer_is_deleted_with_their_special_prices(): void
    {
        $shop = $this->customer('Bare Shop');
        $price = $this->specialPrice($this->bottle, $shop);
        $this->assertNull($shop->deletionBlocker());

        $this->actingAs(User::factory()->create())
            ->delete(route('customers.destroy', $shop))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($shop);
        $this->assertModelMissing($price);
    }

    public function test_customers_index_greys_delete_on_blocked_rows_only(): void
    {
        $blocked = $this->customer('Blocked Shop');
        $this->payment($blocked);
        $bare = $this->customer('Bare Shop');

        $html = $this->actingAs(User::factory()->create())->get(route('customers.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-delete-blocked'));
        $this->assertStringContainsString("Can't delete — 1 record depends on it. ".e($blocked->deletionBlocker()), $html);
        $this->assertStringContainsString('action="'.route('customers.destroy', $bare).'"', $html);
        $this->assertStringNotContainsString('action="'.route('customers.destroy', $blocked).'"', $html);
    }

    public function test_customers_index_costs_the_same_queries_with_more_blocked_rows(): void
    {
        $office = User::factory()->create();
        $this->payment($this->customer('Blocked 0'));
        $queries = function () use ($office) {
            [, $count] = $this->countingQueries(fn () => $this->actingAs($office)->get(route('customers.index'))->assertOk());

            return $count;
        };

        $before = $queries();
        foreach (range(1, 4) as $n) {
            $shop = $this->customer("Blocked {$n}");
            $this->payment($shop);
            $this->orderLine($this->bottle, $shop);
            $this->invoice($shop);
        }
        $after = $queries();

        $this->assertSame($before, $after, "/customers ran {$before} queries with one blocked customer, {$after} with five");
        $this->assertSame(5, substr_count($this->actingAs($office)->get(route('customers.index'))->getContent(), 'data-delete-blocked'));
    }
}
