<?php

declare(strict_types=1);

use App\Services\AutoPost\AutoPostDispatcherService;
use App\Services\AutoPost\StockAlertService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();
})
    ->name('auto-post-slots')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('auto-post:check-missed')
    ->name('auto-post-check-missed')
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::call(function (): void {
    resolve(StockAlertService::class)->warnIfLow();
})
    ->name('stock-alert')
    ->dailyAt('08:00');

Schedule::command('observability:check-heartbeats')
    ->name('observability-check-heartbeats')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('model:prune')
    ->name('model-prune')
    ->dailyAt('04:00');

Schedule::command('uploads:prune-stale')
    ->name('uploads-prune-stale')
    ->dailyAt('04:30')
    ->withoutOverlapping();
