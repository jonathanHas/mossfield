<?php

/*
|--------------------------------------------------------------------------
| Invoicing & statements
|--------------------------------------------------------------------------
|
| Seller identity printed on invoices, credit notes and statements, plus the
| knobs for the scheduled statement run and the scheduler-driven queue worker.
| Kept in config (not a settings table) to match config/mossfield.php.
|
*/

return [
    'seller' => [
        'name' => env('INVOICING_SELLER_NAME', 'Mossfield Organic Farm'),
        // Pipe-separated so a multi-line address fits one env var.
        'address_lines' => array_values(array_filter(array_map(
            'trim',
            explode('|', (string) env('INVOICING_SELLER_ADDRESS', ''))
        ), fn ($line) => $line !== '')),
        'vat_number' => env('INVOICING_VAT_NUMBER'),
        'email' => env('INVOICING_SELLER_EMAIL'),
        'phone' => env('INVOICING_SELLER_PHONE'),
    ],

    'bank' => [
        'name' => env('INVOICING_BANK_NAME'),
        'iban' => env('INVOICING_IBAN'),
        'bic' => env('INVOICING_BIC'),
    ],

    /*
    | Days added to the issue date to get the due date, keyed by the customer's
    | payment_terms. Moved out of resources/views/orders/invoice.blade.php so
    | the service and the template agree.
    */
    'payment_terms_days' => [
        'immediate' => 0,
        'net_7' => 7,
        'net_14' => 14,
        'net_30' => 30,
    ],

    'payment_terms_labels' => [
        'immediate' => 'Immediate',
        'net_7' => 'Net 7 days',
        'net_14' => 'Net 14 days',
        'net_30' => 'Net 30 days',
    ],

    /*
    | Order statuses a consolidated invoice will sweep up. Per-order invoicing
    | uses Order::hasReachedReady() instead.
    */
    'consolidated_statuses' => ['dispatched', 'delivered'],

    'reminder' => [
        'min_days_overdue' => (int) env('INVOICING_REMINDER_MIN_DAYS', 1),
    ],

    'statements' => [
        'auto_send' => (bool) env('INVOICING_AUTO_STATEMENTS', false),
        'day_of_month' => (int) env('INVOICING_STATEMENT_DAY', 1),
    ],

    'queue' => [
        // Production runs cron only (DEPLOYMENT.md §6), so the scheduler drains
        // the queue itself. Set false once a real worker/supervisor exists.
        'run_via_scheduler' => (bool) env('INVOICING_QUEUE_VIA_SCHEDULER', true),
    ],
];
