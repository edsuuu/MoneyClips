<?php

declare(strict_types=1);

use App\Services\AutoPostDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Auto-postagem unificada (YouTube + TikTok)
|--------------------------------------------------------------------------
|
| O MESMO vídeo do estoque (youtube_shorts) vai pro YouTube e pro TikTok,
| 5x/dia. As janelas ficam em App\Services\AutoPostDispatcher::WINDOWS
| (09/12/15/18/21, fuso São Paulo); cada janela dispara num MINUTO ALEATÓRIO
| estável por dia. O scheduler roda a cada minuto, mas o ->when() só libera no
| minuto sorteado. Sem jobs/commands: o dispatcher faz tudo (síncrono no
| YouTube, webhook no TikTok).
|
| Requer cron: `* * * * * php artisan schedule:run` (ou `php artisan schedule:work`).
|
*/
Schedule::call(function (): void {
    resolve(AutoPostDispatcher::class)->run();
})
    ->name('auto-post-social')
    ->everyMinute()
    ->timezone(AutoPostDispatcher::TIMEZONE)
    ->when(static fn (): bool => AutoPostDispatcher::isDueWindow())
    ->withoutOverlapping();
