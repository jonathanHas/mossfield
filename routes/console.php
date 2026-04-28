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
