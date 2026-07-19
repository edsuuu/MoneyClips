<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduleSlot;
use App\Services\AutoPost\WeekGeneratorService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class MigrateLegacyScheduleCommand extends Command
{
    /** @var string */
    protected $signature = 'schedule:migrate-legacy';

    /** @var string */
    protected $description = 'Materializa schedule_slots a partir da agenda legada (users.auto_post_schedule).';

    public function handle(WeekGeneratorService $generator): int
    {
        if (ScheduleSlot::query()->exists()) {
            $this->info('schedule_slots já tem dados — nada a migrar.');

            return self::SUCCESS;
        }

        $monday = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);

        $created = $generator->generate($monday, 0)
            + $generator->generate($monday->addWeek(), 0);

        $this->info(sprintf('%d slots criados (semana corrente + próxima). Atribua os vídeos na /agenda.', $created));

        return self::SUCCESS;
    }
}
