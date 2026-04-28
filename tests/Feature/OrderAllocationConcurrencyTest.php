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
        $this->assertSame(1, $orderItemB->orderAllocations()->count());
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
}
