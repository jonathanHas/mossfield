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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Dispatch now issues an invoice and emails a dispatch confirmation.
        Mail::fake();

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

    // ------------------------------------------------ edit-form status (F-19)

    private function patchStatus(Order $order, ?string $status, array $extra = [])
    {
        $payload = array_merge([
            'order_date' => $order->order_date->toDateString(),
            'delivery_date' => null,
            'notes' => 'edited',
        ], $extra);
        if ($status !== null) {
            $payload['status'] = $status;
        }

        return $this->actingAs(User::factory()->create())
            ->patch(route('orders.update', $order), $payload);
    }

    public function test_the_edit_route_cannot_jump_an_order_to_dispatched(): void
    {
        // E2E F-19: a pending/cancelled order became "dispatched" with no
        // invoice, no dispatch time and nothing picked.
        $order = $this->makeOrder('pending');

        $this->patchStatus($order, 'dispatched')
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error', 'Order status moves through the workflow — use Confirm, picking, Mark dispatched or Mark delivered.');

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->dispatched_at);
        $this->assertNull($order->invoice_id);
        $this->assertNull($order->notes, 'nothing is written when the move is refused');
    }

    public function test_the_edit_route_cannot_mark_an_unpicked_order_ready(): void
    {
        $order = $this->makeOrder('confirmed');

        $this->patchStatus($order, 'ready')->assertSessionHas('error');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_the_edit_route_cannot_revive_a_cancelled_order(): void
    {
        $order = $this->makeOrder('cancelled');

        $this->patchStatus($order, 'pending')->assertSessionHas('error');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_show_page_confirm_and_cancel_moves_still_work(): void
    {
        $pending = $this->makeOrder('pending');
        $this->patchStatus($pending, 'confirmed')->assertSessionHas('success');
        $this->assertSame('confirmed', $pending->fresh()->status);

        $ready = $this->makeOrder('ready');
        $this->patchStatus($ready, 'cancelled')->assertSessionHas('success');
        $this->assertSame('cancelled', $ready->fresh()->status);

        // Already shipped: cancelling is not a legal move.
        $dispatched = $this->makeOrder('dispatched');
        $this->patchStatus($dispatched, 'cancelled')->assertSessionHas('error');
        $this->assertSame('dispatched', $dispatched->fresh()->status);
    }

    public function test_the_edit_form_saves_without_posting_a_status(): void
    {
        $order = $this->makeOrder('preparing');

        $this->patchStatus($order, null, ['notes' => 'ring the bell'])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('preparing', $order->status);
        $this->assertSame('ring the bell', $order->notes);
    }

    public function test_the_edit_page_has_no_status_input(): void
    {
        $order = $this->makeOrder('confirmed');

        $this->actingAs(User::factory()->create())
            ->get(route('orders.edit', $order))
            ->assertOk()
            ->assertDontSee('name="status"', false)
            ->assertSee('Status changes from the order page.');
    }

    public function test_confirming_says_confirmed_other_edits_say_updated(): void
    {
        $pending = $this->makeOrder('pending');
        $this->patchStatus($pending, 'confirmed')->assertSessionHas('success', 'Order confirmed.');

        $this->patchStatus($pending->fresh(), null)->assertSessionHas('success', 'Order updated successfully.');
    }

    public function test_an_invoiced_order_offers_no_edit_link(): void
    {
        $office = User::factory()->create();
        $order = $this->makeOrder('ready');
        $milk = Product::create(['name' => 'Milk', 'type' => 'milk', 'is_active' => true]);
        $bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L', 'size' => '1L', 'unit' => 'bottle', 'base_price' => 2.00, 'is_active' => true,
        ]);
        $order->orderItems()->create(['product_variant_id' => $bottle->id, 'quantity_ordered' => 2, 'quantity_fulfilled' => 2, 'unit_price' => 2.00]);

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertSee(route('orders.edit', $order), false);

        $this->actingAs($office)->post(route('orders.dispatch', $order));
        $order->refresh();
        $this->assertNotNull($order->invoice_id, 'dispatch issued the per-order invoice');

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee(route('orders.edit', $order), false)
            ->assertSee('On invoice '.$order->invoice->number.' — void it to edit.');
    }
}
