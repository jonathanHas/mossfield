<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An open order whose delivery date has passed is flagged "overdue" wherever
 * the date prints — orders index, order page, allocation worklist (UX review A3).
 */
class DeliveryOverdueTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'name' => 'Corner Shop', 'email' => 'shop@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true,
        ]);
        $milk = Product::create(['name' => 'Test Milk', 'type' => 'milk', 'is_active' => true]);
        $this->variant = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'is_active' => true,
        ]);
    }

    private function order(string $status, $deliveryDate): Order
    {
        $order = Order::create([
            'customer_id' => $this->customer->id, 'order_date' => now()->subDays(3)->toDateString(),
            'delivery_date' => $deliveryDate, 'status' => $status, 'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $this->variant->id,
            'quantity_ordered' => 5, 'unit_price' => 2.50,
        ]);

        return $order;
    }

    public function test_is_delivery_overdue(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $this->assertFalse($this->order('confirmed', null)->isDeliveryOverdue());
        $this->assertFalse($this->order('confirmed', today()->toDateString())->isDeliveryOverdue());

        foreach (Order::OPEN_STATUSES as $status) {
            $this->assertTrue($this->order($status, $yesterday)->isDeliveryOverdue(), $status);
        }
        foreach (['dispatched', 'delivered', 'cancelled'] as $status) {
            $this->assertFalse($this->order($status, $yesterday)->isDeliveryOverdue(), $status);
        }
    }

    public function test_orders_index_flags_an_overdue_order(): void
    {
        $this->order('confirmed', now()->subDay()->toDateString());

        $this->actingAs(User::factory()->create())->get(route('orders.index'))
            ->assertOk()->assertSee('data-overdue', false);
    }

    public function test_orders_index_does_not_flag_a_future_order(): void
    {
        $this->order('confirmed', now()->addDay()->toDateString());

        $this->actingAs(User::factory()->create())->get(route('orders.index'))
            ->assertOk()->assertDontSee('data-overdue', false);
    }

    public function test_order_page_flags_an_overdue_order(): void
    {
        $late = $this->order('confirmed', now()->subDay()->toDateString());
        $future = $this->order('confirmed', now()->addDay()->toDateString());
        $office = User::factory()->create();

        $this->actingAs($office)->get(route('orders.show', $late))
            ->assertOk()->assertSee('data-overdue', false);
        $this->actingAs($office)->get(route('orders.show', $future))
            ->assertOk()->assertDontSee('data-overdue', false);
    }

    public function test_allocation_worklist_flags_an_overdue_order(): void
    {
        $this->order('confirmed', now()->subDay()->toDateString());

        $this->actingAs(User::factory()->create())->get(route('order-allocations.index'))
            ->assertOk()->assertSee('Delivery')->assertSee('data-overdue', false);
    }

    public function test_allocation_worklist_does_not_flag_a_future_order(): void
    {
        $this->order('confirmed', now()->addDay()->toDateString());

        $this->actingAs(User::factory()->create())->get(route('order-allocations.index'))
            ->assertOk()->assertDontSee('data-overdue', false);
    }
}
