<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chilled run sheet (/chilled-runs) + delivery run management (/delivery-runs).
 * The sheet is readable by admin/office/factory; the loaded tick is the narrow
 * OrderPolicy::load carve-out (factory can tick, can't edit orders). Run
 * management is office/admin only. No € appears anywhere on the sheet.
 */
class ChilledRunTest extends TestCase
{
    use RefreshDatabase;

    private Product $milk;

    private Product $yoghurt;

    private Product $cheese;

    protected function setUp(): void
    {
        parent::setUp();
        $this->milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->yoghurt = Product::create(['name' => 'Organic Yoghurt', 'type' => 'yoghurt', 'is_active' => true]);
        $this->cheese = Product::create(['name' => 'Farmhouse Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
    }

    private function variant(Product $product, string $name, string $size, ?int $caseSize = null): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => $name,
            'size' => $size,
            'unit' => 'unit',
            'weight_kg' => 1.0,
            'base_price' => 2.50,
            'case_size' => $caseSize,
            'is_active' => true,
        ]);
    }

    private function makeRun(array $attrs = []): DeliveryRun
    {
        return DeliveryRun::create(array_merge([
            'name' => 'Dublin',
            'day_of_week' => CarbonImmutable::now()->isoWeekday(), // today, so dateFor() = today
            'driver' => 'Stuart',
            'is_active' => true,
        ], $attrs));
    }

    private function customerOnRun(DeliveryRun $run, string $name, ?int $position = 1): Customer
    {
        return Customer::create([
            'name' => $name,
            'email' => uniqid().'@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'is_active' => true,
            'delivery_run_id' => $run->id,
            'run_position' => $position,
        ]);
    }

    private function orderFor(Customer $customer, array $lines, string $status = 'confirmed', ?string $deliveryDate = null): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => $deliveryDate ?? now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        foreach ($lines as [$variant, $qty]) {
            $order->orderItems()->create([
                'product_variant_id' => $variant->id,
                'quantity_ordered' => $qty,
                'unit_price' => $variant->base_price,
            ]);
        }

        return $order;
    }

    // ── Access control ───────────────────────────────────────────────

    public function test_admin_office_and_factory_can_view_the_run_sheet(): void
    {
        $this->makeRun();

        foreach ([User::factory()->admin(), User::factory(), User::factory()->factoryWorker()] as $factory) {
            $this->actingAs($factory->create())
                ->get(route('chilled-runs.index'))
                ->assertOk();
        }
    }

    public function test_driver_cannot_view_the_run_sheet(): void
    {
        $this->makeRun();

        $this->actingAs(User::factory()->driver()->create())
            ->get(route('chilled-runs.index'))
            ->assertForbidden();
    }

    public function test_factory_cannot_access_run_management(): void
    {
        $run = $this->makeRun();
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)->get(route('delivery-runs.index'))->assertForbidden();
        $this->actingAs($factory)->get(route('delivery-runs.create'))->assertForbidden();
        $this->actingAs($factory)->put(route('delivery-runs.update', $run), ['name' => 'X'])->assertForbidden();
    }

    public function test_office_can_manage_runs(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)->get(route('delivery-runs.index'))->assertOk();

        $this->actingAs($office)->post(route('delivery-runs.store'), [
            'name' => 'Midlands',
            'day_of_week' => null,
            'driver' => 'Midland Fine Foods',
        ])->assertRedirect(route('delivery-runs.index'));

        $this->assertDatabaseHas('delivery_runs', ['name' => 'Midlands', 'driver' => 'Midland Fine Foods']);
    }

    // ── Sheet contents ───────────────────────────────────────────────

    public function test_stops_render_in_run_position_order(): void
    {
        $run = $this->makeRun();
        $this->customerOnRun($run, 'Third Stop', 3);
        $this->customerOnRun($run, 'First Stop', 1);
        $this->customerOnRun($run, 'Second Stop', 2);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk();

        $body = $response->getContent();
        $this->assertTrue(
            strpos($body, 'First Stop') < strpos($body, 'Second Stop')
            && strpos($body, 'Second Stop') < strpos($body, 'Third Stop'),
            'Stops should render in run_position order'
        );
    }

    public function test_customer_without_an_order_shows_no_order_tag(): void
    {
        $run = $this->makeRun();
        $this->customerOnRun($run, 'Quiet Shop');

        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('Quiet Shop')
            ->assertSee('No order this week');
    }

    public function test_inactive_customer_is_excluded_and_cancelled_orders_do_not_count(): void
    {
        $run = $this->makeRun();
        $inactive = $this->customerOnRun($run, 'Closed Shop', 1);
        $inactive->update(['is_active' => false]);

        $cancelledCust = $this->customerOnRun($run, 'Cancelled Shop', 2);
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $this->orderFor($cancelledCust, [[$oneL, 10]], 'cancelled');

        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertDontSee('Closed Shop')
            ->assertSee('Cancelled Shop')
            ->assertSee('No order this week');
    }

    public function test_all_active_milk_yog_columns_render_and_cheese_becomes_a_column(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Corner Shop');

        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $this->variant($this->milk, '2L Bottle', '2L', 8); // unordered but active — still a column (entry target)
        $y500 = $this->variant($this->yoghurt, '500g Tub', '500g', 6);
        $vacPack = $this->variant($this->cheese, 'Vacuum Pack', 'pack');

        $this->orderFor($shop, [[$oneL, 30], [$y500, 12], [$vacPack, 15]]);

        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('1L')
            ->assertSee('2L')
            ->assertSee('500g')
            ->assertSee('pack')                              // cheese column header (variant size)
            ->assertSee('>15<', false)                       // cheese qty as a cell
            ->assertDontSee('15 × Farmhouse Cheese');        // no longer summarised into the notes column
    }

    public function test_crate_totals_use_case_size(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Crate Shop');

        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $this->orderFor($shop, [[$oneL, 30]]); // 30 = 1 crate of 16 + 14 extra

        $response = $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('Total units')
            ->assertSee('Blue crates')
            ->assertSee('Extra units outside crate')
            ->assertSee('1 crates total');

        $body = $response->getContent();
        $this->assertStringContainsString('>30<', $body);  // total units
        $this->assertStringContainsString('>14<', $body);  // extra outside crate
    }

    public function test_multiple_same_day_orders_for_one_customer_sum_in_the_cell(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Busy Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);

        $this->orderFor($shop, [[$oneL, 10]]);
        $this->orderFor($shop, [[$oneL, 5]]);

        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('>15<', false);
    }

    public function test_no_euro_amounts_anywhere_on_the_sheet(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Corner Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $this->orderFor($shop, [[$oneL, 30]]);

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertDontSee('€');
    }

    // ── Loaded tick ──────────────────────────────────────────────────

    public function test_factory_can_toggle_loaded_and_driver_cannot(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Corner Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $order = $this->orderFor($shop, [[$oneL, 10]]);

        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)
            ->post(route('chilled-runs.toggle-loaded', $order))
            ->assertRedirect();
        $this->assertNotNull($order->refresh()->loaded_at);

        $this->actingAs($factory)
            ->post(route('chilled-runs.toggle-loaded', $order))
            ->assertRedirect();
        $this->assertNull($order->refresh()->loaded_at);

        $this->actingAs(User::factory()->driver()->create())
            ->post(route('chilled-runs.toggle-loaded', $order))
            ->assertForbidden();
        $this->assertNull($order->refresh()->loaded_at);
    }

    public function test_loaded_tick_does_not_grant_general_order_write(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Corner Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $order = $this->orderFor($shop, [[$oneL, 10]]);

        // Factory can tick loaded but still cannot edit the order itself.
        $this->actingAs(User::factory()->factoryWorker()->create())
            ->put(route('orders.update', $order), ['status' => 'cancelled'])
            ->assertForbidden();
    }

    // ── Run management ───────────────────────────────────────────────

    public function test_assign_reorder_and_unassign_stops(): void
    {
        $run = $this->makeRun();
        $office = User::factory()->create();

        $a = Customer::create(['name' => 'Shop A', 'email' => 'a@example.ie', 'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true]);
        $b = Customer::create(['name' => 'Shop B', 'email' => 'b@example.ie', 'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true]);

        $this->actingAs($office)->post(route('delivery-runs.assign', $run), ['customer_id' => $a->id])->assertRedirect();
        $this->actingAs($office)->post(route('delivery-runs.assign', $run), ['customer_id' => $b->id])->assertRedirect();

        $this->assertSame(1, $a->refresh()->run_position);
        $this->assertSame(2, $b->refresh()->run_position);

        // Index renders the assigned stops (and their reorder controls).
        $this->actingAs($office)->get(route('delivery-runs.index'))
            ->assertOk()
            ->assertSee('Shop A')
            ->assertSee('Shop B');

        $this->actingAs($office)->post(route('delivery-runs.reorder', $run), [
            'positions' => [$b->id, $a->id],
        ])->assertRedirect();

        $this->assertSame(1, $b->refresh()->run_position);
        $this->assertSame(2, $a->refresh()->run_position);

        $this->actingAs($office)->post(route('delivery-runs.unassign', $a))->assertRedirect();
        $this->assertNull($a->refresh()->delivery_run_id);
        $this->assertNull($a->refresh()->run_position);
    }

    public function test_deleting_a_run_unassigns_customers_without_deleting_them(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Surviving Shop');

        $this->actingAs(User::factory()->create())
            ->delete(route('delivery-runs.destroy', $run))
            ->assertRedirect();

        $this->assertDatabaseMissing('delivery_runs', ['id' => $run->id]);
        $this->assertNull($shop->refresh()->delivery_run_id);
        $this->assertSame('Surviving Shop', $shop->name);
    }

    // ── Date resolution ──────────────────────────────────────────────

    public function test_date_for_resolves_within_the_anchored_week(): void
    {
        $tuesdayRun = DeliveryRun::create(['name' => 'Dublin', 'day_of_week' => 2, 'is_active' => true]);
        $weeklyRun = DeliveryRun::create(['name' => 'Midlands', 'day_of_week' => null, 'is_active' => true]);

        $anchor = CarbonImmutable::parse('2026-06-04'); // a Thursday

        $this->assertSame('2026-06-02', $tuesdayRun->dateFor($anchor)->toDateString()); // Tuesday that week
        $this->assertSame('2026-06-01', $weeklyRun->dateFor($anchor)->toDateString()); // week start
        $this->assertSame(CarbonInterface::TUESDAY, $tuesdayRun->dateFor($anchor)->dayOfWeek);
    }

    public function test_date_query_param_shifts_the_week(): void
    {
        $run = $this->makeRun(['day_of_week' => 2]); // Tuesday run
        $shop = $this->customerOnRun($run, 'Next Week Shop');

        $nextTuesday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->addDay();
        $this->orderFor($shop, [[$this->variant($this->milk, '1L Bottle', '1L', 16), 12]], 'confirmed', $nextTuesday->toDateString());

        // This week: no order for the stop.
        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('No order this week');

        // Anchored to next week: the order appears.
        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index', ['date' => $nextTuesday->toDateString()]))
            ->assertOk()
            ->assertDontSee('No order this week')
            ->assertSee('>12<', false);
    }

    // ── Inline order entry (saveStop) ────────────────────────────────

    public function test_entry_creates_a_pending_order_with_run_delivery_date(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'New Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 6],
            ])
            ->assertRedirect();

        $order = Order::where('customer_id', $shop->id)->sole();
        $this->assertSame('pending', $order->status);
        $this->assertSame(now()->toDateString(), $order->order_date->toDateString());
        $this->assertSame($run->dateFor()->toDateString(), $order->delivery_date->toDateString());
        $this->assertSame(6, $order->orderItems()->sole()->quantity_ordered);
        $this->assertStringStartsWith('ORD-', $order->order_number);
    }

    public function test_entry_updates_an_existing_line_without_duplicating(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Repeat Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $order = $this->orderFor($shop, [[$oneL, 4]]);

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 9],
            ])
            ->assertRedirect();

        $this->assertSame(9, $order->orderItems()->sole()->refresh()->quantity_ordered);
    }

    public function test_zero_removes_a_line_and_the_order_survives(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Two Line Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $y500 = $this->variant($this->yoghurt, '500g Tub', '500g', 6);
        $order = $this->orderFor($shop, [[$oneL, 4], [$y500, 2]]);

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 0, $y500->id => 2],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertNotSame('cancelled', $order->status);
        $this->assertSame(1, $order->orderItems()->count());
        $this->assertSame($y500->id, $order->orderItems()->sole()->product_variant_id);
    }

    public function test_zeroing_everything_cancels_the_order_keeping_lines_as_history(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Gone Quiet Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $order = $this->orderFor($shop, [[$oneL, 4]]);

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 0],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(1, $order->orderItems()->count()); // line kept as history
    }

    public function test_no_order_and_all_zeros_is_a_no_op(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Quiet Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 0],
            ])
            ->assertRedirect();

        $this->assertSame(0, Order::where('customer_id', $shop->id)->count());
    }

    public function test_multiple_same_day_orders_block_inline_editing(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Busy Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $this->orderFor($shop, [[$oneL, 4]]);
        $this->orderFor($shop, [[$oneL, 2]]);

        $office = User::factory()->create();

        // POST is rejected outright.
        $this->actingAs($office)
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 9],
            ])
            ->assertStatus(409);

        // ?edit= renders no inputs; the row links to the order page instead.
        $this->actingAs($office)
            ->get(route('chilled-runs.index', ['edit' => $shop->id]))
            ->assertOk()
            ->assertDontSee('name="qty', false)
            ->assertSee('Multiple orders');
    }

    public function test_factory_gets_no_entry_ui_and_cannot_save(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Corner Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $this->orderFor($shop, [[$oneL, 4]]);

        $factory = User::factory()->factoryWorker()->create();

        // Even with ?edit= the sheet renders read-only for factory.
        $this->actingAs($factory)
            ->get(route('chilled-runs.index', ['edit' => $shop->id]))
            ->assertOk()
            ->assertDontSee('name="qty', false)
            ->assertDontSee('Enter order');

        // The save route lives in the office/admin group — middleware 403.
        $this->actingAs($factory)
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 9],
            ])
            ->assertForbidden();
    }

    public function test_decreasing_below_picked_quantity_returns_stock_to_the_batch(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Stocked Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $order = $this->orderFor($shop, [[$oneL, 10]], 'confirmed');
        $item = $order->orderItems()->sole();

        $batch = Batch::create([
            'product_id' => $this->milk->id,
            'production_date' => now()->subDay()->toDateString(),
            'raw_milk_litres' => 100,
            'status' => 'active',
        ]);
        $batchItem = BatchItem::create([
            'batch_id' => $batch->id,
            'product_variant_id' => $oneL->id,
            'quantity_produced' => 20,
            'quantity_remaining' => 20,
            'unit_weight_kg' => 1.0,
        ]);

        $allocation = $item->allocateFromBatchItem($batchItem, 10);
        $item->fulfillAllocation($allocation, 10);
        $this->assertSame(10, $batchItem->refresh()->quantity_remaining);

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$oneL->id => 3],
            ])
            ->assertRedirect();

        $this->assertSame(3, $item->refresh()->quantity_ordered);
        $this->assertSame(17, $batchItem->refresh()->quantity_remaining); // 7 picked units restored
    }

    public function test_saved_cheese_line_appears_as_a_column(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Cheese Shop');
        $this->variant($this->milk, '1L Bottle', '1L', 16);
        $vacPack = $this->variant($this->cheese, 'Vacuum Pack', 'pack');

        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'qty' => [$vacPack->id => 3],
            ])
            ->assertRedirect();

        $this->actingAs($office)
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('Cheese')              // group header
            ->assertSee('pack')                // column sub-header
            ->assertSee('>3<', false);         // qty cell
    }

    public function test_cheese_columns_from_different_products_show_their_variety(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Wheel Shop');

        $farmhouse = Product::create(['name' => 'Mossfield Farmhouse Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);
        $garlic = Product::create(['name' => 'Garlic & Basil Cheese', 'type' => 'cheese', 'maturation_days' => 90, 'is_active' => true]);

        // Both cheese products have a "wheel" variant — size alone is ambiguous.
        $farmhouseWheel = $this->variant($farmhouse, 'Whole Wheel', 'wheel');
        $garlicWheel = $this->variant($garlic, 'Whole Wheel', 'wheel');

        $this->orderFor($shop, [[$farmhouseWheel, 2], [$garlicWheel, 1]]);

        // Assert the rendered header labels themselves — the boilerplate is
        // stripped on word boundaries ("Farmhouse" must survive the "Farm"
        // strip and "Mossfield"/"Cheese" must go).
        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('<span class="vrt">Farmhouse</span>', false)
            ->assertSee('<span class="vrt">Garlic &amp; Basil</span>', false);
    }

    public function test_edit_mode_offers_history_excluding_the_order_being_edited(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'History Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);

        $prior1 = $this->orderFor($shop, [[$oneL, 7]], 'delivered', now()->subWeeks(2)->toDateString());
        $prior2 = $this->orderFor($shop, [[$oneL, 8]], 'delivered', now()->subWeek()->toDateString());
        $current = $this->orderFor($shop, [[$oneL, 9]]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index', ['edit' => $shop->id]))
            ->assertOk()
            ->assertSee('name="qty', false)
            ->assertSee('Repeat last order')
            ->assertDontSee('€');

        // History JSON includes the prior orders but not the one being edited.
        $body = $response->getContent();
        $this->assertStringContainsString($prior1->order_number, $body);
        $this->assertStringContainsString($prior2->order_number, $body);
        $this->assertSame(
            0,
            substr_count($body, $current->order_number),
            'The order being edited must not appear in its own history payload'
        );
    }

    public function test_confirm_all_moves_only_the_runs_pending_orders_to_confirmed(): void
    {
        $run = $this->makeRun();
        $shopA = $this->customerOnRun($run, 'Shop A', 1);
        $shopB = $this->customerOnRun($run, 'Shop B', 2);
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);

        $pendingToday = $this->orderFor($shopA, [[$oneL, 4]], 'pending');
        $alreadyConfirmed = $this->orderFor($shopB, [[$oneL, 2]], 'confirmed');
        $pendingNextWeek = $this->orderFor($shopA, [[$oneL, 6]], 'pending', now()->addWeek()->toDateString());

        // Off-run customer with a pending order the same day — untouched.
        $offRun = Customer::create(['name' => 'Off Run Shop', 'email' => 'off@example.ie', 'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67', 'is_active' => true]);
        $offRunPending = $this->orderFor($offRun, [[$oneL, 3]], 'pending');

        $office = User::factory()->create();

        // The button shows with the pending count.
        $this->actingAs($office)
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertSee('Confirm all (1)')
            ->assertSee('Pending');

        $this->actingAs($office)
            ->post(route('chilled-runs.confirm-all'), ['run' => $run->id])
            ->assertRedirect();

        $this->assertSame('confirmed', $pendingToday->refresh()->status);
        $this->assertSame('confirmed', $alreadyConfirmed->refresh()->status); // unchanged
        $this->assertSame('pending', $pendingNextWeek->refresh()->status);    // other week untouched
        $this->assertSame('pending', $offRunPending->refresh()->status);      // off-run untouched

        // Button gone once nothing is pending.
        $this->actingAs($office)
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertDontSee('Confirm all');
    }

    public function test_factory_cannot_confirm_all(): void
    {
        $run = $this->makeRun();
        $shop = $this->customerOnRun($run, 'Corner Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);
        $order = $this->orderFor($shop, [[$oneL, 4]], 'pending');

        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)
            ->get(route('chilled-runs.index'))
            ->assertOk()
            ->assertDontSee('Confirm all');

        $this->actingAs($factory)
            ->post(route('chilled-runs.confirm-all'), ['run' => $run->id])
            ->assertForbidden();
        $this->assertSame('pending', $order->refresh()->status);
    }

    public function test_save_with_date_param_targets_the_shifted_week(): void
    {
        $run = $this->makeRun(['day_of_week' => 2]); // Tuesday run
        $shop = $this->customerOnRun($run, 'Forward Shop');
        $oneL = $this->variant($this->milk, '1L Bottle', '1L', 16);

        $nextTuesday = CarbonImmutable::now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->addDay();

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.save-stop', $shop), [
                'run' => $run->id,
                'date' => $nextTuesday->toDateString(),
                'qty' => [$oneL->id => 5],
            ])
            ->assertRedirect();

        $order = Order::where('customer_id', $shop->id)->sole();
        $this->assertSame($nextTuesday->toDateString(), $order->delivery_date->toDateString());
    }
}
