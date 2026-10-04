<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DocumentEmail;
use App\Models\Statement;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\StatementService;
use Illuminate\Support\Facades\Mail;

/**
 * The month-end run. Queued rather than inline: dozens of SMTP round-trips would
 * time a request out. Every recipient gets a log row up front so the run can be
 * inspected and the failures retried.
 */
class StatementBatchTest extends InvoicingTestCase
{
    private function period(): array
    {
        return [today()->subMonth()->startOfMonth(), today()->subMonth()->endOfMonth()];
    }

    private function billLastMonth(?Customer $customer = null, int $qty = 10): \App\Models\Invoice
    {
        [$from] = $this->period();
        $invoice = $this->issue($this->deliveredOrder($qty, customer: $customer ?? $this->shop));
        $invoice->update(['issued_at' => $from->copy()->addDays(3), 'due_date' => $from->copy()->addDays(33)]);

        return $invoice->fresh();
    }

    public function test_candidates_are_customers_who_owe_money(): void
    {
        $this->billLastMonth();

        $settled = Customer::create([
            'name' => 'Settled Shop', 'email' => 's@example.ie',
            'address' => 'X', 'city' => 'Y', 'postal_code' => 'Z',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);
        $paid = $this->billLastMonth($settled, 4);
        app(PaymentService::class)->record($settled, [
            'amount' => 6.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$paid->id => 6.00]);

        [$from, $to] = $this->period();
        $candidates = app(StatementService::class)->candidatesForBatch($from, $to, 'balance');

        $this->assertCount(1, $candidates);
        $this->assertSame($this->shop->id, $candidates->first()->id);
    }

    public function test_activity_mode_includes_customers_who_settled_up(): void
    {
        $this->billLastMonth();
        $settled = Customer::create([
            'name' => 'Settled Shop', 'email' => 's@example.ie',
            'address' => 'X', 'city' => 'Y', 'postal_code' => 'Z',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);
        $paid = $this->billLastMonth($settled, 4);
        app(PaymentService::class)->record($settled, [
            'amount' => 6.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$paid->id => 6.00]);

        [$from, $to] = $this->period();

        $this->assertCount(2, app(StatementService::class)->candidatesForBatch($from, $to, 'activity'));
    }

    public function test_customers_without_a_billing_address_are_excluded(): void
    {
        $this->shop->update(['email' => '', 'billing_email' => null]);
        $this->billLastMonth();

        [$from, $to] = $this->period();

        $this->assertCount(0, app(StatementService::class)->candidatesForBatch($from, $to));
    }

    public function test_opted_out_customers_are_excluded(): void
    {
        $this->shop->update(['send_statements' => false]);
        $this->billLastMonth();

        [$from, $to] = $this->period();

        $this->assertCount(0, app(StatementService::class)->candidatesForBatch($from, $to));
    }

    public function test_a_run_creates_one_statement_and_one_log_row_per_recipient(): void
    {
        Mail::fake();
        $this->billLastMonth();
        [$from, $to] = $this->period();
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.statements.batch.store'), [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'customer_ids' => [$this->shop->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Statement::count());

        $row = DocumentEmail::sole();

        $this->assertSame('statement', $row->kind->value);
        $this->assertNotNull($row->batch_id, 'the run groups its sends under one id');
        $this->assertSame(Statement::sole()->batch_id, $row->batch_id);
        // QUEUE_CONNECTION=sync in tests, so the job runs inline and completes.
        $this->assertSame('sent', $row->status->value);
    }

    public function test_the_run_page_shows_progress(): void
    {
        Mail::fake();
        $this->billLastMonth();
        [$from, $to] = $this->period();
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('invoicing.statements.batch.store'), [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'customer_ids' => [$this->shop->id],
        ]);

        $batch = DocumentEmail::sole()->batch_id;

        $this->actingAs($office)
            ->get(route('invoicing.statements.batch.show', $batch))
            ->assertOk()
            ->assertSee('Kerry Organic Store')
            ->assertSee('1 sent');
    }

    public function test_a_failed_send_is_recorded_and_can_be_retried(): void
    {
        $this->billLastMonth();
        [$from, $to] = $this->period();
        $office = User::factory()->create();

        // Make the transport blow up so the failure path is exercised for real.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP refused'));

        $this->actingAs($office)->post(route('invoicing.statements.batch.store'), [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'customer_ids' => [$this->shop->id],
        ]);

        $row = DocumentEmail::sole();

        $this->assertSame('failed', $row->status->value);
        $this->assertStringContainsString('SMTP refused', $row->error);
        $this->assertNull(Statement::sole()->emailed_at, 'a failed send must not claim it went out');

        // And the retry re-queues it.
        Mail::fake();
        $this->actingAs($office)
            ->post(route('invoicing.statements.batch.retry', $row->batch_id))
            ->assertSessionHas('success');

        $this->assertSame('sent', $row->fresh()->status->value);
    }

    public function test_the_scheduled_command_queues_the_same_way(): void
    {
        Mail::fake();
        $this->billLastMonth();

        $this->artisan('invoicing:send-statements')->assertSuccessful();

        $this->assertSame(1, Statement::count());
        $this->assertSame(1, DocumentEmail::where('kind', 'statement')->count());
    }

    public function test_the_command_dry_run_sends_nothing(): void
    {
        Mail::fake();
        $this->billLastMonth();

        $this->artisan('invoicing:send-statements --dry-run')->assertSuccessful();

        $this->assertSame(0, Statement::count());
        Mail::assertNothingSent();
    }
}
