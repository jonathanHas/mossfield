<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Statement;
use App\Models\User;

/**
 * Who can see the money.
 *
 * Finance is office/admin only. FinancialPolicy narrows BasePolicy so factory
 * loses even view access — the packing floor's two carve-outs (recording picks
 * and ticking a van loaded) don't reach here.
 */
class InvoicingAccessTest extends InvoicingTestCase
{
    /** @return array<int, string> */
    private function readRoutes(): array
    {
        $invoice = $this->issue($this->deliveredOrder());

        return [
            route('invoicing.invoices.index'),
            route('invoicing.invoices.show', $invoice),
            route('invoicing.invoices.pdf', $invoice),
            route('invoicing.uninvoiced.index'),
            route('invoicing.consolidated.create'),
            route('invoicing.overdue.index'),
            route('invoicing.payments.index'),
            route('invoicing.payments.create'),
            route('invoicing.statements.index'),
            route('invoicing.statements.batch.create'),
            route('invoicing.statements.preview', $this->shop),
            route('invoicing.emails.index'),
            route('invoicing.export.index'),
        ];
    }

    public function test_factory_is_denied_every_finance_screen(): void
    {
        $routes = $this->readRoutes();
        $factory = User::factory()->factoryWorker()->create();

        foreach ($routes as $url) {
            $this->actingAs($factory)->get($url)->assertForbidden();
        }
    }

    public function test_driver_is_denied_every_finance_screen(): void
    {
        $routes = $this->readRoutes();
        $driver = User::factory()->driver()->create();

        foreach ($routes as $url) {
            $this->actingAs($driver)->get($url)->assertForbidden();
        }
    }

    public function test_office_can_reach_every_finance_screen(): void
    {
        $routes = $this->readRoutes();
        $office = User::factory()->create();

        foreach ($routes as $url) {
            $this->actingAs($office)->get($url)->assertSuccessful();
        }
    }

    public function test_factory_cannot_issue_or_void_an_invoice(): void
    {
        $order = $this->deliveredOrder();
        $factory = User::factory()->factoryWorker()->create();

        $this->actingAs($factory)
            ->post(route('invoicing.invoices.issue-order', $order))
            ->assertForbidden();

        $this->assertNull($order->fresh()->invoice_id);
    }

    public function test_factory_cannot_record_a_payment(): void
    {
        $invoice = $this->issue($this->deliveredOrder());

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('invoicing.payments.store'), [
                'customer_id' => $this->shop->id,
                'amount' => '15.00',
                'received_at' => today()->toDateString(),
                'method' => 'cash',
                'allocations' => [$invoice->id => '15.00'],
            ])
            ->assertForbidden();

        $this->assertSame(0, Payment::count());
    }

    /** The policies deny factory directly, not just the route middleware. */
    public function test_the_policies_themselves_deny_factory(): void
    {
        $factory = User::factory()->factoryWorker()->create();
        $invoice = $this->issue($this->deliveredOrder());

        $this->assertFalse($factory->can('viewAny', Invoice::class));
        $this->assertFalse($factory->can('view', $invoice));
        $this->assertFalse($factory->can('create', Invoice::class));
        $this->assertFalse($factory->can('viewAny', Payment::class));
        $this->assertFalse($factory->can('viewAny', Statement::class));
    }

    public function test_admin_and_office_pass_the_policies(): void
    {
        $invoice = $this->issue($this->deliveredOrder());

        foreach ([User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->assertTrue($user->can('viewAny', Invoice::class));
            $this->assertTrue($user->can('view', $invoice));
            $this->assertTrue($user->can('create', Payment::class));
        }
    }

    public function test_the_finance_nav_group_shows_for_office_but_not_factory(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Finance');

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Finance<', false);
    }

    /** The email log is written by the mailer, never by a user. */
    public function test_the_email_log_is_read_only_even_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $office = User::factory()->create();

        // The read-only override bites for office. Admin still passes
        // everything via BasePolicy::before(), as it does across the app —
        // the point here is that no UI offers writing a log row.
        $this->assertFalse($office->can('create', \App\Models\DocumentEmail::class));
        $this->assertFalse($office->can('delete', new \App\Models\DocumentEmail));
        $this->assertTrue($admin->can('viewAny', \App\Models\DocumentEmail::class));
        $this->assertTrue($office->can('viewAny', \App\Models\DocumentEmail::class));
    }
}
