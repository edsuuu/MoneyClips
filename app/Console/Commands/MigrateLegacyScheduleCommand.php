<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduleSlot;
use App\Services\AutoPost\AutoPost;
use App\Services\AutoPost\WeekGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * One-shot do deploy da agenda em banco: materializa os slots da semana
 * corrente (horários futuros) + semana seguinte a partir da agenda legada
 * users.auto_post_schedule. Sem vídeo atribuído — o operador atribui na
 * /agenda (ou usa "Gerar próxima semana" que auto-atribui).
 *
 * Idempotente: aborta se schedule_slots já tem linhas.
 */
final class MigrateLegacyScheduleCommand extends Command
{
    /** @var string */
    protected $signature = 'schedule:migrate-legacy';

    /** @var string */
    protected $description = 'Materializa schedule_slots a partir da agenda legada (users.auto_post_schedule).';

    public function handle(WeekGenerator $generator): int
    {
        if (ScheduleSlot::query()->exists()) {
            $this->info('schedule_slots já tem dados — nada a migrar.');

            return self::SUCCESS;
        }

        $monday = CarbonImmutable::now(AutoPost::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY);

        $created = $generator->generate($monday, 0)
            + $generator->generate($monday->addWeek(), 0);

        $this->info(sprintf('%d slots criados (semana corrente + próxima). Atribua os vídeos na /agenda.', $created));

        return self::SUCCESS;
    }
}
