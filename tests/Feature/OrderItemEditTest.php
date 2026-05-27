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
 * Editing a line's quantity and removing a line on an existing order, with
 * allocation unwinding. Shrinking/removing returns reserved units (no stock
 * change) and picked units (restores BatchItem.quantity_remaining) — fixing
 * the FK-cascade gap where deleting an item stranded picked stock.
 */
class OrderItemEditTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private BatchItem $batchItemA;

    private BatchItem $batchItemB;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::create(['name' => 'Test Milk', 'type' => 'milk', 'is_active' => true]);

        $this->variantA = ProductVariant::create([
            'product_id' => $product->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 2.00, 'is_active' => true,
        ]);
        $this->variantB = ProductVariant::create([
            'product_id' => $product->id, 'name' => '2L Bottle', 'size' => '2L', 'unit' => 'bottle',
            'weight_kg' => 2.0, 'base_price' => 2.00, 'is_active' => true,
        ]);

        $batch = Batch::create([
            'product_id' => $product->id,
            'production_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        $this->batchItemA = BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->variantA->id,
            'quantity_produced' => 10, 'quantity_remaining' => 10, 'unit_weight_kg' => 1.0,
        ]);
        $this->batchItemB = BatchItem::create([
            'batch_id' => $batch->id, 'product_variant_id' => $this->variantB->id,
            'quantity_produced' => 10, 'quantity_remaining' => 10, 'unit_weight_kg' => 2.0,
        ]);

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market', 'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar', 'city' => 'Dublin', 'postal_code' => 'D02 X285', 'is_active' => true,
        ]);
    }

    private function makeOrder(string $status): Order
    {
        return Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
    }

    private function addItem(Order $order, ProductVariant $variant, int $qty): OrderItem
    {
        return $order->orderItems()->create([
            'product_variant_id' => $variant->id,
            'quantity_ordered' => $qty,
            'unit_price' => $variant->base_price,
        ]);
    }

    public function test_reduce_qty_releases_unfulfilled_reservation_without_touching_stock(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');
        $item = $this->addItem($order, $this->variantA, 5);
        $item->allocateFromBatchItem($this->batchItemA, 5); // reserved, not fulfilled

        $this->actingAs($office)
            ->patch(route('orders.items.update', [$order, $item]), ['quantity' => 3])
            ->assertRedirect(route('orders.show', $order));

        $item->refresh();
        $this->assertSame(3, $item->quantity_ordered);
        $this->assertSame(3, $item->quantity_allocated);
        $this->assertSame(10, (int) $this->batchItemA->fresh()->quantity_remaining); // untouched
        $this->assertSame('6.00', (string) $order->fresh()->total_amount); // 3 × €2
    }

    public function test_reduce_qty_below_fulfilled_restores_stock(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');
        $item = $this->addItem($order, $this->variantA, 5);
        $alloc = $item->allocateFromBatchItem($this->batchItemA, 5);
        $item->fulfillAllocation($alloc, 5); // batch remaining 10 → 5

        $this->actingAs($office)
            ->patch(route('orders.items.update', [$order, $item]), ['quantity' => 2])
            ->assertRedirect(route('orders.show', $order));

        $item->refresh();
        $this->assertSame(2, $item->quantity_ordered);
        $this->assertSame(2, $item->quantity_fulfilled);
        $this->assertSame(8, (int) $this->batchItemA->fresh()->quantity_remaining); // 3 returned
        // Still fully fulfilled at the lower qty, so it stays ready.
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_increase_qty_on_ready_order_reverts_to_preparing(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');
        $item = $this->addItem($order, $this->variantA, 5);
        $alloc = $item->allocateFromBatchItem($this->batchItemA, 5);
        $item->fulfillAllocation($alloc, 5);

        $this->actingAs($office)
            ->patch(route('orders.items.update', [$order, $item]), ['quantity' => 8])
            ->assertRedirect(route('orders.show', $order));

        $item->refresh();
        $this->assertSame(8, $item->quantity_ordered);
        $this->assertSame(5, $item->quantity_allocated); // unchanged — shortfall now shows in picker
        $this->assertSame('preparing', $order->fresh()->status);
        $this->assertSame('16.00', (string) $order->fresh()->total_amount); // 8 × €2
    }

    public function test_remove_unfulfilled_line_drops_allocations_and_updates_totals(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('confirmed');
        $keep = $this->addItem($order, $this->variantA, 5);
        $remove = $this->addItem($order, $this->variantB, 4);
        $remove->allocateFromBatchItem($this->batchItemB, 4); // reserved only

        $this->actingAs($office)
            ->delete(route('orders.items.destroy', [$order, $remove]))
            ->assertRedirect(route('orders.show', $order));

        $this->assertNull(OrderItem::find($remove->id));
        $this->assertCount(1, $order->fresh()->orderItems);
        $this->assertSame(10, (int) $this->batchItemB->fresh()->quantity_remaining); // never fulfilled
        $this->assertSame('10.00', (string) $order->fresh()->total_amount); // only line A: 5 × €2
    }

    public function test_remove_fulfilled_line_restores_exact_stock(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');
        $this->addItem($order, $this->variantA, 5);
        $remove = $this->addItem($order, $this->variantB, 4);
        $allocB = $remove->allocateFromBatchItem($this->batchItemB, 4);
        $remove->fulfillAllocation($allocB, 4); // batch B remaining 10 → 6

        $this->actingAs($office)
            ->delete(route('orders.items.destroy', [$order, $remove]))
            ->assertRedirect(route('orders.show', $order));

        $this->assertNull(OrderItem::find($remove->id));
        $this->assertSame(10, (int) $this->batchItemB->fresh()->quantity_remaining); // 4 returned
        $this->assertCount(1, $order->fresh()->orderItems);
    }

    public function test_removing_the_unpicked_line_completes_the_pick_and_flips_to_ready(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('preparing');
        $picked = $this->addItem($order, $this->variantA, 5);
        $allocA = $picked->allocateFromBatchItem($this->batchItemA, 5);
        $picked->fulfillAllocation($allocA, 5); // line A fully fulfilled
        $unpicked = $this->addItem($order, $this->variantB, 4); // nothing allocated

        $this->actingAs($office)
            ->delete(route('orders.items.destroy', [$order, $unpicked]))
            ->assertRedirect(route('orders.show', $order));

        // Only the fully-fulfilled line remains → order advances to ready.
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_removing_the_only_line_cancels_the_order_and_restores_stock(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');
        $only = $this->addItem($order, $this->variantA, 5);
        $alloc = $only->allocateFromBatchItem($this->batchItemA, 5);
        $only->fulfillAllocation($alloc, 5); // batch A remaining 10 → 5

        $this->actingAs($office)
            ->delete(route('orders.items.destroy', [$order, $only]))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, (int) $this->batchItemA->fresh()->quantity_remaining); // stock returned
        $this->assertNotNull(OrderItem::find($only->id), 'Line kept as history on the cancelled order.');
    }

    public function test_factory_cannot_edit_or_remove(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $order = $this->makeOrder('confirmed');
        $a = $this->addItem($order, $this->variantA, 5);
        $this->addItem($order, $this->variantB, 2);

        $this->actingAs($factory)
            ->patch(route('orders.items.update', [$order, $a]), ['quantity' => 3])
            ->assertForbidden();
        $this->actingAs($factory)
            ->delete(route('orders.items.destroy', [$order, $a]))
            ->assertForbidden();

        $this->assertSame(5, (int) $a->fresh()->quantity_ordered);
    }

    public function test_cannot_edit_or_remove_on_a_dispatched_order(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('dispatched');
        $a = $this->addItem($order, $this->variantA, 5);
        $this->addItem($order, $this->variantB, 2);

        $this->actingAs($office)
            ->patch(route('orders.items.update', [$order, $a]), ['quantity' => 3])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $this->assertSame(5, (int) $a->fresh()->quantity_ordered);
        $this->assertSame('dispatched', $order->fresh()->status);
    }
}
