<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OnlineOrderImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-delivery-run delivery charge: an ex-VAT € amount on a DeliveryRun,
 * toggled per customer on the delivery-runs index (behind a reveal), snapshotted
 * onto new orders (23% VAT), editable per order, shown on show + invoice only.
 */
class DeliveryChargeTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->variant = ProductVariant::create([
            'product_id' => $milk->id,
            'name' => '1L Bottle',
            'size' => '1L',
            'unit' => 'bottle',
            'base_price' => 1.50,
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

    private function customerOnRun(DeliveryRun $run, string $name, bool $charged = false): Customer
    {
        return Customer::create([
            'name' => $name,
            'email' => uniqid().'@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'is_active' => true,
            'delivery_run_id' => $run->id,
            'run_position' => 1,
            'apply_delivery_charge' => $charged,
        ]);
    }

    // ── Run form ─────────────────────────────────────────────────────

    public function test_run_form_persists_the_delivery_charge(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('delivery-runs.store'), [
            'name' => 'Midlands',
            'delivery_charge' => '4.50',
        ])->assertRedirect(route('delivery-runs.index'));

        $this->assertEquals(4.50, DeliveryRun::where('name', 'Midlands')->sole()->delivery_charge);
    }

    public function test_run_form_rejects_a_negative_charge(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('delivery-runs.store'), [
            'name' => 'Bad Run',
            'delivery_charge' => '-5',
        ])->assertSessionHasErrors('delivery_charge');

        $this->assertDatabaseMissing('delivery_runs', ['name' => 'Bad Run']);
    }

    // ── Per-customer toggle ──────────────────────────────────────────

    public function test_office_can_toggle_the_charge_flag_both_ways(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Corner Shop');

        $this->actingAs($office)
            ->post(route('delivery-runs.toggle-charge', $shop))
            ->assertRedirect(route('delivery-runs.index', ['charges' => 1]));
        $this->assertTrue($shop->fresh()->apply_delivery_charge);

        $this->actingAs($office)->post(route('delivery-runs.toggle-charge', $shop));
        $this->assertFalse($shop->fresh()->apply_delivery_charge);
    }

    public function test_factory_cannot_toggle_the_charge_flag(): void
    {
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Corner Shop');

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('delivery-runs.toggle-charge', $shop))
            ->assertForbidden();

        $this->assertFalse($shop->fresh()->apply_delivery_charge);
    }

    public function test_toggling_an_unassigned_customer_errors_and_leaves_flag_unchanged(): void
    {
        $office = User::factory()->create();
        $loner = Customer::create([
            'name' => 'No Run Shop',
            'email' => 'norun@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'is_active' => true,
        ]);

        $this->actingAs($office)
            ->post(route('delivery-runs.toggle-charge', $loner))
            ->assertRedirect(route('delivery-runs.index'))
            ->assertSessionHas('error');

        $this->assertFalse($loner->fresh()->apply_delivery_charge);
    }

    public function test_index_shows_the_reveal_button_and_toggle_form(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $this->customerOnRun($run, 'Corner Shop');

        $this->actingAs($office)
            ->get(route('delivery-runs.index'))
            ->assertOk()
            ->assertSee('Show charge settings')
            ->assertSee(route('delivery-runs.toggle-charge', Customer::sole()), false);
    }

    // ── Snapshot at creation ─────────────────────────────────────────

    public function test_office_store_snapshots_charge_and_vat_for_a_flagged_customer(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Corner Shop', charged: true);

        $this->actingAs($office)->post(route('orders.store'), [
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => null,
            'delivery_address' => null,
            'notes' => null,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]],
        ])->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(4.50, $order->delivery_charge);  // stored gross (VAT-inclusive)
        $this->assertEquals(3.00, $order->subtotal);         // 2 × 1.50
        $this->assertEquals(0.84, $order->tax_amount);       // VAT contained in €4.50 @ 23%
        $this->assertEquals(3.66, $order->delivery_charge_net); // 4.50 − 0.84
        $this->assertEquals(7.50, $order->total_amount);     // 3.00 + 4.50 (gross), no drift
    }

    public function test_unflagged_customer_gets_no_charge(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Corner Shop', charged: false);

        $this->actingAs($office)->post(route('orders.store'), [
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => null,
            'delivery_address' => null,
            'notes' => null,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]],
        ])->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(0, $order->delivery_charge);
        $this->assertEquals(0, $order->tax_amount);
        $this->assertEquals(3.00, $order->total_amount);
    }

    public function test_flagged_customer_on_a_zero_charge_run_gets_no_charge(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 0]);
        $shop = $this->customerOnRun($run, 'Corner Shop', charged: true);

        $this->actingAs($office)->post(route('orders.store'), [
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => null,
            'delivery_address' => null,
            'notes' => null,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]],
        ])->assertRedirect();

        $this->assertEquals(0, Order::sole()->delivery_charge);
    }

    public function test_chilled_run_save_stop_snapshots_the_charge_on_a_new_order(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Corner Shop', charged: true);

        $this->actingAs($office)->post(route('chilled-runs.save-stop', $shop), [
            'run' => $run->id,
            'qty' => [$this->variant->id => 6],
        ])->assertRedirect();

        $order = Order::where('customer_id', $shop->id)->sole();
        $this->assertEquals(4.50, $order->delivery_charge);
        $this->assertEquals(0.84, $order->tax_amount);
    }

    public function test_editing_quantities_via_save_stop_does_not_change_the_snapshot(): void
    {
        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Corner Shop', charged: true);

        // Create with the charge, then flip the flag off and re-save quantities.
        $this->actingAs($office)->post(route('chilled-runs.save-stop', $shop), [
            'run' => $run->id,
            'qty' => [$this->variant->id => 6],
        ]);
        $shop->update(['apply_delivery_charge' => false]);

        $this->actingAs($office)->post(route('chilled-runs.save-stop', $shop), [
            'run' => $run->id,
            'qty' => [$this->variant->id => 9],
        ]);

        $order = Order::where('customer_id', $shop->id)->sole();
        $this->assertEquals(4.50, $order->delivery_charge, 'Existing order keeps its snapshot.');
    }

    public function test_import_snapshots_the_charge_and_recomputes_totals(): void
    {
        config([
            'services.mossorders.base_url' => 'https://mossorders.test',
            'services.mossorders.api_token' => 'token',
        ]);

        $office = User::factory()->create();
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Online Shop', charged: true);
        $shop->update(['mossorders_user_id' => 501]);

        $this->mock(OnlineOrderImportService::class, function ($mock) {
            $mock->shouldReceive('fetchOrders')->andReturn([[
                'mossorders_order_id' => 9001,
                'order_number' => 'MSF-9001',
                'customer' => ['mossorders_user_id' => 501],
                'items' => [[
                    'office_variant_id' => $this->variant->id,
                    'quantity' => 2,
                    'unit_price' => 1.50,
                    'product_name' => '1L Bottle',
                ]],
                'totals' => ['subtotal' => 3.00, 'tax' => 0, 'grand_total' => 3.00],
            ]]);
        });

        $this->actingAs($office)->post(route('online-orders.import'))->assertRedirect();

        $order = Order::where('mossorders_order_id', 9001)->sole();
        $this->assertEquals(4.50, $order->delivery_charge);
        $this->assertEquals(0.84, $order->tax_amount);
        $this->assertEquals(7.50, $order->total_amount);   // 3.00 + 4.50 (gross)
    }

    // ── Recalc survival ──────────────────────────────────────────────

    public function test_recalculating_totals_preserves_charge_and_vat(): void
    {
        $order = Order::create([
            'customer_id' => $this->customerOnRun($this->makeRun(['delivery_charge' => 4.50]), 'Shop', true)->id,
            'order_date' => now()->toDateString(),
            'status' => 'pending',
            'payment_status' => 'pending',
            'delivery_charge' => 4.50,
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $order->orderItems()->create([
            'product_variant_id' => $this->variant->id,
            'quantity_ordered' => 4,
            'unit_price' => 1.50,
        ]);

        $order->calculateTotals();

        $this->assertEquals(4.50, $order->delivery_charge);
        $this->assertEquals(6.00, $order->subtotal);         // 4 × 1.50
        $this->assertEquals(0.84, $order->tax_amount);
        $this->assertEquals(10.50, $order->total_amount);    // 6.00 + 4.50 (gross)
    }

    // ── Edit / replay ────────────────────────────────────────────────

    public function test_update_can_change_and_zero_the_charge(): void
    {
        $office = User::factory()->create();
        $order = $this->orderWithCharge(4.50);

        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'delivery_charge' => '2.00',
        ])->assertRedirect();

        $order->refresh();
        $this->assertEquals(2.00, $order->delivery_charge);
        $this->assertEquals(0.37, $order->tax_amount);       // VAT contained in €2.00 @ 23%

        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'delivery_charge' => '',
        ])->assertRedirect();

        $order->refresh();
        $this->assertEquals(0, $order->delivery_charge);
        $this->assertEquals(0, $order->tax_amount);
    }

    public function test_charge_survives_a_status_only_patch_via_status_fields(): void
    {
        $office = User::factory()->create();
        $order = $this->orderWithCharge(4.50, 'confirmed');

        // The show-page inline forms replay $statusFields as hidden inputs —
        // delivery_charge must be among them or the new blank→0 coercion zeroes it.
        $this->actingAs($office)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('name="delivery_charge"', false);

        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'preparing',
            'payment_status' => 'pending',
            'delivery_charge' => '4.50',   // replayed hidden value
            'delivery_address' => null,
            'notes' => null,
        ])->assertRedirect();

        $this->assertEquals(4.50, $order->fresh()->delivery_charge);
    }

    // ── Rendering ────────────────────────────────────────────────────

    public function test_show_and_invoice_render_the_charge_for_office(): void
    {
        $office = User::factory()->create();
        $order = $this->orderWithCharge(4.50, 'ready');

        // Net (ex-VAT) portion of the €4.50 VAT-inclusive charge is shown.
        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Delivery charge')->assertSee('3.66');

        $this->actingAs($office)->get(route('orders.invoice', $order))
            ->assertOk()->assertSee('Delivery charge')->assertSee('3.66');
    }

    public function test_factory_sees_no_euro_charge_on_the_order_show_page(): void
    {
        $order = $this->orderWithCharge(4.50, 'ready');

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('Delivery charge');
    }

    private function orderWithCharge(float $charge, string $status = 'pending'): Order
    {
        $shop = $this->customerOnRun($this->makeRun(['delivery_charge' => $charge]), 'Shop '.uniqid(), true);
        $order = Order::create([
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'delivery_charge' => $charge,
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $order->orderItems()->create([
            'product_variant_id' => $this->variant->id,
            'quantity_ordered' => 2,
            'unit_price' => 1.50,
        ]);
        $order->calculateTotals();

        return $order;
    }

    // ── Percentage charge (per customer) ─────────────────────────────

    private function percentCustomer(float $pct, string $name = 'Percent Shop'): Customer
    {
        return Customer::create([
            'name' => $name,
            'email' => uniqid().'@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'is_active' => true,
            'delivery_charge_percent' => $pct,
        ]);
    }

    private function customerFormPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Form Shop',
            'email' => uniqid().'@example.ie',
            'address' => 'Main St',
            'city' => 'Wicklow',
            'postal_code' => 'A67',
            'country' => 'Ireland',
            'credit_limit' => '0',
            'payment_terms' => 'immediate',
        ], $overrides);
    }

    public function test_customer_form_persists_the_delivery_charge_percent(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('customers.store'),
            $this->customerFormPayload(['name' => 'Pct Co', 'delivery_charge_percent' => '10']))
            ->assertRedirect();

        $customer = Customer::where('name', 'Pct Co')->sole();
        $this->assertEquals(10.00, $customer->delivery_charge_percent);

        // Blank clears it back to null (no percentage).
        $this->actingAs($office)->patch(route('customers.update', $customer),
            $this->customerFormPayload(['name' => 'Pct Co', 'email' => $customer->email, 'delivery_charge_percent' => '']))
            ->assertRedirect();
        $this->assertNull($customer->fresh()->delivery_charge_percent);
    }

    public function test_customer_form_rejects_an_out_of_range_percent(): void
    {
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('customers.store'),
            $this->customerFormPayload(['name' => 'Too High', 'delivery_charge_percent' => '150']))
            ->assertSessionHasErrors('delivery_charge_percent');

        $this->actingAs($office)->post(route('customers.store'),
            $this->customerFormPayload(['name' => 'Negative', 'delivery_charge_percent' => '-5']))
            ->assertSessionHasErrors('delivery_charge_percent');
    }

    public function test_percentage_order_snapshots_rate_and_computes_gross(): void
    {
        $office = User::factory()->create();
        $shop = $this->percentCustomer(10);

        $this->actingAs($office)->post(route('orders.store'), [
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => null,
            'delivery_address' => null,
            'notes' => null,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 10]],
        ])->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(10.00, $order->delivery_charge_percent); // rate snapshot
        $this->assertEquals(15.00, $order->subtotal);                // 10 × 1.50
        $this->assertEquals(1.50, $order->delivery_charge);          // 10% of 15.00 (gross)
        $this->assertEquals(0.28, $order->tax_amount);               // VAT in €1.50
        $this->assertEquals(16.50, $order->total_amount);            // 15.00 + 1.50, no drift
    }

    public function test_percentage_overrides_the_fixed_run_charge(): void
    {
        $office = User::factory()->create();
        // On a run with a €4.50 fixed charge AND flagged, but also a 10% rate.
        $run = $this->makeRun(['delivery_charge' => 4.50]);
        $shop = $this->customerOnRun($run, 'Both Shop', charged: true);
        $shop->update(['delivery_charge_percent' => 10]);

        $this->actingAs($office)->post(route('orders.store'), [
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => null,
            'delivery_address' => null,
            'notes' => null,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 10]],
        ])->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(10.00, $order->delivery_charge_percent);
        $this->assertEquals(1.50, $order->delivery_charge);  // 10% of 15.00, NOT 4.50
    }

    public function test_percentage_recomputes_when_the_order_changes(): void
    {
        $office = User::factory()->create();
        $shop = $this->percentCustomer(10);

        $this->actingAs($office)->post(route('orders.store'), [
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => null,
            'delivery_address' => null,
            'notes' => null,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 10]],
        ])->assertRedirect();

        $order = Order::sole();
        $this->assertEquals(1.50, $order->delivery_charge);  // 10% of 15.00

        // Add 10 more of the same variant (merges → qty 20, subtotal 30.00).
        $this->actingAs($office)->post(route('orders.items.store', $order), [
            'product_variant_id' => $this->variant->id,
            'quantity' => 10,
        ])->assertRedirect();

        $order->refresh();
        $this->assertEquals(30.00, $order->subtotal);
        $this->assertEquals(3.00, $order->delivery_charge);  // recomputed: 10% of 30.00
    }

    public function test_update_percent_recomputes_and_blank_reverts_to_fixed(): void
    {
        $office = User::factory()->create();
        $order = $this->orderWithCharge(4.50); // fixed €4.50, subtotal 3.00

        // Set a 10% rate — overrides the € amount (10% of 3.00 = 0.30).
        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'delivery_charge' => '4.50',
            'delivery_charge_percent' => '10',
        ])->assertRedirect();

        $order->refresh();
        $this->assertEquals(10.00, $order->delivery_charge_percent);
        $this->assertEquals(0.30, $order->delivery_charge);  // 10% of 3.00, not 4.50

        // Blank the percent + set a € amount → reverts to fixed.
        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'delivery_charge' => '5.00',
            'delivery_charge_percent' => '',
        ])->assertRedirect();

        $order->refresh();
        $this->assertNull($order->delivery_charge_percent);
        $this->assertEquals(5.00, $order->delivery_charge);
    }

    public function test_percent_survives_a_status_only_patch_via_status_fields(): void
    {
        $office = User::factory()->create();
        $shop = $this->percentCustomer(10);
        $order = Order::create([
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'delivery_charge_percent' => 10,
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $order->orderItems()->create([
            'product_variant_id' => $this->variant->id,
            'quantity_ordered' => 10,
            'unit_price' => 1.50,
        ]);
        $order->calculateTotals();

        // Show page carries the percent as a replayed hidden field.
        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()->assertSee('name="delivery_charge_percent"', false);

        // A status-only PATCH replaying $statusFields keeps the percent.
        $this->actingAs($office)->patch(route('orders.update', $order), [
            'status' => 'preparing',
            'payment_status' => 'pending',
            'delivery_charge' => '1.50',
            'delivery_charge_percent' => '10',
            'delivery_address' => null,
            'notes' => null,
        ])->assertRedirect();

        $order->refresh();
        $this->assertEquals(10.00, $order->delivery_charge_percent);
        $this->assertEquals(1.50, $order->delivery_charge);
    }

    public function test_percentage_shows_on_show_invoice_and_customer_page(): void
    {
        $office = User::factory()->create();
        $shop = $this->percentCustomer(10, 'Pct Display Shop');
        $order = Order::create([
            'customer_id' => $shop->id,
            'order_date' => now()->toDateString(),
            'status' => 'ready',
            'payment_status' => 'pending',
            'delivery_charge_percent' => 10,
            'subtotal' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
        $order->orderItems()->create([
            'product_variant_id' => $this->variant->id,
            'quantity_ordered' => 10,
            'unit_price' => 1.50,
        ]);
        $order->calculateTotals();

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Delivery charge (10%)')->assertSee('1.22'); // net of €1.50

        $this->actingAs($office)->get(route('orders.invoice', $order))
            ->assertOk()->assertSee('Delivery charge (10%)');

        $this->actingAs($office)->get(route('customers.show', $shop))
            ->assertOk()->assertSee('10% delivery');
    }
}
