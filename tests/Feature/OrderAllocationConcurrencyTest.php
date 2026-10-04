<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAllocationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

    private Batch $batch;

    private BatchItem $batchItem;

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

        $this->batch = Batch::create([
            'product_id' => $this->product->id,
            'production_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        $this->batchItem = BatchItem::create([
            'batch_id' => $this->batch->id,
            'product_variant_id' => $this->variant->id,
            'quantity_produced' => 10,
            'quantity_remaining' => 10,
            'unit_weight_kg' => 1.030,
        ]);
    }

    public function test_allocation_rejects_quantity_exceeding_available_after_existing_allocations(): void
    {
        $orderItemA = $this->makeOrderItem(quantityOrdered: 8);
        $orderItemB = $this->makeOrderItem(quantityOrdered: 5);

        $first = $orderItemA->allocateFromBatchItem($this->batchItem, 8);
        $this->assertNotNull($first, 'First allocation of 8 should succeed (10 available).');

        // 8 of 10 already allocated and unfulfilled. Asking for 5 more must be rejected.
        $second = $orderItemB->allocateFromBatchItem($this->batchItem->fresh(), 5);

        $this->assertNull($second, 'Second allocation must be rejected — only 2 available.');
        $this->assertSame(0, $orderItemB->orderAllocations()->count(), 'Rejected allocation must create no row.');
        $this->assertSame(0, (int) $orderItemB->orderAllocations()->sum('quantity_allocated'));
    }

    public function test_allocation_accepts_quantity_within_available_window(): void
    {
        $orderItemA = $this->makeOrderItem(quantityOrdered: 8);
        $orderItemB = $this->makeOrderItem(quantityOrdered: 2);

        $orderItemA->allocateFromBatchItem($this->batchItem, 8);
        $second = $orderItemB->allocateFromBatchItem($this->batchItem->fresh(), 2);

        $this->assertNotNull($second);
        $this->assertSame(0, $this->batchItem->fresh()->available_quantity);
    }

    public function test_fulfill_then_unfulfill_round_trips_quantity_remaining(): void
    {
        $orderItem = $this->makeOrderItem(quantityOrdered: 5);
        $allocation = $orderItem->allocateFromBatchItem($this->batchItem, 5);

        $this->assertTrue($orderItem->fulfillAllocation($allocation, 5));
        $this->assertSame(5, $this->batchItem->fresh()->quantity_remaining);

        $this->assertTrue($orderItem->unfulfillAllocation($allocation->fresh(), 5));
        $this->assertSame(10, $this->batchItem->fresh()->quantity_remaining);
    }

    public function test_fulfill_rejects_quantity_greater_than_allocation_remaining(): void
    {
        $orderItem = $this->makeOrderItem(quantityOrdered: 5);
        $allocation = $orderItem->allocateFromBatchItem($this->batchItem, 5);

        $this->assertTrue($orderItem->fulfillAllocation($allocation, 3));

        // Allocation has 2 remaining; asking for 3 must be rejected and stock untouched.
        $this->assertFalse($orderItem->fulfillAllocation($allocation->fresh(), 3));
        $this->assertSame(7, $this->batchItem->fresh()->quantity_remaining);
    }

    private function makeOrderItem(int $quantityOrdered): OrderItem
    {
        $customer = Customer::create([
            'name' => 'Test Customer '.uniqid(),
            'email' => 'test_'.uniqid().'@example.com',
            'address' => '1 Test Lane',
            'city' => 'Test City',
            'postal_code' => 'X00 X00',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-TEST-'.uniqid(),
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->variant->id,
            'quantity_ordered' => $quantityOrdered,
            'unit_price' => $this->variant->base_price,
        ]);
    }

    public function test_the_allocate_refusal_says_which_limit_was_hit(): void
    {
        $office = \App\Models\User::factory()->create();

        // The line is already covered.
        $full = $this->makeOrderItem(quantityOrdered: 2);
        $full->allocateFromBatchItem($this->batchItem, 2);
        $this->actingAs($office)->post(route('order-allocations.allocate', $full), [
            'batch_item_id' => $this->batchItem->id, 'quantity' => 1,
        ])->assertSessionHasErrors(['quantity' => 'This line is already fully allocated (2 of 2).']);

        // The batch hasn't that many free (10 − 2 reserved = 8).
        $big = $this->makeOrderItem(quantityOrdered: 20);
        $this->actingAs($office)->post(route('order-allocations.allocate', $big), [
            'batch_item_id' => $this->batchItem->id, 'quantity' => 9,
        ])->assertSessionHasErrors(['quantity' => 'Only 8 units are available in this batch right now.']);
    }

    public function test_asking_for_more_than_the_line_needs_says_how_many_it_needs(): void
    {
        $office = \App\Models\User::factory()->create();
        $this->batchItem->update(['quantity_produced' => 50, 'quantity_remaining' => 50]);
        $line = $this->makeOrderItem(quantityOrdered: 10);
        $line->allocateFromBatchItem($this->batchItem, 6);

        $this->actingAs($office)->post(route('order-allocations.allocate', $line), [
            'batch_item_id' => $this->batchItem->id, 'quantity' => 5,
        ])->assertSessionHasErrors(['quantity' => 'This line only needs 4 more — allocate at most that many.']);
        $this->assertSame(6, (int) $line->fresh()->quantity_allocated);

        // Exactly what it needs allocates. (A second batch: topping up the
        // same batch's reservation through allocate() hits the unique
        // (order_item, batch_item) index — a separate, pre-existing gap.)
        $second = BatchItem::create([
            'batch_id' => $this->batch->id, 'product_variant_id' => $this->variant->id,
            'quantity_produced' => 50, 'quantity_remaining' => 50, 'unit_weight_kg' => 1.030,
        ]);
        $this->actingAs($office)->post(route('order-allocations.allocate', $line), [
            'batch_item_id' => $second->id, 'quantity' => 4,
        ])->assertSessionHasNoErrors();
        $this->assertSame(10, (int) $line->fresh()->quantity_allocated);
    }
}
