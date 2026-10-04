<?php

namespace Tests\Feature;

use App\Http\Controllers\Invoicing\UninvoicedOrderController;
use App\Mail\DispatchConfirmationMail;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The run-level "Dispatch run" button on the chilled-run sheet: the last step
 * of enter → Confirm all → Allocate all → pick → load → dispatch, and the point
 * at which the finance side is told what went out.
 */
class DispatchRunTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $bottle;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $milk = Product::create(['name' => 'Organic Milk', 'type' => 'milk', 'is_active' => true]);
        $this->bottle = ProductVariant::create([
            'product_id' => $milk->id, 'name' => '1L Bottle', 'size' => '1L', 'unit' => 'bottle',
            'weight_kg' => 1.0, 'base_price' => 1.50, 'is_active' => true,
        ]);
    }

    private function makeRun(array $attrs = []): DeliveryRun
    {
        return DeliveryRun::create(array_merge([
            'name' => 'Dublin',
            'day_of_week' => CarbonImmutable::now()->isoWeekday(),
            'driver' => 'Stuart',
            'is_active' => true,
        ], $attrs));
    }

    private function customerOnRun(DeliveryRun $run, string $name, int $position = 1, array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@example.ie',
            'address' => 'Main St', 'city' => 'Wicklow', 'postal_code' => 'A67',
            'payment_terms' => 'net_30',
            'is_active' => true,
            'delivery_run_id' => $run->id,
            'run_position' => $position,
        ], $attrs));
    }

    /** A picked order for today's run, loaded onto the van unless told otherwise. */
    private function orderFor(Customer $customer, string $status = 'ready', bool $loaded = true, ?string $deliveryDate = null): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'delivery_date' => $deliveryDate ?? now()->toDateString(),
            'status' => $status,
            'payment_status' => 'pending',
            'loaded_at' => $loaded ? now() : null,
        ]);
        $order->orderItems()->create([
            'product_variant_id' => $this->bottle->id,
            'quantity_ordered' => 10, 'quantity_fulfilled' => 10,
            'unit_price' => 1.50,
        ]);
        $order->calculateTotals();

        return $order;
    }

    private function dispatchRun(DeliveryRun $run, ?User $as = null)
    {
        return $this->actingAs($as ?? User::factory()->create())
            ->post(route('chilled-runs.dispatch-all'), ['run' => $run->id]);
    }

    public function test_only_loaded_ready_orders_are_dispatched_and_the_rest_are_named(): void
    {
        $run = $this->makeRun();
        $loaded = $this->orderFor($this->customerOnRun($run, 'Loaded Shop', 1));
        $unloaded = $this->orderFor($this->customerOnRun($run, 'Forgotten Shop', 2), loaded: false);
        $picking = $this->orderFor($this->customerOnRun($run, 'Slow Shop', 3), status: 'preparing');

        $this->dispatchRun($run)
            ->assertRedirect()
            ->assertSessionHas('success', function (string $m) use ($unloaded, $picking) {
                // Per order, with every reason (cycle 4, F-32).
                return str_contains($m, '1 order dispatched')
                    && str_contains($m, $unloaded->order_number.' Forgotten Shop (not loaded)')
                    && str_contains($m, $picking->order_number.' Slow Shop (not picked)');
            });

        $this->assertSame('dispatched', $loaded->fresh()->status);
        $this->assertNotNull($loaded->fresh()->dispatched_at);
        $this->assertSame('ready', $unloaded->fresh()->status);
        $this->assertSame('preparing', $picking->fresh()->status);
    }

    public function test_a_skipped_order_lists_every_reason_and_the_loaded_flash_names_the_order(): void
    {
        $run = $this->makeRun();
        $this->orderFor($this->customerOnRun($run, 'Loaded Shop', 1));
        $both = $this->orderFor($this->customerOnRun($run, 'An Tairseach', 2), status: 'preparing', loaded: false);

        $this->dispatchRun($run)->assertSessionHas('success', fn ($m) => str_contains($m, 'skipped: '.$both->order_number.' An Tairseach (not picked, not loaded)'));

        $this->actingAs(User::factory()->create())
            ->post(route('chilled-runs.toggle-loaded', $both), ['run' => $run->id])
            ->assertSessionHas('success', 'An Tairseach · '.$both->order_number.' marked loaded.');
    }

    public function test_per_order_customers_get_an_invoice_and_consolidated_ones_do_not(): void
    {
        $run = $this->makeRun();
        $perOrder = $this->orderFor($this->customerOnRun($run, 'Corner Shop', 1));
        $weekly = $this->orderFor($this->customerOnRun($run, 'Weekly Wholesaler', 2, ['invoicing_mode' => 'weekly']));

        $this->dispatchRun($run)->assertSessionHas('success', fn ($m) => str_contains($m, '2 orders dispatched') && str_contains($m, '1 invoice issued'));

        $this->assertSame(1, Invoice::count());
        $this->assertNotNull($perOrder->fresh()->invoice_id);
        $this->assertNull($weekly->fresh()->invoice_id, 'a weekly customer waits for the period run');
        $this->assertSame('dispatched', $weekly->fresh()->status, 'but is still dispatched');

        // Both shops get a confirmation — it's per delivery, not per invoice.
        $this->assertSame(2, DocumentEmail::where('kind', 'dispatch_confirmation')->count());
    }

    public function test_confirmations_share_one_batch_id_and_are_sent(): void
    {
        $run = $this->makeRun();
        $this->orderFor($this->customerOnRun($run, 'Shop A', 1));
        $this->orderFor($this->customerOnRun($run, 'Shop B', 2));

        $this->dispatchRun($run)->assertSessionHas('success', fn ($m) => str_contains($m, '2 confirmations queued'));

        $rows = DocumentEmail::where('kind', 'dispatch_confirmation')->get();

        $this->assertCount(2, $rows);
        $this->assertNotNull($rows->first()->batch_id);
        $this->assertSame(1, $rows->pluck('batch_id')->unique()->count(), 'one batch id per run');
        // QUEUE_CONNECTION=sync in tests: the job runs inline and lands as sent.
        $this->assertTrue($rows->every(fn ($r) => $r->status->value === 'sent'));
        Mail::assertSent(DispatchConfirmationMail::class, 2);
    }

    public function test_the_invoice_is_not_emailed_at_dispatch(): void
    {
        $run = $this->makeRun();
        $order = $this->orderFor($this->customerOnRun($run, 'Corner Shop'));

        $this->dispatchRun($run);

        $invoice = $order->fresh()->invoice;
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->emailed_at, 'office sends the invoice from Finance');
        $this->assertSame(1, Invoice::notEmailed()->count());
        Mail::assertNotSent(\App\Mail\DocumentMail::class);
    }

    public function test_an_invoice_failure_does_not_block_dispatch(): void
    {
        $run = $this->makeRun();
        $order = $this->orderFor($this->customerOnRun($run, 'Corner Shop'));

        // Make issuing blow up, then check dispatch still landed.
        $this->partialMock(\App\Services\InvoiceService::class, function ($mock) {
            $mock->shouldReceive('issueForOrder')->andThrow(new \App\Services\InvoicingException('Numbering broke'));
        });

        $this->dispatchRun($run)->assertSessionHas('success', fn ($m) => str_contains($m, '1 order dispatched') && str_contains($m, 'invoice failed'));

        $order->refresh();
        $this->assertSame('dispatched', $order->status);
        $this->assertNull($order->invoice_id);
        $this->assertTrue(UninvoicedOrderController::uninvoicedQuery()->whereKey($order->id)->exists(), 'it waits in Finance → Awaiting invoice');
        // The confirmation still went.
        $this->assertSame(1, DocumentEmail::where('kind', 'dispatch_confirmation')->count());
    }

    public function test_a_customer_with_no_email_is_dispatched_and_flagged(): void
    {
        $run = $this->makeRun();
        $order = $this->orderFor($this->customerOnRun($run, 'Quiet Shop', 1, ['email' => '']));

        $this->dispatchRun($run)->assertSessionHas('success', fn ($m) => str_contains($m, 'no email: Quiet Shop'));

        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->invoice_id, 'the invoice is still issued');
        $this->assertSame(0, DocumentEmail::count());
    }

    public function test_a_second_run_finds_nothing_to_dispatch(): void
    {
        $run = $this->makeRun();
        $this->orderFor($this->customerOnRun($run, 'Corner Shop'));
        $office = User::factory()->create();

        $this->dispatchRun($run, $office);
        $this->dispatchRun($run, $office)->assertSessionHas('error', fn ($m) => str_contains($m, 'Nothing to dispatch'));

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, DocumentEmail::count());
    }

    public function test_other_runs_and_other_weeks_are_untouched(): void
    {
        $run = $this->makeRun();
        $other = $this->makeRun(['name' => 'Cork']);
        $today = $this->orderFor($this->customerOnRun($run, 'Today Shop', 1));
        $otherRun = $this->orderFor($this->customerOnRun($other, 'Cork Shop', 1));
        $nextWeek = $this->orderFor($this->customerOnRun($run, 'Next Week Shop', 2), deliveryDate: now()->addWeek()->toDateString());

        $this->dispatchRun($run);

        $this->assertSame('dispatched', $today->fresh()->status);
        $this->assertSame('ready', $otherRun->fresh()->status);
        $this->assertSame('ready', $nextWeek->fresh()->status);
    }

    public function test_factory_cannot_dispatch_a_run_and_sees_no_button(): void
    {
        $run = $this->makeRun();
        $order = $this->orderFor($this->customerOnRun($run, 'Corner Shop'));
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)->get(route('chilled-runs.index', ['run' => $run->id]))
            ->assertOk()->assertDontSee('Dispatch run');

        $this->actingAs($factory)->post(route('chilled-runs.dispatch-all'), ['run' => $run->id])
            ->assertForbidden();

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_the_button_counts_loaded_ready_orders_and_disappears_after(): void
    {
        $run = $this->makeRun();
        $this->orderFor($this->customerOnRun($run, 'Shop A', 1));
        $this->orderFor($this->customerOnRun($run, 'Shop B', 2));
        $this->orderFor($this->customerOnRun($run, 'Shop C', 3), loaded: false);
        $office = User::factory()->create();

        $this->actingAs($office)->get(route('chilled-runs.index', ['run' => $run->id]))
            ->assertOk()->assertSee('Dispatch run (2)');

        $this->dispatchRun($run, $office);

        $this->actingAs($office)->get(route('chilled-runs.index', ['run' => $run->id]))
            ->assertOk()->assertDontSee('Dispatch run (');
    }

    public function test_the_sheet_shows_dispatched_state_and_locks_the_tick(): void
    {
        $run = $this->makeRun();
        $order = $this->orderFor($this->customerOnRun($run, 'Corner Shop'));
        $office = User::factory()->create();

        $this->dispatchRun($run, $office);

        $html = $this->actingAs($office)->get(route('chilled-runs.index', ['run' => $run->id]))
            ->assertOk()
            ->assertSee('Dispatched')
            ->assertSee('All stops dispatched')
            ->assertDontSee('/10 picked')
            ->getContent();

        $this->assertStringNotContainsString(route('chilled-runs.toggle-loaded', $order), $html, 'the tick is static once dispatched');

        $loadedAt = $order->fresh()->loaded_at;
        $this->actingAs($office)->post(route('chilled-runs.toggle-loaded', $order), ['run' => $run->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'locked'));

        $this->assertEquals($loadedAt, $order->fresh()->loaded_at);
    }

    public function test_the_sheet_stays_free_of_euro_amounts(): void
    {
        $run = $this->makeRun();
        $this->orderFor($this->customerOnRun($run, 'Corner Shop'));

        $this->actingAs(User::factory()->create())
            ->get(route('chilled-runs.index', ['run' => $run->id]))
            ->assertOk()
            ->assertDontSee('€');
    }
}
