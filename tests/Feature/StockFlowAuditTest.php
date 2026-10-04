<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-cutting audit of the money-and-stock spine: order entry → FIFO
 * reservation → picking → stock adjustments (undo, cancel, short close, line
 * edits, maturing hold) → invoicing.
 *
 * Deliberately driven through the real HTTP routes wherever an operator would
 * use one, so the controller guards are exercised alongside the model methods.
 * Existing suites cover each stage in isolation; this one checks the seams —
 * in particular that a unit of stock is never decremented twice, never lost,
 * and never billed for more than was actually supplied.
 */
class StockFlowAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $office;

    private Customer $shop;

    private Product $milk;

    private ProductVariant $bottle;

    private Product $cheese;

    private ProductVariant $wheel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->admin()->create();

        $this->shop = Customer::create([
            'name' => 'Audit Test Shop',
            'email' => 'audit@example.ie',
            'address' => '1 Main St',
            'city' => 'Birr',
            'postal_code' => 'R42 AB12',
            'country' => 'Ireland',
            'payment_terms' => 'net_30',
            'is_active' => true,
        ]);

        $this->milk = Product::create(['name' => 'Audit Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $this->milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'base_price' => 2.00,
            'is_active' => true,
        ]);

        $this->cheese = Product::create([
            'name' => 'Audit Cheese',
            'type' => 'cheese',
            'maturation_days' => 30,
            'is_active' => true,
        ]);
        $this->wheel = ProductVariant::create([
            'product_id' => $this->cheese->id,
            'name' => 'Whole Wheel',
            'size' => '4kg',
            'unit' => 'wheel',
            'weight_kg' => 4.000,
            'base_price' => 12.00,
            'is_variable_weight' => true,
            'is_priced_by_weight' => true,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------- fixtures

    private function stock(int $quantity, ?string $productionDate = null, ?ProductVariant $variant = null): BatchItem
    {
        $variant ??= $this->bottle;

        $batch = Batch::create([
            'product_id' => $variant->product_id,
            'production_date' => $productionDate ?? today()->subDays(2)->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);

        // Cheese batches get a ready_date from maturation_days; backdate it so
        // the stock is sellable today rather than blocked by isReadyToSell().
        if ($batch->ready_date && $batch->ready_date->isFuture()) {
            $batch->forceFill(['ready_date' => today()->subDay()->toDateString()])->save();
        }

        return BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $variant->id,
            'quantity_produced' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_weight_kg' => $variant->weight_kg,
        ]);
    }

    private function order(int $quantity, string $status = 'confirmed', ?ProductVariant $variant = null): Order
    {
        $variant ??= $this->bottle;

        $order = Order::create([
            'customer_id' => $this->shop->id,
            'order_date' => today()->toDateString(),
            'delivery_date' => today()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => $quantity,
            'unit_price' => $variant->base_price,
        ]);

        $order->calculateTotals();

        return $order->fresh('orderItems');
    }

    private function autoAllocate(Order $order): void
    {
        $this->actingAs($this->office)
            ->post(route('order-allocations.auto-allocate', $order))
            ->assertRedirect();
    }

    private function pick(Order $order, OrderItem $item, BatchItem $batchItem, int $quantity, ?float $weight = null): \Illuminate\Testing\TestResponse
    {
        $payload = ['batch_item_id' => $batchItem->id, 'quantity' => $quantity];
        if ($weight !== null) {
            // Per-unit lines post one weight per unit; spread the total evenly.
            $payload['weights'] = array_fill(0, $quantity, round($weight / $quantity, 3));
        }

        return $this->actingAs($this->office)
            ->post(route('picking.pick', [$order, $item]), $payload);
    }

    // --------------------------------------------------- 1. the happy path

    public function test_order_to_invoice_moves_each_unit_of_stock_exactly_once(): void
    {
        $batchItem = $this->stock(30);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        // Reservation stage: nothing has left the store yet.
        $this->autoAllocate($order);

        $item->refresh();
        $batchItem->refresh();

        $this->assertSame(10, $item->quantity_allocated, 'auto-allocate should reserve the whole line');
        $this->assertSame(0, $item->quantity_fulfilled);
        $this->assertSame(30, $batchItem->quantity_remaining, 'a reservation must not decrement stock');
        $this->assertSame(20, $batchItem->available_quantity, 'the reservation must reduce availability');
        $this->assertSame('preparing', $order->fresh()->status);

        // Picking stage: stock actually moves, once.
        $this->pick($order, $item, $batchItem, 10)->assertRedirect();

        $item->refresh();
        $batchItem->refresh();

        $this->assertSame(10, $item->quantity_fulfilled);
        $this->assertSame(20, $batchItem->quantity_remaining, 'picking decrements stock exactly once');
        $this->assertSame(20, $batchItem->available_quantity, 'a fulfilled reservation must not still hold stock');
        $this->assertSame(10, $batchItem->quantity_sold);
        $this->assertSame('ready', $order->fresh()->status);

        // Billing stage.
        $order->refresh();
        $this->assertTrue($order->isInvoiceable());
        $this->assertEquals(20.00, (float) $order->total_amount);

        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);

        $this->assertEquals(20.00, (float) $invoice->total);
        $this->assertSame(10, (int) $invoice->lines->first()->quantity);
        $this->assertEquals(20.00, (float) $invoice->lines->first()->amount);
        $this->assertSame(
            [$batchItem->batch->batch_code],
            $invoice->lines->first()->batch_codes,
            'the invoice line should carry batch traceability'
        );
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
    }

    // ------------------------------------------- 2. allocation correctness

    public function test_two_orders_cannot_reserve_the_same_stock(): void
    {
        $batchItem = $this->stock(10);

        $first = $this->order(10);
        $second = $this->order(10);

        $this->autoAllocate($first);
        $this->autoAllocate($second);

        $this->assertSame(10, $first->orderItems->first()->fresh()->quantity_allocated);
        $this->assertSame(0, $second->orderItems->first()->fresh()->quantity_allocated,
            'the second order must not be able to reserve stock already reserved');
        $this->assertSame(10, $batchItem->fresh()->quantity_remaining);
    }

    public function test_a_line_cannot_be_allocated_beyond_the_quantity_ordered(): void
    {
        $batchItem = $this->stock(50);
        $order = $this->order(5);
        $item = $order->orderItems->first();

        $this->actingAs($this->office)
            ->post(route('order-allocations.allocate', $item), [
                'batch_item_id' => $batchItem->id,
                'quantity' => 6,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, $item->fresh()->quantity_allocated);
    }

    public function test_expired_and_unready_stock_is_never_allocated(): void
    {
        $expired = $this->stock(10, today()->subDays(20)->toDateString());
        $expired->batch->forceFill(['expiry_date' => today()->toDateString()])->save();

        $order = $this->order(10);
        $this->autoAllocate($order);

        $this->assertSame(0, $order->orderItems->first()->fresh()->quantity_allocated,
            'a batch expiring today is already expired and must not be allocated');

        // A cheese batch still maturing is likewise off limits.
        $maturing = $this->stock(10, today()->toDateString(), $this->wheel);
        $maturing->batch->forceFill(['ready_date' => today()->addDays(30)->toDateString()])->save();

        $cheeseOrder = $this->order(2, 'confirmed', $this->wheel);
        $this->autoAllocate($cheeseOrder);

        $this->assertSame(0, $cheeseOrder->orderItems->first()->fresh()->quantity_allocated,
            'stock that has not reached its ready_date must not be allocated');
    }

    public function test_a_maturing_hold_removes_wheels_from_allocation(): void
    {
        $batchItem = $this->stock(10, today()->subDays(60)->toDateString(), $this->wheel);
        $batchItem->forceFill(['quantity_maturing' => 8])->save();

        $this->assertSame(2, $batchItem->fresh()->available_quantity);

        $order = $this->order(5, 'confirmed', $this->wheel);
        $this->autoAllocate($order);

        $this->assertSame(2, $order->orderItems->first()->fresh()->quantity_allocated,
            'only the un-held wheels should be reservable');
        $this->assertSame(10, $batchItem->fresh()->quantity_remaining);
    }

    /**
     * The reservation arithmetic in BatchItem::holdable_quantity subtracts the
     * FULL quantity_allocated of every allocation whose fulfilled_at is null.
     * On a PARTIALLY picked allocation, the picked units have already been
     * taken out of quantity_remaining, so they are subtracted a second time —
     * availability under-reports and stock that physically exists cannot be
     * reserved by anyone else.
     */
    public function test_availability_is_correct_after_a_partial_pick(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 4)->assertRedirect();

        $batchItem->refresh();

        $this->assertSame(16, $batchItem->quantity_remaining, '4 picked units left the store');
        $this->assertSame(6, $batchItem->orderAllocations()->whereNull('fulfilled_at')
            ->sum('quantity_allocated') - $batchItem->orderAllocations()->whereNull('fulfilled_at')
            ->sum('quantity_fulfilled'), 'the outstanding reservation is 6 units');

        // 16 physically remaining, 6 still reserved for this order ⇒ 10 free.
        $this->assertSame(10, $batchItem->available_quantity,
            'the already-picked units must not be subtracted twice');
    }

    public function test_fifo_draws_the_oldest_batch_first(): void
    {
        $oldest = $this->stock(3, today()->subDays(10)->toDateString());
        $middle = $this->stock(3, today()->subDays(5)->toDateString());
        $newest = $this->stock(10, today()->subDay()->toDateString());

        $order = $this->order(8);
        $this->autoAllocate($order);

        $item = $order->orderItems->first()->fresh();

        $this->assertSame(8, $item->quantity_allocated);
        $this->assertSame(3, (int) $item->orderAllocations()->where('batch_item_id', $oldest->id)->sum('quantity_allocated'));
        $this->assertSame(3, (int) $item->orderAllocations()->where('batch_item_id', $middle->id)->sum('quantity_allocated'));
        $this->assertSame(2, (int) $item->orderAllocations()->where('batch_item_id', $newest->id)->sum('quantity_allocated'));
    }

    // -------------------------------------------- 3. stock adjustments

    public function test_undoing_a_pick_returns_stock_and_keeps_the_reservation(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 10)->assertRedirect();

        $this->assertSame(10, $batchItem->fresh()->quantity_remaining);
        $this->assertSame('ready', $order->fresh()->status);

        $this->actingAs($this->office)
            ->post(route('picking.undo', [$order, $item]))
            ->assertRedirect();

        $item->refresh();
        $batchItem->refresh();

        $this->assertSame(0, $item->quantity_fulfilled);
        $this->assertSame(20, $batchItem->quantity_remaining, 'undo must restore the batch');
        $this->assertSame(10, $item->quantity_allocated, 'the reservation survives an undo');
        $this->assertSame(10, $batchItem->available_quantity, 'the restored units are still reserved for this order');
        $this->assertSame('preparing', $order->fresh()->status, 'the order drops back out of ready');
    }

    public function test_cancelling_an_order_returns_both_reserved_and_picked_stock(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 6)->assertRedirect();

        $this->assertSame(14, $batchItem->fresh()->quantity_remaining);

        $this->actingAs($this->office)
            ->patch(route('orders.update', $order), [
                'status' => 'cancelled',
                'order_date' => $order->order_date->toDateString(),
            ])
            ->assertRedirect();

        $item->refresh();
        $batchItem->refresh();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(20, $batchItem->quantity_remaining, 'all picked stock returns to the batch');
        $this->assertSame(20, $batchItem->available_quantity, 'and no reservation is left behind');
        $this->assertSame(0, $item->quantity_allocated);
        $this->assertSame(0, $item->quantity_fulfilled);
        $this->assertSame(0, $item->orderAllocations()->count());
    }

    public function test_reducing_a_line_quantity_releases_the_surplus_stock(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 8)->assertRedirect();

        $this->assertSame(12, $batchItem->fresh()->quantity_remaining);

        // Office trims the line to 4 after 8 were already picked.
        $this->actingAs($this->office)
            ->patch(route('orders.items.update', [$order, $item]), ['quantity' => 4])
            ->assertRedirect();

        $item->refresh();
        $batchItem->refresh();

        $this->assertSame(4, $item->quantity_ordered);
        $this->assertSame(4, $item->quantity_fulfilled, 'fulfilment is trimmed to the new quantity');
        $this->assertSame(4, $item->quantity_allocated);
        $this->assertSame(16, $batchItem->quantity_remaining, 'the 4 surplus units go back to stock');
        $this->assertEquals(8.00, (float) $order->fresh()->total_amount);
    }

    public function test_removing_a_line_returns_all_of_its_stock(): void
    {
        $milkStock = $this->stock(20);
        $cheeseStock = $this->stock(10, today()->subDays(60)->toDateString(), $this->wheel);

        $order = $this->order(10);
        $item = $order->orderItems->first();

        // A second line, so removing the first is a real removal rather than
        // the last-line case (which cancels the order instead).
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $this->wheel->id,
            'quantity_ordered' => 2,
            'unit_price' => 12.00,
        ]);
        $order->refresh();

        $this->autoAllocate($order);
        $this->pick($order, $item, $milkStock, 5)->assertRedirect();

        $this->actingAs($this->office)
            ->delete(route('orders.items.destroy', [$order, $item]))
            ->assertRedirect();

        $this->assertSame(20, $milkStock->fresh()->quantity_remaining,
            'deleting a line must not strand its picked stock');
        $this->assertSame(20, $milkStock->fresh()->available_quantity);
        $this->assertSame(1, $order->fresh()->orderItems()->count());
        $this->assertSame(2, (int) $cheeseStock->fresh()->orderAllocations()->sum('quantity_allocated'),
            'the surviving line keeps its own reservation');
    }

    /**
     * Documented behaviour: an order may not go empty, so removing its only
     * line cancels the order and keeps the line as history — while still
     * handing every committed unit back to its batch.
     */
    public function test_removing_the_only_line_cancels_the_order_and_returns_its_stock(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 5)->assertRedirect();

        $this->actingAs($this->office)
            ->delete(route('orders.items.destroy', [$order, $item]))
            ->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(1, $order->fresh()->orderItems()->count(), 'the line is kept as history');
        $this->assertSame(20, $batchItem->fresh()->quantity_remaining);
        $this->assertSame(20, $batchItem->fresh()->available_quantity);
        $this->assertSame(0, $item->fresh()->quantity_allocated);
        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
    }

    /**
     * The short close itself is correct — the unpicked reservation is dropped,
     * the picked units stay picked, and only what was supplied is billed.
     *
     * What is NOT correct is the availability left behind. releaseUnits()'
     * pass 1 lowers quantity_allocated to match quantity_fulfilled but never
     * stamps fulfilled_at, so holdable_quantity still counts the whole
     * allocation as an outstanding reservation. Those picked units are then
     * subtracted from availability a second time — permanently, because
     * nothing later clears the stamp on a short-closed line.
     */
    public function test_short_closing_releases_the_reservation_but_keeps_picked_stock(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 6)->assertRedirect();

        $this->actingAs($this->office)
            ->post(route('orders.items.short', [$order, $item]), ['short_reason' => 'Ran out'])
            ->assertRedirect();

        $item->refresh();
        $batchItem->refresh();

        $this->assertTrue($item->isShortClosed());
        $this->assertSame(6, $item->quantity_fulfilled, 'picked units stay picked');
        $this->assertSame(6, $item->quantity_allocated, 'the unpicked reservation is released');
        $this->assertSame(4, $item->quantity_short);
        $this->assertSame(14, $batchItem->quantity_remaining, 'only the 6 picked units left the store');
        $this->assertSame(14, $batchItem->available_quantity, 'the released 4 are free for other orders');

        // A short line bills only what was supplied.
        $order->refresh();
        $this->assertSame('ready', $order->status);
        $this->assertEquals(12.00, (float) $order->total_amount);

        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);
        $line = $invoice->lines->first();

        $this->assertSame(6, (int) $line->quantity, 'the invoice bills the supplied quantity');
        $this->assertEquals(12.00, (float) $line->amount);
        $this->assertEquals(12.00, (float) $invoice->total);
        $this->assertStringContainsString('not supplied', (string) $line->detail);
    }

    public function test_a_never_picked_short_line_is_billed_at_zero(): void
    {
        $this->stock(0);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->actingAs($this->office)
            ->post(route('orders.items.short', [$order, $item]))
            ->assertRedirect();

        $order->refresh();
        $order->update(['status' => 'ready']);

        $this->assertEquals(0.00, (float) $order->fresh()->total_amount);

        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);

        $this->assertEquals(0.00, (float) $invoice->total,
            'nothing supplied must bill nothing, not the ordered estimate');
        $this->assertSame(0, (int) $invoice->lines->first()->quantity);
    }

    // --------------------------------------------------- 4. invoicing

    public function test_a_weight_priced_line_bills_the_recorded_weight(): void
    {
        $batchItem = $this->stock(10, today()->subDays(60)->toDateString(), $this->wheel);
        $order = $this->order(2, 'confirmed', $this->wheel);
        $item = $order->orderItems->first();

        // Estimate before picking: 2 wheels × 4.000 kg × €12.00 = €96.00.
        $this->assertEquals(96.00, (float) $item->line_total);

        $this->autoAllocate($order);
        // Actual wheels come in light: 7.500 kg for the pair.
        $this->pick($order, $item, $batchItem, 2, 7.500)->assertRedirect();

        $item->refresh();
        $this->assertEquals(7.500, (float) $item->weight_fulfilled_kg);
        $this->assertEquals(90.00, (float) $item->fulfilled_total, '7.5 kg × €12.00');
        $this->assertEquals(90.00, (float) $order->fresh()->total_amount,
            'the order must bill the weighed value, not the estimate');

        $order->refresh();
        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);
        $line = $invoice->lines->first();

        $this->assertEquals(90.00, (float) $line->amount);
        $this->assertStringContainsString('7.500 kg', (string) $line->detail);
        $this->assertEquals(90.00, (float) $invoice->total);
        $this->assertSame(8, $batchItem->fresh()->quantity_remaining);
    }

    public function test_the_delivery_charge_vat_is_backed_out_of_the_gross(): void
    {
        $batchItem = $this->stock(10);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 10)->assertRedirect();

        $this->actingAs($this->office)
            ->patch(route('orders.update', $order), [
                'status' => 'ready',
                'order_date' => $order->order_date->toDateString(),
                'delivery_charge' => 12.30,
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertEquals(20.00, (float) $order->subtotal);
        $this->assertEquals(12.30, (float) $order->delivery_charge, 'the gross charge is what was entered');
        $this->assertEquals(10.00, (float) $order->delivery_charge_net, '12.30 / 1.23');
        $this->assertEquals(2.30, (float) $order->tax_amount);
        $this->assertEquals(32.30, (float) $order->total_amount, 'net + vat must equal the entered gross');

        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);

        $this->assertEquals(20.00, (float) $invoice->subtotal);
        $this->assertEquals(10.00, (float) $invoice->delivery_charge_net);
        $this->assertEquals(2.30, (float) $invoice->tax_amount);
        $this->assertEquals(32.30, (float) $invoice->total);
    }

    public function test_an_issued_invoice_is_a_snapshot_and_locks_the_order(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 10)->assertRedirect();

        $order->refresh();
        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);

        $this->assertEquals(20.00, (float) $invoice->total);

        // Editing an invoiced order must be refused, not silently applied.
        $this->actingAs($this->office)
            ->patch(route('orders.items.update', [$order, $item]), ['quantity' => 4])
            ->assertRedirect();

        $this->assertSame(10, $item->fresh()->quantity_ordered, 'an invoiced order must not be editable');
        $this->assertEquals(20.00, (float) $invoice->fresh()->total);

        // A second invoice for the same order must be impossible.
        $this->expectException(\App\Services\InvoicingException::class);
        app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);
    }

    public function test_voiding_an_invoice_returns_the_order_to_the_uninvoiced_queue(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 10)->assertRedirect();

        $order->refresh();
        $order->update(['status' => 'delivered']);
        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);
        $number = $invoice->number;

        app(InvoiceService::class)->void($invoice, 'Wrong customer', $this->office);

        $order->refresh();
        $this->assertNull($order->invoice_id);
        $this->assertTrue($order->isInvoiceable());
        $this->assertSame('void', $invoice->fresh()->status->value);

        // Re-issuing mints a NEW number — a void must not recycle one.
        $reissued = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);
        $this->assertNotSame($number, $reissued->number);
        $this->assertEquals(20.00, (float) $reissued->total);

        // Stock is untouched by any of this.
        $this->assertSame(10, $batchItem->fresh()->quantity_remaining);
    }

    public function test_invoice_total_equals_the_sum_of_its_lines(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(7);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 7)->assertRedirect();

        $order->refresh();
        $order->forceFill(['delivery_charge' => 6.15])->save();
        $order->calculateTotals();
        $order->update(['status' => 'delivered']);

        $invoice = app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);

        $lineSum = round($invoice->lines->sum(fn ($line) => (float) $line->amount + (float) $line->vat_amount), 2);

        $this->assertEquals((float) $invoice->total, $lineSum,
            'the document total must be the sum of what it actually prints');
    }

    // ------------------------------------- 5. integrity of the audit trail

    /**
     * order_allocations.batch_item_id and batch_items.batch_id are both
     * ON DELETE CASCADE, so without a guard in BatchController::destroy()
     * deleting a batch an order was picked from removed the allocations
     * underneath it — order_items.quantity_fulfilled survived while the record
     * of where those units came from did not.
     */
    public function test_a_batch_with_picked_allocations_cannot_be_deleted(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);
        $item = $order->orderItems->first();

        $this->autoAllocate($order);
        $this->pick($order, $item, $batchItem, 10)->assertRedirect();

        $order->refresh();
        $order->update(['status' => 'delivered']);
        app(InvoiceService::class)->issueForOrder($order->fresh(), $this->office);

        $batchId = $batchItem->batch_id;
        $batchCode = $batchItem->batch->batch_code;

        $this->assertSame(1, $item->orderAllocations()->count());
        $this->assertSame([$batchCode], $item->fresh()->batch_codes);

        $this->actingAs($this->office)
            ->delete(route('batches.destroy', $batchId))
            ->assertRedirect(route('batches.show', $batchId))
            ->assertSessionHas('error', fn ($message) => str_contains($message, $batchCode)
                && str_contains($message, 'order allocation'));

        $item->refresh();

        $this->assertDatabaseHas('batches', ['id' => $batchId]);
        $this->assertSame(1, $item->orderAllocations()->count(), 'the allocation must survive');
        $this->assertSame(10, $item->quantity_fulfilled);
        $this->assertSame([$batchCode], $item->batch_codes, 'traceability must survive');
        $this->assertSame(10, $batchItem->fresh()->quantity_remaining);
    }

    /**
     * The guard must not be so broad that a genuine mistake can't be undone:
     * a batch nothing depends on is still deletable.
     */
    public function test_a_batch_with_no_history_is_still_deletable(): void
    {
        $batchItem = $this->stock(20);
        $batchId = $batchItem->batch_id;

        $this->actingAs($this->office)
            ->delete(route('batches.destroy', $batchId))
            ->assertRedirect(route('batches.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('batches', ['id' => $batchId]);
        $this->assertDatabaseMissing('batch_items', ['id' => $batchItem->id]);
    }

    /**
     * A reservation that was never picked is still order history worth
     * protecting — the office needs to know the batch was promised out.
     */
    public function test_a_batch_with_only_a_reservation_cannot_be_deleted(): void
    {
        $batchItem = $this->stock(20);
        $order = $this->order(10);

        $this->autoAllocate($order);

        $this->assertSame(0, $order->orderItems->first()->fresh()->quantity_fulfilled);

        $this->actingAs($this->office)
            ->delete(route('batches.destroy', $batchItem->batch_id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('batches', ['id' => $batchItem->batch_id]);
    }
}
