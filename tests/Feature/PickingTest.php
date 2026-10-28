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
 * Mobile picking flow (/picking) — the factory write carve-out. Factory users
 * may pick (allocate + fulfil + undo via OrderPolicy::fulfill) but keep no
 * general order write access; drivers are denied at the middleware. The pick
 * action is transactional: it fulfils an existing office reservation on the
 * chosen batch first, then tops up with a fresh allocation.
 */
class PickingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->product = Product::create(['name' => 'Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
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

    private function orderWithItem(ProductVariant $variant, int $qty, string $status = 'confirmed'): array
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

    // ── Access control ──────────────────────────────────────────────

    public function test_factory_can_view_the_picking_screens(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 3);
        $this->batchItem($variant);

        $this->actingAs($factory)->get(route('picking.index'))
            ->assertOk()->assertSee('Kerry Organic');
        $this->actingAs($factory)->get(route('picking.show', $order))->assertOk();
        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))->assertOk();
    }

    public function test_driver_is_denied_everywhere_on_picking(): void
    {
        $driver = User::factory()->driver()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 3);
        $batchItem = $this->batchItem($variant);

        $this->actingAs($driver)->get(route('picking.index'))->assertForbidden();
        $this->actingAs($driver)->get(route('picking.show', $order))->assertForbidden();
        $this->actingAs($driver)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id, 'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_factory_still_cannot_use_office_write_routes(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 3);
        $batchItem = $this->batchItem($variant);

        // Office allocation routes are role-gated to admin/office.
        $this->actingAs($factory)->post(route('order-allocations.allocate', $item), [
            'batch_item_id' => $batchItem->id, 'quantity' => 1,
        ])->assertForbidden();

        // Order item mutation requires the (office-only) update ability.
        $this->actingAs($factory)->post(route('orders.items.store', $order), [
            'product_variant_id' => $variant->id, 'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_factory_lands_on_picking_queue_after_login(): void
    {
        $factory = User::factory()->factoryWorker()->create(['password' => bcrypt('secret123')]);

        $this->post(route('login'), ['login' => $factory->username, 'password' => 'secret123'])
            ->assertRedirect(route('picking.index', absolute: false));
    }

    // ── The pick action ─────────────────────────────────────────────

    public function test_pick_allocates_and_fulfils_in_one_step(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 3);
        $batchItem = $this->batchItem($variant, 10);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 3,
        ])->assertRedirect(route('picking.show', $order));

        $item->refresh();
        $this->assertSame(3, $item->quantity_allocated);
        $this->assertSame(3, $item->quantity_fulfilled);
        $this->assertSame(7, (int) $batchItem->fresh()->quantity_remaining);
        // Full pick of the only line: confirmed → preparing → ready.
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_partial_pick_keeps_order_preparing_and_returns_to_the_line(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 5);
        $batchItem = $this->batchItem($variant, 10);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 2,
        ])->assertRedirect(route('picking.item', [$order, $item]));

        $this->assertSame(2, $item->fresh()->quantity_fulfilled);
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_pick_fulfils_existing_office_allocation_without_duplicating(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 4, 'preparing');
        $batchItem = $this->batchItem($variant, 10);

        // Office pre-allocated the full line to this batch.
        $item->allocateFromBatchItem($batchItem, 4);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 4,
        ])->assertRedirect(route('picking.show', $order));

        $item->refresh();
        $this->assertSame(1, $item->orderAllocations()->count());
        $this->assertSame(4, $item->quantity_fulfilled);
        $this->assertSame(6, (int) $batchItem->fresh()->quantity_remaining);
    }

    public function test_pick_from_a_different_batch_than_the_reservation_fails_cleanly(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 4, 'preparing');
        $reservedBatch = $this->batchItem($variant, 10);
        $otherBatch = $this->batchItem($variant, 10);

        // Fully reserved on batch A → picking batch B would over-allocate.
        $item->allocateFromBatchItem($reservedBatch, 4);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $otherBatch->id,
            'quantity' => 4,
        ])->assertRedirect(route('picking.item', [$order, $item]))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
        $this->assertSame(10, (int) $otherBatch->fresh()->quantity_remaining);
    }

    public function test_pick_exceeding_batch_stock_changes_nothing(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 8);
        $batchItem = $this->batchItem($variant, 4);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 8,
        ])->assertSessionHasErrors('quantity');

        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
        $this->assertSame(0, $item->fresh()->quantity_allocated);
        $this->assertSame(4, (int) $batchItem->fresh()->quantity_remaining);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    // ── Variable weight ─────────────────────────────────────────────

    public function test_variable_weight_pick_requires_a_weight(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $wheel = $this->variant([
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
            'weight_kg' => 2.5, 'base_price' => 14.00,
        ]);
        [$order, $item] = $this->orderWithItem($wheel, 2);
        $batchItem = $this->batchItem($wheel, 5);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 2,
        ])->assertSessionHasErrors('weights');

        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
    }

    public function test_a_refused_weight_keeps_the_weights_already_typed(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $wheel = $this->variant([
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
            'weight_kg' => 2.5, 'base_price' => 14.00,
        ]);
        [$order, $item] = $this->orderWithItem($wheel, 2);
        $batchItem = $this->batchItem($wheel, 5);
        // Batch::boot() sets a cheese ready_date 90 days out; the pick form renders for ready stock only.
        $batchItem->batch->update(['ready_date' => now()->subDay()]);

        $this->actingAs($factory)
            ->from(route('picking.item', [$order, $item]))
            ->post(route('picking.pick', [$order, $item]), [
                'batch_item_id' => $batchItem->id,
                'quantity' => 2,
                'weights' => ['5.1', '52'],
            ])
            ->assertRedirect(route('picking.item', [$order, $item]))
            ->assertSessionHasInput('weights');

        // The form's Alpine state starts from old(), so the inputs refill.
        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))
            ->assertOk()
            ->assertSee('Check the decimal point')
            ->assertSeeInOrder(['weights:', '5.1', '52', 'bulkWeight:'], false);
        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
    }

    public function test_the_pick_screen_names_the_real_batch_stock(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 2);
        // Batch::boot() sets a cheese ready_date 90 days out; the chooser lists ready stock only.
        $this->batchItem($variant, 23)->batch->update(['ready_date' => now()->subDay()]);

        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))
            ->assertOk()
            ->assertSee('23 in stock')
            ->assertSee('in this batch')
            ->assertSee('oldest first')
            ->assertDontSee('pickable')
            ->assertDontSee('(FIFO)');
    }

    public function test_variable_weight_pick_records_weight_and_prices_by_it(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $wheel = $this->variant([
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
            'weight_kg' => 2.5, 'base_price' => 14.00, // €/kg
        ]);
        [$order, $item] = $this->orderWithItem($wheel, 2);
        $batchItem = $this->batchItem($wheel, 5);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 2,
            'weights' => [2.500, 2.700],
        ])->assertRedirect(route('picking.show', $order));

        $item->refresh();
        $this->assertSame('5.200', (string) $item->weight_fulfilled_kg);
        $this->assertSame('72.80', (string) $item->fulfilled_total); // 5.2kg × €14
        $this->assertSame('72.80', (string) $order->fresh()->total_amount);
    }

    public function test_pick_widens_a_partial_reservation_on_the_same_row(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $wheel = $this->variant([
            'is_variable_weight' => true, 'is_bulk_weighed' => false, 'is_priced_by_weight' => true,
            'weight_kg' => 1.0, 'base_price' => 10.00,
        ]);
        [$order, $item] = $this->orderWithItem($wheel, 3, 'preparing');
        $batchItem = $this->batchItem($wheel, 10);

        // Office reserved 2 of 3; the picker picks all 3 from the same batch.
        // order_allocations is unique per (item, batch), so the reservation row
        // is widened rather than duplicated.
        $item->allocateFromBatchItem($batchItem, 2);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id,
            'quantity' => 3,
            'weights' => [1.100, 1.100, 1.100],
        ])->assertRedirect(route('picking.show', $order));

        $item->refresh();
        $this->assertSame(3, $item->quantity_fulfilled);
        $this->assertSame(1, $item->orderAllocations()->count());
        $this->assertSame(3, (int) $item->orderAllocations()->first()->quantity_allocated);
        $this->assertSame('3.300', (string) $item->weight_fulfilled_kg);
        $this->assertSame(7, (int) $batchItem->fresh()->quantity_remaining);
    }

    // ── Undo ────────────────────────────────────────────────────────

    public function test_undo_restores_stock_and_drops_ready_back_to_preparing(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 3);
        $batchItem = $this->batchItem($variant, 10);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id, 'quantity' => 3,
        ]);
        $this->assertSame('ready', $order->fresh()->status);

        $this->actingAs($factory)->post(route('picking.undo', [$order, $item]))
            ->assertRedirect(route('picking.item', [$order, $item]));

        $item->refresh();
        $this->assertSame(0, $item->quantity_fulfilled);
        $this->assertSame(3, $item->quantity_allocated); // reservation survives the undo
        $this->assertSame(10, (int) $batchItem->fresh()->quantity_remaining);
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_ready_order_renders_celebration_and_done_line_offers_undo(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 2);
        $batchItem = $this->batchItem($variant, 5);

        $this->actingAs($factory)->post(route('picking.pick', [$order, $item]), [
            'batch_item_id' => $batchItem->id, 'quantity' => 2,
        ]);

        // Overview renders the celebration variant once fully fulfilled…
        $this->actingAs($factory)->get(route('picking.show', $order))
            ->assertOk()->assertSee('Order ready.');

        // …a ready order still shows on the queue…
        $this->actingAs($factory)->get(route('picking.index'))
            ->assertOk()->assertSee($order->order_number);

        // …and the done line's item screen shows the picked summary + undo.
        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))
            ->assertOk()->assertSee('Undo last pick');
    }

    // ── Queue scoping & financial redaction ─────────────────────────

    public function test_queue_only_lists_picking_statuses(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$confirmed] = $this->orderWithItem($variant, 1, 'confirmed');
        [$pending] = $this->orderWithItem($variant, 1, 'pending');
        [$dispatched] = $this->orderWithItem($variant, 1, 'dispatched');

        $response = $this->actingAs($factory)->get(route('picking.index'))->assertOk();
        $response->assertSee($confirmed->order_number);
        $response->assertDontSee($pending->order_number);
        $response->assertDontSee($dispatched->order_number);
    }

    private function orderFor($deliveryDate, int $qty, string $status = 'confirmed'): Order
    {
        [$order] = $this->orderWithItem($this->variant(), $qty, $status);
        $order->update(['delivery_date' => $deliveryDate]);

        return $order;
    }

    public function test_queue_shows_one_day_and_parks_old_ready_orders(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $tuesday = today()->next('Tuesday');
        $july = $this->orderFor(today()->subMonths(3), 4, 'ready');
        $a = $this->orderFor($tuesday, 3);
        $b = $this->orderFor($tuesday, 5);
        $later = $this->orderFor($tuesday->copy()->addDays(2), 7);

        $response = $this->actingAs($factory)->get(route('picking.index'))->assertOk();
        $response->assertViewHas('selected', $tuesday->toDateString());
        $response->assertViewHas('totalUnits', 8);
        $response->assertSee($a->order_number);
        $response->assertSee($b->order_number);
        $response->assertDontSee($later->order_number);
        // The July order is in the stale bucket, not a selectable day.
        $response->assertViewHas('stale', fn ($stale) => $stale->pluck('id')->all() === [$july->id]);
        $response->assertSeeInOrder(['Ready, awaiting dispatch — 1 order from earlier days', $july->order_number]);
        $response->assertSee(route('picking.index', ['date' => $later->delivery_date->toDateString()]), false);

        // ?date= switches days.
        $this->actingAs($factory)->get(route('picking.index', ['date' => $later->delivery_date->toDateString()]))
            ->assertOk()
            ->assertViewHas('totalUnits', 7)
            ->assertSee($later->order_number)
            ->assertDontSee($a->order_number);
    }

    public function test_a_past_order_still_to_pick_stays_a_selectable_day(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $past = today()->subDays(5);
        $late = $this->orderFor($past, 2, 'preparing');
        $this->orderFor(today()->addDay(), 1);

        // Default goes forward to tomorrow…
        $this->actingAs($factory)->get(route('picking.index'))
            ->assertOk()
            ->assertViewHas('selected', today()->addDay()->toDateString())
            ->assertViewHas('stale', fn ($stale) => $stale->isEmpty())
            ->assertSee(route('picking.index', ['date' => $past->toDateString()]), false);

        // …and the late preparing order is one click back.
        $this->actingAs($factory)->get(route('picking.index', ['date' => $past->toDateString()]))
            ->assertOk()
            ->assertSee($late->order_number);
    }

    /** A finished order today: its line fully picked, status ready. */
    private function finishedOrderFor($deliveryDate): Order
    {
        $order = $this->orderFor($deliveryDate, 2, 'ready');
        $order->orderItems()->update(['quantity_fulfilled' => 2]);

        return $order->fresh();
    }

    public function test_next_up_never_points_at_an_order_past_its_date(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $done = $this->finishedOrderFor(today());
        $this->orderFor(today()->subMonths(3), 4);          // July, still confirmed
        $tomorrow = $this->orderFor(today()->addDay(), 1);

        $this->actingAs($factory)->get(route('picking.show', $done))
            ->assertOk()
            ->assertViewHas('nextOrder', fn ($next) => $next?->id === $tomorrow->id)
            ->assertDontSee('Past its delivery date');

        $tomorrow->update(['status' => 'cancelled']);
        $this->actingAs($factory)->get(route('picking.show', $done))
            ->assertOk()
            ->assertViewHas('nextOrder', null)
            ->assertDontSee('Next up');
    }

    public function test_a_past_day_says_so_and_an_untouched_day_says_start(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $past = today()->subDays(5);
        $this->orderFor($past, 2);
        $this->orderFor(today(), 1);

        $this->actingAs($factory)->get(route('picking.index', ['date' => $past->toDateString()]))
            ->assertOk()
            ->assertSee('Past its delivery date');

        $this->actingAs($factory)->get(route('picking.index'))
            ->assertOk()
            ->assertViewHas('selected', today()->toDateString())
            ->assertDontSee('Past its delivery date')
            ->assertSee('Start picking')
            ->assertDontSee('Continue picking');
    }

    public function test_queue_defaults_to_today_when_today_has_work(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $today = $this->orderFor(today(), 1);
        $this->orderFor(today()->addDay(), 1);

        $this->actingAs($factory)->get(route('picking.index'))
            ->assertOk()
            ->assertViewHas('selected', today()->toDateString())
            ->assertSee($today->order_number);
    }

    public function test_skip_walks_forward_and_wraps(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        [$order, $one] = $this->orderWithItem($this->variant(), 1);
        $two = $order->orderItems()->create(['product_variant_id' => $this->variant()->id, 'quantity_ordered' => 1, 'unit_price' => 10]);
        $three = $order->orderItems()->create(['product_variant_id' => $this->variant()->id, 'quantity_ordered' => 1, 'unit_price' => 10]);

        $this->actingAs($factory)->get(route('picking.item', [$order, $two]))
            ->assertOk()
            ->assertViewHas('skipTo', fn ($item) => $item->id === $three->id);

        $this->actingAs($factory)->get(route('picking.item', [$order, $three]))
            ->assertOk()
            ->assertViewHas('skipTo', fn ($item) => $item->id === $one->id);
    }

    public function test_stepper_buttons_disable_at_their_limits(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order, $item] = $this->orderWithItem($variant, 3);
        // Matured, so the stepper (not the no-stock dead end) renders.
        $this->batchItem($variant)->batch->forceFill(['ready_date' => now()->subDay()])->saveQuietly();

        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))
            ->assertOk()
            ->assertSee(':disabled="qty >= maxQty"', false)
            ->assertSee(':disabled="qty <= 1"', false);
    }

    public function test_a_zero_nominal_weight_gives_no_check_threshold(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant(['weight_kg' => 0, 'is_variable_weight' => true]);
        [$order, $item] = $this->orderWithItem($variant, 1);
        $this->batchItem($variant)->batch->forceFill(['ready_date' => now()->subDay()])->saveQuietly();

        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))
            ->assertOk()
            ->assertSee('weigh each one')
            ->assertDontSee('Check</span>', false)
            ->assertDontSee('expected ~');
    }

    public function test_orders_outside_the_queue_bounce_back_to_index(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $variant = $this->variant();
        [$order] = $this->orderWithItem($variant, 1, 'dispatched');

        $this->actingAs($factory)->get(route('picking.show', $order))
            ->assertRedirect(route('picking.index'));
    }

    public function test_factory_sees_no_euro_amounts_office_does(): void
    {
        $variant = $this->variant(['base_price' => 12.34]);
        [$order, $item] = $this->orderWithItem($variant, 2);
        $this->batchItem($variant, 5);

        $factory = User::factory()->factoryWorker()->create();
        $this->actingAs($factory)->get(route('picking.item', [$order, $item]))
            ->assertOk()->assertDontSee('€');

        $office = User::factory()->create();
        $this->actingAs($office)->get(route('picking.item', [$order, $item]))
            ->assertOk()->assertSee('€');
    }
}
