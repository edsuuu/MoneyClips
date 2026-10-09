<?php

declare(strict_types=1);

namespace App\Services\CutEdit;

/**
 * Porte do zoom_at, do rhythm_toggles e do miolo do loop de frames do
 * render.py: parte dos keyframes-base do face tracking (style=cuts, rosto a
 * 38% do topo do recorte) e multiplica o zoom nas punchlines e no ritmo
 * 1.0↔1.3. Degrau = dois keyframes a 1 frame de distância, porque o /reframe
 * interpola linear entre vizinhos.
 *
 * ponytail: ~4 keyframes por punch + 2 por troca de ritmo; o spec 08 (2 min,
 * 47 punches) dá 216. O ffmpeg 8.1 recusa o zoompan acima de ~94 keyframes
 * num mesmo trecho: o /reframe só aguenta isso com o trecho fatiado do PR11
 * (deploy do video antes).
 */
final readonly class CutEditKeyframeService
{
    private const array ZOOMS = ['punch' => 1.8, 'laugh' => 2.5, 'slow' => 1.3];

    private const float RHYTHM_ZOOM = 1.3;

    private const float RHYTHM_EVERY = 1.5;

    private const float MIN_SHOT = 0.4;

    private const float MAX_ZOOM = 3.0;

    private const float FACE_HEADROOM = 0.12;

    private const float DEFAULT_FACE_Y = 0.45;

    private const float FRAME = 1 / 30;

    /**
     * @param  list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>  $keyframes
     * @param  array{cuts: list<array{0: float, 1: float}>, captions: list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>, punches: list<array{t: array{0: float, 1: float}, kind: string}>, ...}  $spec
     * @return list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>
     */
    public function compose(array $keyframes, array $spec, float $duration): array
    {
        if ($keyframes === [] || $duration <= 0.0) {
            return $keyframes;
        }

        $starts = [];

        foreach ($spec['captions'] as $caption) {
            if (in_array($caption['style'], CutEditValidatorService::SPEECH_STYLES, true)) {
                $starts[] = $caption['t'][0];
            }
        }

        $splices = [];

        foreach ($spec['cuts'] as [$start, $end]) {
            if ($start > 0.0 && $end < $duration) {
                $splices[] = $end;
            }
        }

        $toggles = $this->rhythmToggles($starts, $splices);
        $changes = $toggles;

        foreach ($spec['punches'] as $punch) {
            $changes[] = $punch['t'][0];
            $changes[] = $punch['t'][1];
        }

        $times = [0.0];

        foreach ($keyframes as $keyframe) {
            $times[] = $keyframe['t'];
        }

        foreach ($changes as $change) {
            $times[] = $change - self::FRAME;
            $times[] = $change;
        }

        $composed = [];

        foreach ($this->sortedTimes($times, $duration) as $time) {
            $base = $this->baseAt($keyframes, $time);
            $zoom = $this->zoomAt($time, $spec['punches']) ?? ($this->countUpTo($toggles, $time) % 2 === 1 ? self::RHYTHM_ZOOM : 1.0);
            $regions = [];

            foreach ($base['regions'] as $region) {
                $regions[] = $this->zoomRegion($region, $zoom);
            }

            $composed[] = ['t' => $time, 'mode' => $base['mode'], 'regions' => $regions];
        }

        return $this->withoutRedundant($composed);
    }

    /**
     * Zoom do punch ativo em t (slow = rampa de 1.0 até o zoom do kind); null
     * fora de punch, onde vale o ritmo.
     *
     * @param  list<array{t: array{0: float, 1: float}, kind: string}>  $punches
     */
    public function zoomAt(float $time, array $punches): ?float
    {
        foreach ($punches as $punch) {
            [$start, $end] = $punch['t'];

            if ($time < $start) {
                continue;
            }

            if ($time >= $end) {
                continue;
            }

            $zoom = self::ZOOMS[$punch['kind']] ?? 1.0;

            if ($punch['kind'] === 'slow') {
                return 1 + ($zoom - 1) * ($time - $start) / max(0.01, $end - $start);
            }

            return $zoom;
        }

        return null;
    }

    /**
     * Alterna 1.0↔1.3 em todo jump cut e no início de legenda que vier ≥1.5s
     * depois da última troca.
     *
     * @param  list<float>  $starts
     * @param  list<float>  $cuts
     * @return list<float>
     */
    public function rhythmToggles(array $starts, array $cuts): array
    {
        $times = array_unique([...$starts, ...$cuts], SORT_REGULAR);
        sort($times);

        $toggles = [];

        foreach ($times as $time) {
            $gap = $toggles === [] ? 9.0 : $time - $toggles[count($toggles) - 1];

            if ((in_array($time, $cuts, true) && $gap >= self::MIN_SHOT) || $gap >= self::RHYTHM_EVERY) {
                $toggles[] = $time;
            }
        }

        return $toggles;
    }

    /**
     * @param  list<float>  $times
     * @return list<float>
     */
    private function sortedTimes(array $times, float $duration): array
    {
        $sorted = [];

        foreach ($times as $time) {
            if ($time >= 0.0 && $time <= $duration) {
                $sorted[] = round($time, 3);
            }
        }

        $sorted = array_values(array_unique($sorted, SORT_REGULAR));
        sort($sorted);

        return $sorted;
    }

    /**
     * Região do keyframe-base em t, do mesmo jeito que o /reframe: constante
     * antes do primeiro e depois do último, linear entre vizinhos do mesmo
     * modo, degrau na troca de modo.
     *
     * @param  non-empty-list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>  $keyframes
     * @return array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}
     */
    private function baseAt(array $keyframes, float $time): array
    {
        $previous = $keyframes[0];

        foreach ($keyframes as $index => $keyframe) {
            if ($keyframe['t'] <= $time) {
                $previous = $keyframe;

                continue;
            }

            if ($index === 0 || $keyframe['mode'] !== $previous['mode'] || count($keyframe['regions']) !== count($previous['regions'])) {
                return $previous;
            }

            $ratio = ($time - $previous['t']) / max(0.001, $keyframe['t'] - $previous['t']);
            $regions = [];

            foreach ($previous['regions'] as $slot => $region) {
                $target = $keyframe['regions'][$slot];
                $regions[] = [
                    'x' => $region['x'] + ($target['x'] - $region['x']) * $ratio,
                    'y' => $region['y'] + ($target['y'] - $region['y']) * $ratio,
                    'w' => $region['w'] + ($target['w'] - $region['w']) * $ratio,
                    'h' => $region['h'] + ($target['h'] - $region['h']) * $ratio,
                ];
            }

            return ['t' => $time, 'mode' => $previous['mode'], 'regions' => $regions];
        }

        return $previous;
    }

    /**
     * Zoom total = zoom-base da região × zoom pedido, teto 3.0. Com zoom, o
     * rosto fica a 12% da altura do recorte acima do centro (FACE_HEADROOM do
     * media); a posição do rosto sai da própria região-base.
     *
     * @param  array{x: float, y: float, w: float, h: float}  $region
     * @return array{x: float, y: float, w: float, h: float}
     */
    private function zoomRegion(array $region, float $zoom): array
    {
        if ($zoom === 1.0 || $region['h'] <= 0.0) {
            return array_map(static fn (float $value): float => round($value, 4), $region);
        }

        $baseZoom = 1 / $region['h'];
        $totalZoom = max($baseZoom, min(self::MAX_ZOOM, $zoom * $baseZoom));
        $height = 1 / $totalZoom;
        $width = $region['w'] * $height / $region['h'];
        $faceY = $baseZoom > 1.05 ? $region['y'] + $region['h'] / 2 - self::FACE_HEADROOM * $region['h'] : self::DEFAULT_FACE_Y;
        $centerX = $region['x'] + $region['w'] / 2;
        $centerY = $totalZoom > 1.05 ? $faceY + self::FACE_HEADROOM * $height : 0.5;

        return [
            'x' => round(min(max($centerX - $width / 2, 0.0), 1 - $width), 4),
            'y' => round(min(max($centerY - $height / 2, 0.0), 1 - $height), 4),
            'w' => round($width, 4),
            'h' => round($height, 4),
        ];
    }

    /**
     * @param  list<float>  $toggles
     */
    private function countUpTo(array $toggles, float $time): int
    {
        $count = 0;

        foreach ($toggles as $toggle) {
            $count += $toggle <= $time ? 1 : 0;
        }

        return $count;
    }

    /**
     * Keyframe igual aos dois vizinhos não muda nada na interpolação linear.
     *
     * @param  list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>  $keyframes
     * @return list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>
     */
    private function withoutRedundant(array $keyframes): array
    {
        $kept = [];

        foreach ($keyframes as $index => $keyframe) {
            $previous = $keyframes[$index - 1] ?? null;
            $next = $keyframes[$index + 1] ?? null;

            if (! is_null($previous) && ! is_null($next) && $this->sameFrame($previous, $keyframe) && $this->sameFrame($keyframe, $next)) {
                continue;
            }

            $kept[] = $keyframe;
        }

        return $kept;
    }

    /**
     * @param  array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}  $a
     * @param  array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}  $b
     */
    private function sameFrame(array $a, array $b): bool
    {
        return $a['mode'] === $b['mode'] && $a['regions'] === $b['regions'];
    }
}
