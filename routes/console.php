<?php

declare(strict_types=1);

use App\Services\AutoPost\AutoPostDispatcher;
use App\Services\AutoPost\WindowSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Auto-postagem unificada (YouTube + TikTok + ...)
|--------------------------------------------------------------------------
|
| Roda o AutoPostDispatcher (orquestrador) nas janelas de WindowSchedule
| (09/12/15/18/21, SP); cada janela dispara num MINUTO ALEATÓRIO estável por
| dia. O scheduler roda a cada minuto, mas o ->when() só libera no minuto
| sorteado. Cada plataforma é um Poster em App\Services\AutoPost\Posters\ —
| adicione novos lá e registre em AppServiceProvider.
|
| Requer cron: `* * * * * php artisan schedule:run` (ou `php artisan schedule:work`).
|
*/
Schedule::call(function (): void {
    resolve(AutoPostDispatcher::class)->run();
})
    ->name('auto-post-social')
    ->everyMinute()
    ->timezone(WindowSchedule::TIMEZONE)
    ->when(static fn (): bool => WindowSchedule::isDueWindow())
    ->withoutOverlapping();

// Sentinela: a cada 10 min, varre slots passados sem postagem e dispara
// alerta no Discord (1x por slot via Cache::add). Não posta nada — só avisa
// pro operador clicar "Forçar agora" na /agenda.
Schedule::command('auto-post:check-missed')
    ->name('auto-post-check-missed')
    ->everyTenMinutes()
    ->timezone(WindowSchedule::TIMEZONE)
    ->withoutOverlapping();
