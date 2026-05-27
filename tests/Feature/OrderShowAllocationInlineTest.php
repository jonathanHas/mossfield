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

/**
 * The stock-allocation UI now lives inline on the order detail page
 * (/orders/{order}) for orders in the picking workflow, instead of on a
 * separate /order-allocations/{order} page. These tests pin that behaviour:
 *   - office users see the interactive allocate/fulfill/auto-allocate forms
 *   - factory (view-only) users see the same data read-only, no forms
 *   - non-picking statuses fall back to the plain read-only items table
 *   - the old allocation route redirects to the order detail page
 */
class OrderShowAllocationInlineTest extends TestCase
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

    public function test_office_sees_inline_allocation_forms_on_confirmed_order(): void
    {
        $office = User::factory()->create(); // default role = office
        $order = $this->makeOrder('confirmed');
        $orderItem = $order->orderItems->first();

        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(route('order-allocations.auto-allocate', $order))
            ->assertSee(route('order-allocations.allocate', $orderItem));
    }

    public function test_factory_sees_order_items_read_only_without_forms(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $order = $this->makeOrder('confirmed');
        $orderItem = $order->orderItems->first();

        $this->actingAs($factory)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($this->product->name)
            ->assertSee('Needs fulfilment')
            ->assertDontSee(route('order-allocations.allocate', $orderItem))
            ->assertDontSee(route('order-allocations.auto-allocate', $order));
    }

    public function test_pending_order_shows_plain_items_table_without_allocation(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('pending');
        $orderItem = $order->orderItems->first();

        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($this->product->name)
            ->assertDontSee(route('order-allocations.allocate', $orderItem))
            ->assertDontSee(route('order-allocations.auto-allocate', $order));
    }

    public function test_ready_order_keeps_allocation_tables_with_undo(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');
        $orderItem = $order->orderItems->first();
        $orderItem->update([
            'quantity_allocated' => 5,
            'quantity_fulfilled' => 5,
        ]);

        $allocation = OrderAllocation::create([
            'order_item_id' => $orderItem->id,
            'batch_item_id' => $this->batchItem->id,
            'quantity_allocated' => 5,
            'quantity_fulfilled' => 5,
            'allocated_at' => now(),
            'fulfilled_at' => now(),
        ]);

        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(route('order-allocations.unfulfill', $allocation));
    }

    public function test_old_allocation_show_route_redirects_to_order(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');

        $this->actingAs($office)
            ->get(route('order-allocations.show', $order))
            ->assertRedirect(route('orders.show', $order));
    }
}
