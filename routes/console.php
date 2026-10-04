<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$importOrdersSchedule = Schedule::command('mossfield:import-online-orders')
    ->hourly()
    ->when(fn () => config('services.sync.enabled'))
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/sync.log'));

if ($alertEmail = env('SYNC_ALERT_EMAIL')) {
    $importOrdersSchedule->emailOutputOnFailure($alertEmail);
}

/*
|--------------------------------------------------------------------------
| Invoicing
|--------------------------------------------------------------------------
*/

// Mirror overdue state onto orders.payment_status. Correctness doesn't depend on
// this (the finance screens derive overdue live) — it keeps the order badges and
// filters truthful once a due date quietly passes at midnight.
Schedule::command('invoicing:refresh-overdue')
    ->dailyAt('02:00')
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/sync.log'));

// Month-end statements. Off by default: nobody wants these going out
// unattended until they've watched the manual run work once.
$statementSchedule = Schedule::command('invoicing:send-statements')
    ->monthlyOn((int) config('invoicing.statements.day_of_month', 1), '06:00')
    ->when(fn () => (bool) config('invoicing.statements.auto_send'))
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/sync.log'));

if ($alertEmail = env('SYNC_ALERT_EMAIL')) {
    $statementSchedule->emailOutputOnFailure($alertEmail);
}

// Production runs cron only — there is no queue worker (DEPLOYMENT.md §6), so
// the scheduler drains the queue itself. --stop-when-empty exits as soon as
// there is nothing to do, and --max-time keeps it inside the minute. Set
// INVOICING_QUEUE_VIA_SCHEDULER=false once a real supervised worker exists.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->when(fn () => (bool) config('invoicing.queue.run_via_scheduler'))
    ->withoutOverlapping()
    ->runInBackground();
