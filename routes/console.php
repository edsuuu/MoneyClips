<?php

declare(strict_types=1);

use App\Support\PostingSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Agendamentos
|--------------------------------------------------------------------------
|
| Requer um worker de fila ativo (php artisan queue:work) e o scheduler
| (php artisan schedule:work, ou cron com schedule:run a cada minuto).
|
*/

// Publica os ScheduledPosts (cortes agendados) que venceram.
Schedule::command('social:publish-due')->everyMinute()->withoutOverlapping();

/*
| Auto-postagem unificada: o MESMO vídeo vai pro YouTube e pro TikTok, 5x/dia.
| Quem sorteia é o Laravel (fonte única: tabela youtube_shorts) — o uploader do
| TikTok só posta o vídeo que recebe. O Short é reservado no sorteio para nunca
| repetir; o sucesso de cada plataforma é gravado em posted_youtube_at /
| posted_tiktok_at.
|
| Em vez da hora cheia, cada janela de 1h posta num MINUTO ALEATÓRIO estável
| por dia (ex.: hoje 09:14, amanhã 09:37). As janelas ficam em
| App\Support\PostingSchedule::WINDOWS (09/12/15/18/21, fuso São Paulo). O
| comando roda a cada minuto, mas o ->when() só libera no minuto sorteado.
*/
Schedule::command('social:dispatch-posts')
    ->everyMinute()
    ->timezone(PostingSchedule::TIMEZONE)
    ->when(static fn (): bool => PostingSchedule::dueWindow() !== null)
    ->withoutOverlapping();
