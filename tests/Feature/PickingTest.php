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
        ])->assertSessionHasErrors('actual_weight_kg');

        $this->assertSame(0, $item->fresh()->quantity_fulfilled);
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
            'actual_weight_kg' => 5.200,
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
            'actual_weight_kg' => 3.300,
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
