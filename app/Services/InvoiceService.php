<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Order;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issues and voids invoices.
 *
 * The one rule everything else follows from: an invoice is a SNAPSHOT. Order
 * lines keep moving after issue (a later pick, a weight correction, an edit
 * re-running Order::calculateTotals()), so every figure and every line is
 * copied at issue time and nothing is re-derived from the live order when the
 * document is rendered.
 */
class InvoiceService
{
    /**
     * Bill one order on its own invoice.
     *
     * @throws InvoicingException when the order hasn't been dispatched or is already billed
     */
    public function issueForOrder(Order $order, ?User $by = null, bool $allowConsolidated = false): Invoice
    {
        return DB::transaction(function () use ($order, $by, $allowConsolidated) {
            // Re-read under a row lock: two operators hitting "Issue" at once
            // must not mint two invoices for the same order.
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->invoice_id !== null) {
                throw new InvoicingException('This order has already been invoiced.');
            }

            // Only once it has left (OPEN_QUESTIONS #5, F-38a): a ready order can
            // still change at the van. DispatchService flips to dispatched first.
            if (! in_array($locked->status, ['dispatched', 'delivered'], true)) {
                throw new InvoicingException("An invoice is issued when the order is dispatched — this one hasn't left yet.");
            }

            $customer = $locked->customer()->firstOrFail();

            // A weekly/monthly customer is billed by the period run, one invoice
            // for many deliveries. Refusing here (not just hiding the button)
            // means no route — dispatch, the queue's open POST, a future caller
            // — can accidentally bill them per delivery.
            if (! $allowConsolidated && $customer->isConsolidatedBilling()) {
                throw new InvoicingException(
                    $customer->name.' is billed '.strtolower($customer->invoicing_mode->label()).
                    ' — use the consolidated run in Finance.'
                );
            }

            // Settle the order's own figures first so the snapshot matches what
            // was actually picked. Idempotent.
            $locked->calculateTotals();
            $locked->refresh();

            $invoice = $this->createInvoice($customer, collect([$locked]), 'per_order', $by);

            Order::whereKey($locked->getKey())->update(['invoice_id' => $invoice->id]);

            $invoice->refreshBalances();

            return $invoice->fresh(['lines', 'customer']);
        });
    }

    /**
     * Bill a period of a customer's deliveries on one consolidated invoice.
     *
     * @throws InvoicingException when nothing falls in the period
     */
    public function issueConsolidated(Customer $customer, Carbon $start, Carbon $end, ?User $by = null): Invoice
    {
        return DB::transaction(function () use ($customer, $start, $end, $by) {
            $orders = $this->candidateOrdersFor($customer, $start, $end, lock: true);

            if ($orders->isEmpty()) {
                throw new InvoicingException('No uninvoiced deliveries for '.$customer->name.' in that period.');
            }

            foreach ($orders as $order) {
                $order->calculateTotals();
            }

            $orders = $this->candidateOrdersFor($customer, $start, $end);

            $invoice = $this->createInvoice($customer, $orders, 'consolidated', $by, $start, $end);

            Order::whereIn('id', $orders->pluck('id'))->update(['invoice_id' => $invoice->id]);

            $invoice->refreshBalances();

            return $invoice->fresh(['lines', 'customer']);
        });
    }

    /**
     * Build (but do not save) what a consolidated invoice would look like, for
     * the run screen's preview. Consumes no invoice number — that is the whole
     * point of keeping preview separate from issue.
     */
    public function previewConsolidated(Customer $customer, Carbon $start, Carbon $end): Invoice
    {
        $orders = $this->candidateOrdersFor($customer, $start, $end);

        $invoice = new Invoice($this->headerAttributes($customer, $orders, 'consolidated', null, $start, $end));
        $invoice->number = 'PREVIEW';
        $invoice->setRelation('customer', $customer);

        $lines = collect($this->buildLines($orders))
            ->map(fn (array $attrs) => new InvoiceLine($attrs));

        $invoice->setRelation('lines', $lines);

        return $invoice;
    }

    /**
     * Orders eligible for a consolidated invoice: dispatched or delivered, not
     * already billed, filed inside the period.
     *
     * Period matching uses the same COALESCE the billing_date accessor does,
     * because Mossorders imports arrive with a null delivery_date and would
     * otherwise never fall into any period.
     *
     * @return Collection<int, Order>
     */
    public function candidateOrdersFor(Customer $customer, Carbon $start, Carbon $end, bool $lock = false): Collection
    {
        $query = Order::query()
            ->where('customer_id', $customer->id)
            ->whereNull('invoice_id')
            ->whereIn('status', (array) config('invoicing.consolidated_statuses', ['dispatched', 'delivered']))
            ->whereRaw(
                'COALESCE(delivery_date, DATE(delivered_at), order_date) BETWEEN ? AND ?',
                [$start->toDateString(), $end->toDateString()]
            )
            ->orderByRaw('COALESCE(delivery_date, DATE(delivered_at), order_date)')
            ->orderBy('id');

        if ($lock) {
            return $query->lockForUpdate()->get();
        }

        return $query->with([
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ])->get();
    }

    /**
     * Every customer with uninvoiced deliveries in the period — drives the
     * consolidated run screen.
     *
     * @return Collection<int, Customer>
     */
    public function consolidatedCandidates(Carbon $start, Carbon $end): Collection
    {
        return Customer::query()
            ->whereIn('invoicing_mode', ['weekly', 'monthly'])
            ->whereHas('orders', function ($q) use ($start, $end) {
                $q->whereNull('invoice_id')
                    ->whereIn('status', (array) config('invoicing.consolidated_statuses', ['dispatched', 'delivered']))
                    ->whereRaw(
                        'COALESCE(delivery_date, DATE(delivered_at), order_date) BETWEEN ? AND ?',
                        [$start->toDateString(), $end->toDateString()]
                    );
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Void an invoice and hand its orders back to the uninvoiced queue.
     *
     * @throws InvoicingException when money or credit is attached
     */
    public function void(Invoice $invoice, string $reason, ?User $by = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $locked = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isVoidable()) {
                throw new InvoicingException(
                    'This invoice has payments or credit notes against it. Remove those first, then void.'
                );
            }

            $locked->forceFill([
                'status' => 'void',
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            // Release the orders. invoice_lines.order_id keeps the history of
            // what this voided document actually billed.
            Order::where('invoice_id', $locked->id)->update([
                'invoice_id' => null,
                'payment_status' => 'pending',
            ]);

            $locked->refreshBalances();

            return $locked->fresh();
        });
    }

    public function pdf(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing(['lines.order', 'customer']);

        return Pdf::loadView('invoicing.invoices.pdf', [
            'invoice' => $invoice,
            'pdf' => true,
        ]);
    }

    // ------------------------------------------------------------- internals

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function createInvoice(
        Customer $customer,
        Collection $orders,
        string $type,
        ?User $by,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): Invoice {
        $number = DocumentSequence::format('INV', DocumentSequence::next(DocumentSequence::INVOICE));

        $invoice = Invoice::create(array_merge(
            $this->headerAttributes($customer, $orders, $type, $by, $start, $end),
            ['number' => $number]
        ));

        foreach ($this->buildLines($orders) as $attrs) {
            $invoice->lines()->create($attrs);
        }

        return $invoice;
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    private function headerAttributes(
        Customer $customer,
        Collection $orders,
        string $type,
        ?User $by,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): array {
        $issuedAt = today();
        $terms = (string) $customer->payment_terms;
        $days = (int) (config('invoicing.payment_terms_days')[$terms] ?? 0);

        $subtotal = round($orders->sum(fn (Order $o) => (float) $o->subtotal), 2);
        $deliveryNet = round($orders->sum(fn (Order $o) => (float) $o->delivery_charge_net), 2);
        $tax = round($orders->sum(fn (Order $o) => (float) $o->tax_amount), 2);

        return [
            'customer_id' => $customer->id,
            'type' => $type,
            'period_start' => $start?->toDateString(),
            'period_end' => $end?->toDateString(),
            'status' => 'issued',
            'issued_at' => $issuedAt->toDateString(),
            'due_date' => $issuedAt->copy()->addDays($days)->toDateString(),
            'payment_terms' => $terms,
            'bill_to_name' => $customer->name,
            'bill_to_address' => $this->billToAddress($customer),
            'bill_to_email' => $customer->billingEmail(),
            'bill_to_vat_number' => $customer->vat_number,
            'subtotal' => $subtotal,
            'delivery_charge_net' => $deliveryNet,
            'tax_amount' => $tax,
            'total' => round($subtotal + $deliveryNet + $tax, 2),
            'created_by' => $by?->id,
        ];
    }

    /**
     * Freeze the billable lines of the given orders.
     *
     * Mirrors what resources/views/orders/dispatch-confirmation.blade.php renders live:
     * one product line per order item (billing invoiceable_quantity /
     * invoiceable_total so a short-closed line charges only what was supplied),
     * then one delivery line per order carrying the whole 23% VAT.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function buildLines(Collection $orders): array
    {
        $lines = [];
        $sort = 0;

        foreach ($orders as $order) {
            $order->loadMissing([
                'orderItems.productVariant.product',
                'orderItems.orderAllocations.batchItem.batch',
            ]);

            foreach ($order->orderItems as $item) {
                $detail = null;

                if ($item->isPricedByWeight() && (float) $item->weight_fulfilled_kg > 0) {
                    $detail = number_format((float) $item->weight_fulfilled_kg, 3).' kg';
                }

                if ($item->isShortClosed()) {
                    // Keep the line even at €0.00: the customer ordered it and
                    // needs to see it was acknowledged and not charged, and it
                    // has to agree with the docket.
                    $shortNote = $item->quantity_fulfilled === 0
                        ? 'Not supplied — not charged.'
                        : $item->quantity_short.' of '.$item->quantity_ordered.' not supplied — not charged.';

                    $detail = $detail ? $detail.' · '.$shortNote : $shortNote;
                }

                $lines[] = [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'line_type' => InvoiceLine::TYPE_PRODUCT,
                    'description' => $item->productVariant?->full_name ?? 'Item',
                    'detail' => $detail,
                    'batch_codes' => $item->batch_codes ?: null,
                    'quantity' => $item->invoiceable_quantity,
                    'unit_price' => $item->unit_price,
                    'unit_price_label' => $item->unit_price_label,
                    'amount' => $item->invoiceable_total,
                    'vat_rate' => 0,
                    'vat_amount' => 0,
                    'sort' => $sort++,
                ];
            }

            if ((float) $order->delivery_charge > 0) {
                $label = $order->delivery_charge_percent
                    ? 'Delivery charge ('.rtrim(rtrim(number_format((float) $order->delivery_charge_percent, 2), '0'), '.').'%)'
                    : 'Delivery charge';

                $lines[] = [
                    'order_id' => $order->id,
                    'order_item_id' => null,
                    'line_type' => InvoiceLine::TYPE_DELIVERY,
                    'description' => $label,
                    'detail' => null,
                    'batch_codes' => null,
                    'quantity' => 1,
                    'unit_price' => null,
                    'unit_price_label' => null,
                    'amount' => $order->delivery_charge_net,
                    'vat_rate' => Order::DELIVERY_CHARGE_VAT_RATE * 100,
                    'vat_amount' => $order->tax_amount,
                    'sort' => $sort++,
                ];
            }
        }

        return $lines;
    }

    /** Address parts joined without the empty commas full_address leaves behind. */
    private function billToAddress(Customer $customer): ?string
    {
        $parts = array_filter([
            $customer->address,
            $customer->city,
            $customer->postal_code,
            $customer->country,
        ], fn ($p) => filled($p));

        return $parts ? implode("\n", $parts) : null;
    }
}
