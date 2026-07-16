<?php

declare(strict_types=1);

use App\Services\AutoPost\AutoPost;
use App\Services\AutoPost\AutoPostDispatcher;
use App\Services\AutoPost\StockAlert;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Auto-postagem por slots (agenda em banco)
|--------------------------------------------------------------------------
|
| A cada minuto o dispatcher busca slots devidos em schedule_slots (com
| tolerância de GRACE_MINUTES), reivindica cada um atomicamente
| (dispatched_at) e enfileira 1 job por plataforma habilitada na fila
| `posting`. Plataformas são Posters em App\Services\AutoPost\Posters\ —
| adicione novos lá e registre no AppServiceProvider; toggles em
| platform_settings (tela /agenda).
|
| Requer cron: `* * * * * php artisan schedule:run` (ou `schedule:work`)
| e um worker de fila: `php artisan queue:listen --queue=posting,processing,default`.
|
*/
Schedule::call(function (): void {
    resolve(AutoPostDispatcher::class)->dispatchDueSlots();
})
    ->name('auto-post-slots')
    ->everyMinute()
    ->timezone(AutoPost::TIMEZONE)
    ->withoutOverlapping();

// Sentinela: avisa no Discord sobre slots pulados/sem vídeo/com falha total
// (1x por slot via Cache::add). Não posta nada.
Schedule::command('auto-post:check-missed')
    ->name('auto-post-check-missed')
    ->everyTenMinutes()
    ->timezone(AutoPost::TIMEZONE)
    ->withoutOverlapping();

// Estoque baixo: compara vídeos prontos × slots vazios dos próximos 7 dias.
Schedule::call(function (): void {
    resolve(StockAlert::class)->warnIfLow();
})
    ->name('stock-alert')
    ->dailyAt('08:00')
    ->timezone(AutoPost::TIMEZONE);

// Observabilidade: microserviço sem heartbeat > 90s → Discord (1x por queda,
// com aviso de recuperação). Prune diário mantém service_logs em 14 dias.
Schedule::command('observability:check-heartbeats')
    ->name('observability-check-heartbeats')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('model:prune')
    ->name('model-prune')
    ->dailyAt('04:00')
    ->timezone(AutoPost::TIMEZONE);
