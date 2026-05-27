<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market',
            'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar',
            'city' => 'Dublin',
            'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);
    }

    private function makeOrder(string $status = 'ready'): Order
    {
        return Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
    }

    public function test_admin_can_dispatch_a_ready_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->makeOrder('ready');

        $this->actingAs($admin)
            ->post(route('orders.dispatch', $order))
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame('dispatched', $order->status);
        $this->assertNotNull($order->dispatched_at);
    }

    public function test_office_can_dispatch_a_ready_order(): void
    {
        $office = User::factory()->create(); // default role = office
        $order = $this->makeOrder('ready');

        $this->actingAs($office)
            ->post(route('orders.dispatch', $order))
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('dispatched', $order->fresh()->status);
    }

    public function test_factory_cannot_dispatch_an_order(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $order = $this->makeOrder('ready');

        $this->actingAs($factory)
            ->post(route('orders.dispatch', $order))
            ->assertForbidden();

        $this->assertSame('ready', $order->fresh()->status);
        $this->assertNull($order->fresh()->dispatched_at);
    }

    public function test_driver_cannot_reach_dispatch_route(): void
    {
        $driver = User::factory()->driver()->create();
        $order = $this->makeOrder('ready');

        // Driver isn't in the role group for the orders routes at all.
        $this->actingAs($driver)
            ->post(route('orders.dispatch', $order))
            ->assertForbidden();

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_cannot_dispatch_a_pending_order(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('pending');

        $this->actingAs($office)
            ->from(route('orders.show', $order))
            ->post(route('orders.dispatch', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->dispatched_at);
    }

    public function test_cannot_dispatch_a_confirmed_order(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');

        $this->actingAs($office)
            ->from(route('orders.show', $order))
            ->post(route('orders.dispatch', $order))
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_admin_can_mark_a_dispatched_order_as_delivered(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->makeOrder('dispatched');

        $this->actingAs($admin)
            ->post(route('orders.deliver', $order))
            ->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_factory_cannot_mark_an_order_as_delivered(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $order = $this->makeOrder('dispatched');

        $this->actingAs($factory)
            ->post(route('orders.deliver', $order))
            ->assertForbidden();

        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_cannot_deliver_a_ready_order(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');

        $this->actingAs($office)
            ->from(route('orders.show', $order))
            ->post(route('orders.deliver', $order))
            ->assertSessionHas('error');

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_dispatch_endpoint_requires_authentication(): void
    {
        $order = $this->makeOrder('ready');

        $this->post(route('orders.dispatch', $order))
            ->assertRedirect(route('login'));
    }

    public function test_cancelling_an_order_releases_unfulfilled_allocations(): void
    {
        $office = User::factory()->create();

        $product = Product::create(['name' => 'Milk', 'type' => 'milk', 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => '1L',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.0,
            'base_price' => 2.50,
            'is_active' => true,
        ]);
        $batch = Batch::create([
            'product_id' => $product->id,
            'production_date' => now()->subDay(),
            'expiry_date' => now()->addDays(10),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        $batchItem = BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => 100,
            'quantity_remaining' => 90,
            'unit_weight_kg' => 1.0,
        ]);

        $order = $this->makeOrder('confirmed');
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
            'quantity_allocated' => 10,
            'quantity_fulfilled' => 0,
            'unit_price' => 2.50,
            'line_total' => 25.00,
        ]);
        $allocation = OrderAllocation::create([
            'order_item_id' => $orderItem->id,
            'batch_item_id' => $batchItem->id,
            'quantity_allocated' => 10,
            'quantity_fulfilled' => 0,
            'allocated_at' => now(),
        ]);

        $this->actingAs($office)
            ->put(route('orders.update', $order), [
                'status' => 'cancelled',
                'payment_status' => 'pending',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNull(OrderAllocation::find($allocation->id));
        $this->assertSame(0, (int) $orderItem->fresh()->quantity_allocated);
        // Batch stock untouched (was never fulfilled).
        $this->assertSame(90, (int) $batchItem->fresh()->quantity_remaining);
    }

    public function test_cancelling_a_ready_order_returns_picked_stock(): void
    {
        $office = User::factory()->create();

        $product = Product::create(['name' => 'Milk', 'type' => 'milk', 'is_active' => true]);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => '1L', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.50, 'is_active' => true,
        ]);
        $batch = Batch::create([
            'product_id' => $product->id, 'production_date' => now()->subDay(),
            'expiry_date' => now()->addDays(10), 'raw_milk_litres' => 100, 'status' => 'active',
        ]);
        $batchItem = BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $variant->id,
            'quantity_produced' => 100, 'quantity_remaining' => 100, 'unit_weight_kg' => 1.0,
        ]);

        $order = $this->makeOrder('confirmed');
        $orderItem = OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $variant->id,
            'quantity_ordered' => 10, 'unit_price' => 2.50,
        ]);
        $alloc = $orderItem->allocateFromBatchItem($batchItem, 10);
        $orderItem->fulfillAllocation($alloc, 10); // batch 100 → 90
        $order->update(['status' => 'ready']);

        $this->assertSame(90, (int) $batchItem->fresh()->quantity_remaining);

        $this->actingAs($office)
            ->put(route('orders.update', $order), [
                'status' => 'cancelled',
                'payment_status' => 'pending',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('cancelled', $order->fresh()->status);
        // Picked stock is now returned (was previously left stranded).
        $this->assertSame(100, (int) $batchItem->fresh()->quantity_remaining);
        $this->assertSame(0, (int) $orderItem->fresh()->quantity_fulfilled);
    }
}
