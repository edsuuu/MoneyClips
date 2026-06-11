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
