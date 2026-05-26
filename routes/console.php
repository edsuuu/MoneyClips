<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publica os posts agendados no horário marcado. Requer `php artisan schedule:work`
// (ou cron chamando schedule:run) e um worker de fila (`php artisan queue:work`).
Schedule::command('social:publish-due')->everyMinute()->withoutOverlapping();
