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
 * Verifies the implicit status transitions that the allocation controller
 * performs so the order-detail stepper stays in sync without the user
 * having to manually edit the status dropdown.
 *
 *   confirmed → preparing  on first allocation
 *   preparing → ready      when every item is fully fulfilled
 */
class OrderAllocationStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    private BatchItem $batchItem;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Test Milk',
            'type' => 'milk',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'weight_kg' => 1.030,
            'base_price' => 2.00,
            'is_active' => true,
        ]);

        $batch = Batch::create([
            'product_id' => $this->product->id,
            'production_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        $this->batchItem = BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $this->variant->id,
            'quantity_produced' => 10,
            'quantity_remaining' => 10,
            'unit_weight_kg' => 1.030,
        ]);

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market',
            'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar',
            'city' => 'Dublin',
            'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);
    }

    private function makeOrder(string $status, int $itemQty = 5): Order
    {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->variant->id,
            'quantity_ordered' => $itemQty,
            'unit_price' => $this->variant->base_price,
        ]);

        return $order->fresh(['orderItems']);
    }

    public function test_allocate_flips_confirmed_to_preparing(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');
        $orderItem = $order->orderItems->first();

        $this->actingAs($office)
            ->post(route('order-allocations.allocate', $orderItem), [
                'batch_item_id' => $this->batchItem->id,
                'quantity' => 5,
            ])
            ->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_auto_allocate_flips_confirmed_to_preparing(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');

        $this->actingAs($office)
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_allocate_leaves_status_alone_when_already_preparing(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('preparing');
        $orderItem = $order->orderItems->first();

        $this->actingAs($office)
            ->post(route('order-allocations.allocate', $orderItem), [
                'batch_item_id' => $this->batchItem->id,
                'quantity' => 3,
            ])
            ->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_fulfilling_last_allocation_flips_preparing_to_ready(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');
        $orderItem = $order->orderItems->first();

        // Allocate then fulfill all 5 via the controller endpoints so the
        // auto-transition logic runs end-to-end.
        $this->actingAs($office)
            ->post(route('order-allocations.allocate', $orderItem), [
                'batch_item_id' => $this->batchItem->id,
                'quantity' => 5,
            ])->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);

        $allocation = $orderItem->orderAllocations()->first();

        $this->actingAs($office)
            ->post(route('order-allocations.fulfill', $allocation), [
                'quantity' => 5,
            ])->assertRedirect();

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_partial_fulfillment_does_not_flip_to_ready(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed', itemQty: 5);
        $orderItem = $order->orderItems->first();

        $this->actingAs($office)
            ->post(route('order-allocations.allocate', $orderItem), [
                'batch_item_id' => $this->batchItem->id,
                'quantity' => 5,
            ])->assertRedirect();

        $allocation = $orderItem->orderAllocations()->first();

        // Only 3 of 5 fulfilled — order should stay at preparing.
        $this->actingAs($office)
            ->post(route('order-allocations.fulfill', $allocation), [
                'quantity' => 3,
            ])->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_unfulfilling_drops_a_ready_order_back_to_preparing(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed', itemQty: 5);
        $orderItem = $order->orderItems->first();

        $alloc = $orderItem->allocateFromBatchItem($this->batchItem, 5);
        $orderItem->fulfillAllocation($alloc, 5);
        $order->update(['status' => 'ready']); // fully picked

        $this->actingAs($office)
            ->post(route('order-allocations.unfulfill', $alloc), ['quantity' => 5])
            ->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertSame(10, (int) $this->batchItem->fresh()->quantity_remaining);
    }

    public function test_deallocating_drops_a_ready_order_back_to_preparing(): void
    {
        $office = User::factory()->create();
        // Reproduce the stuck state: a "ready" order whose only allocation is
        // unfulfilled (e.g. picked then undone). Removing it must un-stick status.
        $order = $this->makeOrder('confirmed', itemQty: 5);
        $orderItem = $order->orderItems->first();
        $alloc = $orderItem->allocateFromBatchItem($this->batchItem, 5);
        $order->update(['status' => 'ready']);

        $this->actingAs($office)
            ->delete(route('order-allocations.deallocate', $alloc))
            ->assertRedirect();

        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertSame(0, (int) $orderItem->fresh()->quantity_allocated);
    }
}
