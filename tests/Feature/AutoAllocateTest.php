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
 * The shared FIFO allocation loop (Concerns\AllocatesOrderStock) and its
 * order-page caller. The loop is also driven by the chilled run sheet — see
 * the allocation section of ChilledRunTest.
 */
class AutoAllocateTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $variant;

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

        $this->customer = Customer::create([
            'name' => 'Dublin Farmers Market',
            'email' => 'orders@dublinfarmersmarket.ie',
            'address' => 'Temple Bar',
            'city' => 'Dublin',
            'postal_code' => 'D02 X285',
            'is_active' => true,
        ]);
    }

    private function stock(int $quantity, string $productionDate): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->product->id,
            'production_date' => $productionDate,
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $this->variant->id,
            'quantity_produced' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_weight_kg' => 1.030,
        ]);
    }

    private function makeOrder(string $status, int $itemQty): Order
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

    /**
     * Regression guard: the old counter incremented once per allocation ROW,
     * so a line split across three batches reported "3 order items".
     */
    public function test_auto_allocate_counts_units_not_allocation_rows(): void
    {
        $this->stock(4, now()->subDays(3)->toDateString());
        $this->stock(4, now()->subDays(2)->toDateString());
        $this->stock(2, now()->subDay()->toDateString());

        $order = $this->makeOrder('confirmed', 10);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '10 units')
                && str_contains($message, '1 line')
                && ! str_contains($message, '3 order items'));

        $this->assertSame(10, $order->orderItems()->first()->quantity_allocated);
        $this->assertSame(3, $order->orderItems()->first()->orderAllocations()->count());
    }

    public function test_auto_allocate_draws_from_the_oldest_batch_first(): void
    {
        $older = $this->stock(4, now()->subDays(5)->toDateString());
        $newer = $this->stock(10, now()->subDay()->toDateString());

        $order = $this->makeOrder('confirmed', 6);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect();

        $orderItem = $order->orderItems()->first();

        $this->assertSame(4, (int) $orderItem->orderAllocations()
            ->where('batch_item_id', $older->id)->sum('quantity_allocated'));
        $this->assertSame(2, (int) $orderItem->orderAllocations()
            ->where('batch_item_id', $newer->id)->sum('quantity_allocated'));
    }

    public function test_auto_allocate_from_the_order_page_still_promotes_confirmed_to_preparing(): void
    {
        $this->stock(10, now()->subDay()->toDateString());
        $order = $this->makeOrder('confirmed', 5);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_auto_allocate_reserves_without_decrementing_batch_stock(): void
    {
        $batchItem = $this->stock(10, now()->subDay()->toDateString());
        $order = $this->makeOrder('confirmed', 4);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect();

        // Reservation only — stock moves when a pick is recorded, not before.
        $this->assertSame(10, $batchItem->fresh()->quantity_remaining);
        $this->assertSame(6, $batchItem->fresh()->available_quantity);
    }

    public function test_auto_allocate_reports_an_error_when_no_stock_is_available(): void
    {
        $order = $this->makeOrder('confirmed', 5);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHasErrors('auto_allocate');

        $this->assertSame(0, $order->orderItems()->first()->quantity_allocated);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_auto_allocate_flags_a_partially_filled_line(): void
    {
        $this->stock(3, now()->subDay()->toDateString());
        $order = $this->makeOrder('confirmed', 10);

        $this->actingAs(User::factory()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '3 units')
                // The short lines are named, not just counted, so the office
                // knows which products to chase or close short.
                && str_contains($message, 'Not fully allocated:')
                && str_contains($message, $this->variant->name));

        $this->assertSame(3, $order->orderItems()->first()->quantity_allocated);
    }

    public function test_factory_cannot_auto_allocate(): void
    {
        $this->stock(10, now()->subDay()->toDateString());
        $order = $this->makeOrder('confirmed', 5);

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertForbidden();

        $this->assertSame(0, $order->orderItems()->first()->quantity_allocated);
    }
}
