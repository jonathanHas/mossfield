<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps orders.payment_status honest about overdue.
 *
 * Whether an invoice IS overdue is derived (balance > 0 and due_date passed), so
 * nothing here affects correctness on the finance screens. What this fixes is the
 * mirrored column the order list and badges read: an invoice quietly tips into
 * overdue at midnight with no write to trigger a re-sync.
 */
class RefreshOverdueInvoices extends Command
{
    protected $signature = 'invoicing:refresh-overdue';

    protected $description = 'Mirror overdue invoice state onto orders.payment_status';

    public function handle(): int
    {
        $flipped = 0;

        // Newly overdue: still owing, past due, and the mirror hasn't caught up.
        foreach (Invoice::overdue()->cursor() as $invoice) {
            $flipped += Order::where('invoice_id', $invoice->id)
                ->where('payment_status', '!=', 'overdue')
                ->update(['payment_status' => 'overdue']);
        }

        // And back again: an invoice paid or credited after going overdue, or one
        // whose due date was pushed out, must not stay flagged red.
        $notOverdue = Invoice::issued()
            ->where(fn ($q) => $q->where('balance', '<=', 0)->orWhereDate('due_date', '>=', today()))
            ->pluck('id');

        $cleared = Order::whereIn('invoice_id', $notOverdue)
            ->where('payment_status', 'overdue')
            ->get();

        foreach ($cleared as $order) {
            $order->invoice?->syncOrderPaymentStatus();
        }

        $message = "invoicing:refresh-overdue flagged {$flipped}, cleared {$cleared->count()}";

        $this->info($message);
        Log::channel('sync')->info($message);

        return self::SUCCESS;
    }
}
