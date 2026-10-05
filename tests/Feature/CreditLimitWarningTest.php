<?php

namespace Tests\Feature;

use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The credit limit warns, never blocks (OPEN_QUESTIONS #5, F-25): confirming
 * or creating an order that takes a customer past their limit adds a
 * warning flash, and the order page shows the same sentence. A limit of 0
 * means "no limit".
 */
class CreditLimitWarningTest extends InvoicingTestCase
{
    private User $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = User::factory()->create();
        $this->shop->update(['credit_limit' => 100]);

        // €80 outstanding: one open invoice.
        $owed = $this->deliveredOrder(40); // 40 × €1.50 = €60 …
        $owed->orderItems->first()->update(['unit_price' => 2.00]); // … repriced to €80
        $owed->calculateTotals();
        $this->issue($owed->fresh());
        $this->assertSame(80.0, (float) $this->shop->fresh()->outstanding_balance);
    }

    private function pendingOrder(int $bottles): Order
    {
        $order = Order::create([
            'customer_id' => $this->shop->id, 'order_date' => today(), 'delivery_date' => today()->addDay(),
            'status' => 'pending', 'payment_status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_variant_id' => $this->bottle->id,
            'quantity_ordered' => $bottles, 'unit_price' => 1.50,
        ]);
        $order->calculateTotals();

        return $order->fresh();
    }

    private function confirm(Order $order)
    {
        return $this->actingAs($this->office)->patch(route('orders.update', $order), [
            'order_date' => $order->order_date->toDateString(),
            'delivery_date' => $order->delivery_date?->toDateString(),
            'status' => 'confirmed',
        ]);
    }

    public function test_confirming_over_the_limit_warns_but_confirms(): void
    {
        $order = $this->pendingOrder(20); // €30 → €110

        $this->confirm($order)
            ->assertSessionHas('success', 'Order confirmed.')
            ->assertSessionHas('warning', 'Kerry Organic Store is at €80.00 outstanding against a €100.00 credit limit — this order takes it to €110.00.');
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_within_the_limit_there_is_no_warning(): void
    {
        $this->confirm($this->pendingOrder(10)) // €15 → €95
            ->assertSessionHas('success', 'Order confirmed.')
            ->assertSessionMissing('warning');
    }

    public function test_a_zero_limit_means_no_limit(): void
    {
        $this->shop->update(['credit_limit' => 0]);

        $this->confirm($this->pendingOrder(500))->assertSessionMissing('warning');
        $this->assertNull($this->shop->fresh()->creditWarning(1_000_000));
        $this->assertTrue($this->shop->fresh()->canPlaceOrder(1_000_000));
    }

    public function test_creating_an_order_over_the_limit_warns(): void
    {
        $this->actingAs($this->office)->post(route('orders.store'), [
            'customer_id' => $this->shop->id,
            'order_date' => today()->toDateString(),
            'items' => [['product_variant_id' => $this->bottle->id, 'quantity' => 20]],
        ])->assertSessionHas('warning', fn ($m) => str_contains($m, 'takes it to €110.00'));
    }

    public function test_the_order_page_shows_the_banner_over_and_not_within(): void
    {
        $over = $this->pendingOrder(20);
        $within = $this->pendingOrder(10);

        $this->actingAs($this->office)->get(route('orders.show', $over))
            ->assertOk()
            ->assertSee('data-credit-warning', false)
            ->assertSee('this order takes it to €110.00.');
        $this->actingAs($this->office)->get(route('orders.show', $within))
            ->assertOk()
            ->assertDontSee('data-credit-warning', false);

        // Factory never sees money.
        $this->actingAs(User::factory()->factoryWorker()->create())->get(route('orders.show', $over))
            ->assertOk()
            ->assertDontSee('data-credit-warning', false);
    }

    public function test_confirm_all_names_each_customer_once(): void
    {
        $run = DeliveryRun::create(['name' => 'Kerry', 'day_of_week' => CarbonImmutable::now()->isoWeekday(), 'is_active' => true]);
        $this->shop->update(['delivery_run_id' => $run->id, 'run_position' => 1]);
        foreach ([8, 8] as $bottles) { // 2 × €12 = €24 → €104
            $this->pendingOrder($bottles)->update(['delivery_date' => today()]);
        }

        $this->actingAs($this->office)->post(route('chilled-runs.confirm-all'), ['run' => $run->id])
            ->assertSessionHas('success', '2 orders confirmed — now on the picking queue.')
            ->assertSessionHas('warning', fn ($m) => substr_count($m, 'Kerry Organic Store is at') === 1 && str_contains($m, 'takes it to €104.00'));
    }
}
