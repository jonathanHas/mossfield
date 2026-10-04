<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding 11 (docs/planImp/TESTING_AND_FIXES.md, 2026-09-13): validate() drops keys that
 * were never submitted, so a controller reading a nullable key unguarded 500s
 * for any client that omits the field. The browser form always posts every
 * input (an empty textarea posts ""), so only a non-browser client sees it.
 */
class OrderStoreOptionalFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_can_be_created_with_only_the_required_fields(): void
    {
        $customer = Customer::create([
            'name' => 'Dublin Farmers Market', 'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar', 'city' => 'Dublin', 'postal_code' => 'D02 X285', 'is_active' => true,
        ]);
        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle', 'base_price' => 1.50, 'is_active' => true,
        ]);

        // No delivery_date, delivery_address, notes or customer_reference keys at all.
        $response = $this->actingAs(User::factory()->create())->post(route('orders.store'), [
            'customer_id' => $customer->id,
            'order_date' => today()->toDateString(),
            'items' => [['product_variant_id' => $bottle->id, 'quantity' => 3]],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::sole();
        $this->assertNull($order->delivery_date);
        $this->assertNull($order->delivery_address);
        $this->assertNull($order->notes);
        $this->assertNull($order->customer_reference);
        $this->assertSame(3, $order->orderItems()->sole()->quantity_ordered);
    }

    public function test_the_same_variant_twice_becomes_one_line_with_the_summed_quantity(): void
    {
        $customer = Customer::create([
            'name' => 'Dublin Farmers Market', 'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar', 'city' => 'Dublin', 'postal_code' => 'D02 X285', 'is_active' => true,
        ]);
        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle', 'base_price' => 1.50, 'is_active' => true,
        ]);
        $big = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '2L Bottle', 'size' => '2L', 'unit' => 'bottle', 'base_price' => 2.50, 'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create())->post(route('orders.store'), [
            'customer_id' => $customer->id,
            'order_date' => today()->toDateString(),
            'items' => [
                ['product_variant_id' => $bottle->id, 'quantity' => 3],
                ['product_variant_id' => $big->id, 'quantity' => 1],
                ['product_variant_id' => $bottle->id, 'quantity' => 4],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::sole();
        $this->assertCount(2, $order->orderItems);
        $this->assertSame(7, $order->orderItems()->where('product_variant_id', $bottle->id)->sole()->quantity_ordered);
        $this->assertSame('13.00', (string) $order->total_amount); // 7 × 1.50 + 2.50
    }
}
