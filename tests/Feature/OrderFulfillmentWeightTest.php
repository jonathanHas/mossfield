<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Weight capture at fulfilment for variable-weight items. Two entry styles:
 *  - per-unit (cheese wheels): a weight per unit, summed into actual_weight_kg.
 *  - bulk (vacuum packs, is_bulk_weighed): one total weight for the line.
 * Both post a single actual_weight_kg; when the variant is priced by weight the
 * fulfilled total follows the recorded kg.
 */
class OrderFulfillmentWeightTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::create(['name' => 'Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
        $this->customer = Customer::create([
            'name' => 'Kerry Organic', 'email' => 'k@example.ie',
            'address' => 'Main St', 'city' => 'Tralee', 'postal_code' => 'V92', 'is_active' => true,
        ]);
    }

    private function variant(array $attrs): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $this->product->id,
            'name' => 'V'.uniqid(),
            'size' => 'x',
            'unit' => 'x',
            'weight_kg' => 1.0,
            'base_price' => 10.00,
            'is_active' => true,
        ], $attrs));
    }

    private function batchItem(ProductVariant $variant, int $remaining = 10): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->product->id,
            'production_date' => now()->subDay()->toDateString(),
            'ready_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => $remaining,
            'quantity_remaining' => $remaining,
            'unit_weight_kg' => $variant->weight_kg,
        ]);
    }

    private function orderWithAllocatedItem(ProductVariant $variant, int $qty, string $status = 'preparing'): array
    {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $item = $order->orderItems()->create([
            'product_variant_id' => $variant->id,
            'quantity_ordered' => $qty,
            'unit_price' => $variant->base_price,
        ]);
        $alloc = $item->allocateFromBatchItem($this->batchItem($variant), $qty);

        return [$order, $item, $alloc];
    }

    public function test_bulk_weighed_fulfilment_records_total_and_prices_by_weight(): void
    {
        $office = User::factory()->create();
        $pack = $this->variant([
            'name' => 'Vacuum Pack', 'unit' => 'pack', 'weight_kg' => 0.250,
            'base_price' => 10.00, // €/kg
            'is_variable_weight' => true, 'is_bulk_weighed' => true, 'is_priced_by_weight' => true,
        ]);
        [$order, $item, $alloc] = $this->orderWithAllocatedItem($pack, 4);

        $this->actingAs($office)
            ->post(route('order-allocations.fulfill', $alloc), [
                'quantity' => 4,
                'actual_weight_kg' => 2.000,
            ])->assertRedirect(route('orders.show', $order));

        $this->assertSame('2.000', (string) $alloc->fresh()->actual_weight_kg);
        $this->assertSame('2.000', (string) $item->fresh()->weight_fulfilled_kg);
        $this->assertSame('20.00', (string) $item->fresh()->fulfilled_total); // 2.000kg × €10/kg
        $this->assertSame(6, (int) $alloc->fresh()->batchItem->quantity_remaining);
        // Order total reflects the actual fulfilled weight, not the nominal estimate
        // (estimate would be 4 × 0.25kg × €10 = €10.00).
        $this->assertSame('20.00', (string) $order->fresh()->total_amount);
    }

    public function test_dispatched_order_shows_actual_weight_value_not_estimate(): void
    {
        $office = User::factory()->create();
        $wheel = $this->variant([
            'name' => 'Whole Wheel', 'unit' => 'wheel', 'weight_kg' => 2.500,
            'base_price' => 18.00,
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
        ]);
        [$order, $item, $alloc] = $this->orderWithAllocatedItem($wheel, 2);
        $item->fulfillAllocation($alloc, 2, 10.300); // 10.3kg × €18 = €185.40
        $order->calculateTotals();
        $order->update(['status' => 'dispatched']); // renders the read-only items table

        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('185.40')        // actual fulfilled value
            ->assertSee('10.300 kg')     // weight hint
            ->assertDontSee('90.00');    // the nominal estimate (2 × 2.5kg × €18)
    }

    public function test_per_unit_variable_weight_still_prices_by_recorded_weight(): void
    {
        $office = User::factory()->create();
        $wheel = $this->variant([
            'name' => 'Whole Wheel', 'unit' => 'wheel', 'weight_kg' => 2.500,
            'base_price' => 14.00, // €/kg
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
        ]);
        [$order, $item, $alloc] = $this->orderWithAllocatedItem($wheel, 2);

        // The per-unit form sums #1+#2 into actual_weight_kg before posting.
        $this->actingAs($office)
            ->post(route('order-allocations.fulfill', $alloc), [
                'quantity' => 2,
                'actual_weight_kg' => 5.200,
            ])->assertRedirect(route('orders.show', $order));

        $this->assertSame('5.200', (string) $item->fresh()->weight_fulfilled_kg);
        $this->assertSame('72.80', (string) $item->fresh()->fulfilled_total); // 5.2 × €14
    }

    public function test_bulk_line_renders_single_total_input_not_per_unit(): void
    {
        $office = User::factory()->create();
        $pack = $this->variant([
            'name' => 'Vacuum Pack', 'unit' => 'pack',
            'is_variable_weight' => true, 'is_bulk_weighed' => true, 'is_priced_by_weight' => true,
        ]);
        [$order] = $this->orderWithAllocatedItem($pack, 3);

        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('name="actual_weight_kg"', false)
            ->assertDontSee('name="weights[]"', false);
    }

    public function test_per_unit_line_renders_per_unit_inputs(): void
    {
        $office = User::factory()->create();
        $wheel = $this->variant([
            'name' => 'Whole Wheel', 'unit' => 'wheel',
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
        ]);
        [$order] = $this->orderWithAllocatedItem($wheel, 2);

        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('name="weights[]"', false);
    }

    public function test_variant_form_persists_is_bulk_weighed(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('products.variants.store', $this->product), [
                'name' => 'Bulk Pack',
                'size' => 'pack',
                'unit' => 'pack',
                'weight_kg' => 0.250,
                'base_price' => 18.00,
                'is_variable_weight' => '1',
                'is_priced_by_weight' => '1',
                'is_bulk_weighed' => '1',
                'is_active' => '1',
            ])->assertRedirect(route('products.show', $this->product));

        $variant = ProductVariant::where('name', 'Bulk Pack')->firstOrFail();
        $this->assertTrue($variant->is_bulk_weighed);
        $this->assertTrue($variant->is_variable_weight);
    }

    public function test_seeder_flags_cheese_variants_for_weighing(): void
    {
        $this->seed(\Database\Seeders\ProductSeeder::class);

        $wheel = ProductVariant::where('name', 'Whole Wheel')->firstOrFail();
        $pack = ProductVariant::where('name', 'Vacuum Pack')->firstOrFail();

        $this->assertTrue($wheel->is_variable_weight);
        $this->assertFalse($wheel->is_bulk_weighed);   // wheels weigh per-unit
        $this->assertTrue($pack->is_variable_weight);
        $this->assertTrue($pack->is_bulk_weighed);      // packs weigh in bulk
    }
}
