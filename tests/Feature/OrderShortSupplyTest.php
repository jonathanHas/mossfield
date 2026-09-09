<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Short supply — closing a line that can't be filled.
 *
 * Before this, an order with a zero-stock line was a dead end: Order's
 * preparing → ready promotion needed every line fully picked, and dispatch
 * needed ready, so the order could never ship. Closing a line short settles it
 * (releasing its reservation, leaving picked stock alone) so the order
 * completes and bills only what actually went on the van.
 *
 * The shortfall is derived, not stored — quantity_short is
 * max(0, ordered - fulfilled) while short_closed_at is set — so it can never
 * drift from quantity_fulfilled and self-heals if the line is later picked.
 */
class OrderShortSupplyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true,
        ]);
        $this->customer = Customer::create([
            'name' => 'Kerry Organic', 'email' => 'k@example.ie',
            'address' => 'Main St', 'city' => 'Tralee', 'postal_code' => 'V92', 'is_active' => true,
        ]);
    }

    private function variant(array $attrs = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $this->product->id,
            'name' => 'V'.uniqid(),
            'size' => 'x',
            'unit' => 'x',
            'weight_kg' => 1.0,
            'base_price' => 10.00,
            'is_active' => true,
        ], $attrs));
    }

    private function batchItem(ProductVariant $variant, int $remaining = 10): BatchItem
    {
        $batch = Batch::create([
            'product_id' => $this->product->id,
            'production_date' => now()->subDay()->toDateString(),
            'ready_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => $remaining,
            'quantity_remaining' => $remaining,
            'unit_weight_kg' => $variant->weight_kg,
        ]);
    }

    private function orderWithItem(ProductVariant $variant, int $qty, string $status = 'preparing'): array
    {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $item = $order->orderItems()->create([
            'product_variant_id' => $variant->id,
            'quantity_ordered' => $qty,
            'unit_price' => $variant->base_price,
        ]);

        return [$order, $item];
    }

    private function office(): User
    {
        return User::factory()->create();
    }

    // ── The core unblock ────────────────────────────────────────────

    public function test_picker_closes_a_zero_stock_line_short_and_the_order_goes_ready(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);
        // Deliberately no batch item at all — the order-3 situation.

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('picking.short', [$order, $item]))
            ->assertRedirect();

        $item->refresh();
        $this->assertNotNull($item->short_closed_at);
        $this->assertTrue($item->isShortClosed());
        $this->assertSame(10, $item->quantity_short);
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_mixed_order_promotes_once_the_last_open_line_is_closed_short(): void
    {
        $stocked = $this->variant();
        $empty = $this->variant();
        $batchItem = $this->batchItem($stocked, 5);

        [$order, $pickedLine] = $this->orderWithItem($stocked, 5);
        $shortLine = $order->orderItems()->create([
            'product_variant_id' => $empty->id,
            'quantity_ordered' => 10,
            'unit_price' => $empty->base_price,
        ]);

        $pickedLine->pickFromBatchItem($batchItem, 5);
        $order->refresh()->reconcilePickingStatus();
        $this->assertSame('preparing', $order->fresh()->status, 'Still stuck on the empty line.');

        $this->actingAs($this->office())
            ->post(route('orders.items.short', [$order, $shortLine]))
            ->assertRedirect(route('orders.show', $order));

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_a_short_closed_order_can_be_dispatched(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 4);

        $office = $this->office();
        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));
        $this->actingAs($office)->post(route('orders.dispatch', $order))->assertRedirect();

        $this->assertSame('dispatched', $order->fresh()->status);
    }

    // ── Stock unwinding ─────────────────────────────────────────────

    public function test_closing_short_releases_the_reservation_but_keeps_picked_stock(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 10);
        [$order, $item] = $this->orderWithItem($variant, 8);

        // Reserve 8, pick only 2 of them.
        $item->allocateFromBatchItem($batchItem, 8);
        $allocation = $item->orderAllocations()->first();
        $item->fulfillAllocation($allocation, 2);

        $remainingAfterPick = $batchItem->fresh()->quantity_remaining;

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $item->refresh();
        // The 6 reserved-but-unpicked units drop their reservation; the 2 picked
        // units (and their batch decrement) are untouched.
        $this->assertSame(2, $item->quantity_allocated);
        $this->assertSame(2, $item->quantity_fulfilled);
        $this->assertSame(6, $item->quantity_short);
        $this->assertSame($remainingAfterPick, $batchItem->fresh()->quantity_remaining);
    }

    // ── Billing — the regression that matters most ──────────────────

    public function test_a_never_picked_short_line_bills_nothing(): void
    {
        $variant = $this->variant(['base_price' => 9.50]);
        [$order, $item] = $this->orderWithItem($variant, 10);

        // Before: the estimate covers all 10 units.
        $this->assertSame(95.0, round($item->invoiceable_total, 2));

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $item->refresh();
        $this->assertSame(0.0, round($item->invoiceable_total, 2));
        $this->assertSame(0.0, round((float) $order->fresh()->subtotal, 2));
    }

    public function test_a_partially_picked_short_line_bills_only_the_picked_units(): void
    {
        $variant = $this->variant(['base_price' => 10.00]);
        $batchItem = $this->batchItem($variant, 4);
        [$order, $item] = $this->orderWithItem($variant, 10);

        $item->pickFromBatchItem($batchItem, 4);

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $item->refresh();
        $this->assertSame(40.0, round($item->invoiceable_total, 2), '4 picked × €10, not 10 × €10.');
        $this->assertSame(40.0, round((float) $order->fresh()->subtotal, 2));
    }

    public function test_a_weight_priced_short_line_bills_the_recorded_weight_only(): void
    {
        $variant = $this->variant([
            'base_price' => 12.00,          // €/kg
            'weight_kg' => 2.0,
            'is_variable_weight' => true,
            'is_bulk_weighed' => true,
            'is_priced_by_weight' => true,
        ]);
        $batchItem = $this->batchItem($variant, 3);
        [$order, $item] = $this->orderWithItem($variant, 5);

        $item->pickFromBatchItem($batchItem, 3, 5.5);

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $item->refresh();
        $this->assertSame(66.0, round($item->invoiceable_total, 2), '5.5 kg × €12/kg.');
        $this->assertSame(2, $item->quantity_short);
    }

    public function test_the_invoice_quantity_column_shows_what_was_supplied(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 3);
        [$order, $item] = $this->orderWithItem($variant, 10);
        $item->pickFromBatchItem($batchItem, 3);

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $this->assertSame(3, $item->fresh()->invoiceable_quantity);
    }

    public function test_a_percentage_delivery_charge_shrinks_with_the_shortfall(): void
    {
        $variant = $this->variant(['base_price' => 10.00]);
        $batchItem = $this->batchItem($variant, 4);
        [$order, $item] = $this->orderWithItem($variant, 10);
        $order->update(['delivery_charge_percent' => 10.00]);

        $item->pickFromBatchItem($batchItem, 4);
        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $order->refresh();
        // Subtotal 40.00 → 10% gross charge of 4.00, VAT backed out of it.
        $this->assertSame(40.0, round((float) $order->subtotal, 2));
        $this->assertSame(4.0, round((float) $order->delivery_charge, 2));
        $this->assertSame(44.0, round((float) $order->total_amount, 2));
    }

    // ── Paperwork ───────────────────────────────────────────────────

    public function test_the_docket_shows_a_short_column(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);
        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $this->actingAs($this->office())->get(route('orders.docket', $order))
            ->assertOk()
            ->assertSee('Short')
            ->assertSee('Short supplied — 10 not sent');
    }

    public function test_the_invoice_marks_an_unsupplied_line_as_not_charged(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);
        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $this->actingAs($this->office())->get(route('orders.invoice', $order))
            ->assertOk()
            ->assertSee('Not supplied — not charged.')
            ->assertSee('€0.00');
    }

    // ── Reopening + line edits ──────────────────────────────────────

    public function test_reopening_a_short_line_drops_the_order_back_to_preparing(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);
        $office = $this->office();

        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));
        $this->assertSame('ready', $order->fresh()->status);

        $this->actingAs($office)->post(route('orders.items.reopen', [$order, $item]))->assertRedirect();

        $item->refresh();
        $this->assertNull($item->short_closed_at);
        $this->assertSame(0, $item->quantity_short);
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_raising_the_quantity_on_a_short_line_reopens_it(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);
        $office = $this->office();

        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));
        $this->assertSame('ready', $order->fresh()->status);

        // New demand nobody has assessed must re-enter picking rather than
        // riding along as "settled" on a ready order.
        $this->actingAs($office)->patch(route('orders.items.update', [$order, $item]), ['quantity' => 15]);

        $item->refresh();
        $this->assertNull($item->short_closed_at);
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_trimming_the_quantity_to_the_picked_amount_clears_the_short(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 4);
        [$order, $item] = $this->orderWithItem($variant, 10);
        $item->pickFromBatchItem($batchItem, 4);
        $office = $this->office();

        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));
        $this->actingAs($office)->patch(route('orders.items.update', [$order, $item]), ['quantity' => 4]);

        $item->refresh();
        $this->assertNull($item->short_closed_at, 'No shortfall left, so no short close.');
        $this->assertSame(0, $item->quantity_short);
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_trimming_the_quantity_above_the_picked_amount_keeps_it_short(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 3);
        [$order, $item] = $this->orderWithItem($variant, 10);
        $item->pickFromBatchItem($batchItem, 3);
        $office = $this->office();

        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));
        $this->actingAs($office)->patch(route('orders.items.update', [$order, $item]), ['quantity' => 6]);

        $item->refresh();
        $this->assertNotNull($item->short_closed_at);
        $this->assertSame(3, $item->quantity_short, 'Shortfall re-derives from the new quantity.');
    }

    public function test_picking_the_rest_after_stock_arrives_clears_the_shortfall(): void
    {
        $variant = $this->variant(['base_price' => 10.00]);
        [$order, $item] = $this->orderWithItem($variant, 5);

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));
        $this->assertSame('ready', $order->fresh()->status);

        // Stock turns up; the picker fills the line after all.
        $batchItem = $this->batchItem($variant, 5);
        $item->refresh()->pickFromBatchItem($batchItem, 5);
        $order->refresh();
        $order->calculateTotals();

        $item->refresh();
        // short_closed_at is still set as the audit record, but the derived
        // accessor stops reporting a shortfall that no longer exists.
        $this->assertNotNull($item->short_closed_at);
        $this->assertFalse($item->isShortClosed());
        $this->assertSame(0, $item->quantity_short);
        $this->assertSame(50.0, round((float) $order->fresh()->subtotal, 2));
    }

    public function test_undoing_a_pick_on_a_short_line_leaves_the_order_ready(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 4);
        [$order, $item] = $this->orderWithItem($variant, 10);
        $item->pickFromBatchItem($batchItem, 4);

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        // Undo is scoped to the pick, not the settlement — the short close was
        // an operator decision and shouldn't silently reverse itself.
        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('picking.undo', [$order, $item]));

        $item->refresh();
        $this->assertSame(0, $item->quantity_fulfilled);
        $this->assertSame(10, $item->quantity_short);
        $this->assertSame('ready', $order->fresh()->status);
    }

    // ── Knock-on behaviour ──────────────────────────────────────────

    public function test_auto_allocate_skips_a_short_closed_line(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10, 'confirmed');
        $office = $this->office();

        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));

        // Stock arrives afterwards — allocation must not silently reserve
        // against a line the office has already settled.
        $this->batchItem($variant, 10);

        $this->actingAs($office)->post(route('order-allocations.auto-allocate', $order));

        $this->assertSame(0, $item->fresh()->quantity_allocated);
    }

    public function test_a_short_closed_line_no_longer_blocks_is_fully_allocated(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);

        $this->assertFalse($order->isFullyAllocated());

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $this->assertTrue($order->fresh()->load('orderItems')->isFullyAllocated());
    }

    public function test_the_picker_is_not_looped_back_to_a_short_closed_line(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 10);
        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]));

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('picking.show', $order))
            ->assertOk()
            ->assertSee('Order closed — short.')
            ->assertDontSee('Order ready.');
    }

    public function test_cancelling_a_short_order_returns_only_the_picked_stock(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 10);
        [$order, $item] = $this->orderWithItem($variant, 8);
        $item->allocateFromBatchItem($batchItem, 8);
        $item->fulfillAllocation($item->orderAllocations()->first(), 2);

        $office = $this->office();
        $this->actingAs($office)->post(route('orders.items.short', [$order, $item]));

        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'cancelled',
            'payment_status' => 'pending',
        ]);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $batchItem->fresh()->quantity_remaining, 'The 2 picked units come back.');
    }

    // ── Authorization ───────────────────────────────────────────────

    public function test_factory_can_mark_short_from_picking_but_not_from_the_office_route(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 5);

        $this->actingAs($factory)->post(route('orders.items.short', [$order, $item]))
            ->assertForbidden();

        $this->actingAs($factory)->post(route('picking.short', [$order, $item]))
            ->assertRedirect();

        $this->assertNotNull($item->fresh()->short_closed_at);
    }

    public function test_driver_cannot_mark_a_line_short(): void
    {
        $driver = User::factory()->driver()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 5);

        $this->actingAs($driver)->post(route('picking.short', [$order, $item]))->assertForbidden();
        $this->assertNull($item->fresh()->short_closed_at);
    }

    public function test_a_fully_picked_line_cannot_be_marked_short(): void
    {
        $variant = $this->variant();
        $batchItem = $this->batchItem($variant, 5);
        [$order, $item] = $this->orderWithItem($variant, 5);
        $item->pickFromBatchItem($batchItem, 5);

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]))
            ->assertSessionHas('error');

        $this->assertNull($item->fresh()->short_closed_at);
    }

    public function test_a_dispatched_order_cannot_have_a_line_closed_short(): void
    {
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 5, 'dispatched');

        $this->actingAs($this->office())->post(route('orders.items.short', [$order, $item]))
            ->assertSessionHas('error');

        $this->assertNull($item->fresh()->short_closed_at);
    }
}
