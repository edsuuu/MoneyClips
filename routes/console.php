<?php

declare(strict_types=1);

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
| Auto-postagem de Shorts nos horários de maior engajamento (fuso de São
| Paulo). Edite os horários abaixo à vontade. Cada execução também checa o
| estoque e avisa no Discord quando os Shorts a postar ficam abaixo do
| limiar (ex.: 20%).
*/
$hours = ['09:00', '12:00', '15:00', '18:00', '20:00', '22:00'];

foreach ($hours as $hour) {
    Schedule::command('youtube:dispatch-posts')
        ->timezone('America/Sao_Paulo')
        ->at($hour)
        ->withoutOverlapping();
}

/*
| Auto-postagem no TikTok via microserviço tiktok-uploader (porta 8780).
| Horários deslocados dos do YouTube para espalhar a atividade. O uploader
| posta em série (navegador único) e grava o status em tiktok_posts.
*/
$tiktokHours = ['10:00', '13:00', '16:00', '19:00', '21:00'];

foreach ($tiktokHours as $hour) {
    Schedule::command('tiktok:dispatch-posts')
        ->timezone('America/Sao_Paulo')
        ->at($hour)
        ->withoutOverlapping();
}
