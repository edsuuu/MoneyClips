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

/*
|--------------------------------------------------------------------------
| Auto-postagem por slots (agenda em banco)
|--------------------------------------------------------------------------
|
| A cada minuto o dispatcher busca slots devidos em schedule_slots (com
| tolerância de GRACE_MINUTES), reivindica cada um atomicamente
| (dispatched_at) e enfileira 1 job por plataforma habilitada na fila
| `posting`. Posters vivem na pasta da integração (App\Services\Api\* para
| APIs oficiais; App\Services\TikTokUploader para o microserviço) —
| implemente App\Services\AutoPost\PosterInterface e registre no
| AppServiceProvider; toggles em platform_settings (tela /agenda).
|
| Fuso: o scheduler usa o timezone da aplicação (config/app.php —
| America/Sao_Paulo). Requer cron: `* * * * * php artisan schedule:run`
| e um worker: `php artisan queue:listen --queue=posting,processing,default`.
|
*/
Schedule::call(function (): void {
    resolve(AutoPostDispatcherService::class)->dispatchDueSlots();
})
    ->name('auto-post-slots')
    ->everyMinute()
    ->withoutOverlapping();

// Sentinela: avisa no Discord sobre slots pulados/sem vídeo/com falha total
// (1x por slot via Cache::add). Não posta nada.
Schedule::command('auto-post:check-missed')
    ->name('auto-post-check-missed')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Estoque baixo: compara vídeos prontos × slots vazios dos próximos 7 dias.
Schedule::call(function (): void {
    resolve(StockAlertService::class)->warnIfLow();
})
    ->name('stock-alert')
    ->dailyAt('08:00');

// Observabilidade: microserviço sem heartbeat > 90s → Discord (1x por queda,
// com aviso de recuperação). Prune diário mantém service_logs em 14 dias.
Schedule::command('observability:check-heartbeats')
    ->name('observability-check-heartbeats')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('model:prune')
    ->name('model-prune')
    ->dailyAt('04:00');
