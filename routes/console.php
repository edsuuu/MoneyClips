<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune')
    ->name('model-prune')
    ->dailyAt('04:00');

Schedule::command('uploads:prune-stale')
    ->name('uploads-prune-stale')
    ->dailyAt('04:30')
    ->withoutOverlapping();
