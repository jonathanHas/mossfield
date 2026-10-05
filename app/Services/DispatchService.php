<?php

namespace App\Services;

use App\Documents\DispatchConfirmation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place an order goes out the door.
 *
 * Dispatching does three things, in this order, each insulated from the next:
 *
 *  1. Flip the order to `dispatched` — its own short transaction under a row
 *     lock, so the run-level button and a per-order click can't both do it.
 *  2. Issue the numbered invoice for a PER-ORDER customer. Consolidated
 *     (weekly/monthly) customers are skipped: they're billed by the period run
 *     in Finance. InvoiceService opens its own transaction, so a failed issue
 *     cannot roll dispatch back — it's recorded on the result and the order sits
 *     in Finance → Awaiting invoice as the safety net.
 *  3. Email the priced Dispatch Confirmation. Queued for the run path (N
 *     PDF renders + SMTP round-trips don't belong in one request), sent inline
 *     for a single order so the operator gets an immediate flash. The invoice
 *     itself is deliberately NOT emailed here — office sends it from Finance.
 */
class DispatchService
{
    public function __construct(
        private InvoiceService $invoices,
        private DocumentMailer $mailer,
    ) {}

    /**
     * @throws DispatchException when the order isn't ready
     */
    public function dispatch(
        Order $order,
        ?User $by = null,
        bool $queueEmail = false,
        ?string $batchId = null,
    ): DispatchResult {
        // 1. Status flip, locked and re-checked.
        DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'ready') {
                throw new DispatchException('Only ready orders can be dispatched.');
            }
            // Both buttons (the order page and the run sheet) share this rule:
            // an order goes out only once it's ticked as loaded (OPEN_QUESTIONS #5, F-34).
            if ($locked->loaded_at === null) {
                throw new DispatchException('Tick the order as loaded onto the van first.');
            }

            $locked->update(['status' => 'dispatched', 'dispatched_at' => now()]);
        });

        $order->refresh()->loadMissing([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        $result = new DispatchResult($order);

        // 2. Invoice — per-order customers only; failure is recorded, not thrown.
        if ($order->invoice_id !== null) {
            $result->invoice = $order->invoice;
        } elseif (! $order->customer->isConsolidatedBilling()) {
            try {
                $result->invoice = $this->invoices->issueForOrder($order, $by);
                $result->invoiceIssued = true;
                $order->refresh();
            } catch (InvoicingException $e) {
                $result->invoiceError = $e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $result->invoiceError = 'Invoice could not be issued — see the log.';
            }
        }

        // 3. Confirmation email — no address is a skip, not a failure.
        $document = new DispatchConfirmation($order);

        try {
            $result->emailRow = $queueEmail
                ? $this->mailer->queue($document, $by, batchId: $batchId)
                : $this->mailer->sendNow($document, $by);
        } catch (InvoicingException $e) {
            $result->emailSkippedReason = $e->getMessage();
        }

        return $result;
    }
}
