<?php

declare(strict_types=1);

namespace App\Services\AutoPost;

use App\Models\ScheduleSlot;
use App\Models\User;
use App\Models\YoutubeShort;
use Carbon\CarbonImmutable;

final readonly class WeekGeneratorService
{
    /**
     * @param  int  $videosPerDay  Quantos slots por dia recebem vídeo (0 = só cria os horários).
     * @return int Slots criados.
     */
    public function generate(CarbonImmutable $monday, int $videosPerDay): int
    {
        $monday = $monday->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $now = CarbonImmutable::now();
        $weekdayTimes = $this->weekdayTimes($monday);

        $ready = YoutubeShort::query()->readyToSchedule()->orderBy('ready_at')->get()->values();
        $readyIndex = 0;
        $created = 0;

        for ($i = 0; $i < 7; $i++) {
            $day = $monday->addDays($i);

            $existing = array_values(array_map(
                static fn ($time): string => mb_substr((string) $time, 0, 5),
                ScheduleSlot::query()->where('slot_date', $day->toDateString())->pluck('slot_time')->all(),
            ));

            $assignedToday = 0;

            foreach ($weekdayTimes[$day->dayOfWeekIso] ?? [] as $time) {
                if (count($existing) >= ScheduleSlot::MAX_PER_DAY) {
                    break;
                }

                if (in_array($time, $existing, true)) {
                    continue;
                }

                if ($day->setTimeFromTimeString($time)->lessThanOrEqualTo($now)) {
                    continue;
                }

                $short = null;
                if ($assignedToday < $videosPerDay && isset($ready[$readyIndex])) {
                    $short = $ready[$readyIndex];
                    $readyIndex++;
                    $assignedToday++;
                }

                ScheduleSlot::query()->create([
                    'slot_date' => $day->toDateString(),
                    'slot_time' => $time.':00',
                    'youtube_short_id' => $short?->id,
                    'is_active' => true,
                ]);

                $existing[] = $time;
                $created++;
            }
        }

        return $created;
    }

    /**
     * Horários por dia da semana (ISO 1=Seg..7=Dom), já ordenados.
     *
     * @return array<int, list<string>>
     */
    private function weekdayTimes(CarbonImmutable $monday): array
    {
        $latest = ScheduleSlot::query()
            ->where('slot_date', '<', $monday->toDateString())
            ->max('slot_date');

        if (is_string($latest) && $latest !== '') {
            $previousMonday = CarbonImmutable::parse($latest)->startOfWeek(CarbonImmutable::MONDAY);
            $slots = ScheduleSlot::query()->forWeek($previousMonday)->orderBy('slot_time')->get();

            if ($slots->isNotEmpty()) {
                $map = array_fill_keys(range(1, 7), []);
                foreach ($slots as $slot) {
                    $map[$slot->slot_date->dayOfWeekIso][] = $slot->timeLabel();
                }

                return array_map(static fn (array $times): array => array_values(array_unique($times)), $map);
            }
        }

        return $this->legacySchedule() ?? array_fill_keys(range(1, 7), []);
    }

    /**
     * Agenda legada saneada (users.auto_post_schedule do 1º user) ou null
     * quando nunca configurada / totalmente vazia.
     *
     * @return array<int, list<string>>|null
     */
    private function legacySchedule(): ?array
    {
        /** @var mixed $raw */
        $raw = User::query()->orderBy('id')->first()?->auto_post_schedule;
        if (! is_array($raw)) {
            return null;
        }

        $schedule = [];
        $total = 0;
        foreach (range(1, 7) as $dayOfWeek) {
            $times = $raw[$dayOfWeek] ?? [];
            $valid = [];
            foreach (is_array($times) ? $times : [] as $time) {
                if (is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
                    $valid[] = $time;
                }
            }

            $valid = array_values(array_unique($valid));
            sort($valid);
            $schedule[$dayOfWeek] = $valid;
            $total += count($valid);
        }

        return $total > 0 ? $schedule : null;
    }
}
