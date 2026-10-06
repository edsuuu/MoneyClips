<?php

declare(strict_types=1);

use App\Services\CutEdit\CutEditKeyframeService;
use App\Services\CutEdit\CutEditValidatorService;

beforeEach(function (): void {
    $this->composer = new CutEditKeyframeService;
    $this->wide = ['x' => 0.3418, 'y' => 0.0, 'w' => 0.3164, 'h' => 1.0];
});

function keyframeSpec(array $punches = [], array $captions = [], array $cuts = []): array
{
    return ['version' => 1, 'caption_preset' => 'verde', 'cuts' => $cuts, 'captions' => $captions, 'punches' => $punches, 'title' => 't', 'hashtags' => []];
}

it('keeps the self_check of render.py for rhythm and zoom', function (): void {
    $slow = [['t' => [1.0, 2.0], 'kind' => 'slow']];

    expect($this->composer->rhythmToggles([0.0, 0.5, 1.0, 1.6, 2.0, 3.2], [2.1]))->toBe([0.0, 1.6, 2.1])
        ->and($this->composer->zoomAt(1.5, $slow))->toEqualWithDelta(1.15, 0.0001)
        ->and($this->composer->zoomAt(2.0, $slow))->toBeNull()
        ->and($this->composer->zoomAt(1.5, [['t' => [1.0, 2.0], 'kind' => 'punch']]))->toBe(1.8)
        ->and($this->composer->zoomAt(1.5, [['t' => [1.0, 2.0], 'kind' => 'laugh']]))->toBe(2.5);
});

it('leaves the base keyframes untouched without punches or captions', function (): void {
    $base = [
        ['t' => 0.0, 'mode' => 'vertical', 'regions' => [$this->wide]],
        ['t' => 4.0, 'mode' => 'vertical', 'regions' => [['x' => 0.1, 'y' => 0.2, 'w' => 0.2, 'h' => 0.625]]],
    ];

    expect($this->composer->compose($base, keyframeSpec(), 10.0))->toBe($base)
        ->and($this->composer->compose($base, keyframeSpec([['t' => [1.0, 2.0], 'kind' => 'punch']]), 0.0))->toBe($base);
});

it('steps into a punch one frame before it and back out at its end', function (): void {
    $base = [['t' => 0.0, 'mode' => 'vertical', 'regions' => [$this->wide]]];
    $punched = ['x' => 0.4121, 'y' => 0.2389, 'w' => 0.1758, 'h' => 0.5556];

    $keyframes = $this->composer->compose($base, keyframeSpec([['t' => [2.0, 3.0], 'kind' => 'punch']]), 10.0);

    expect($keyframes)->toBe([
        ['t' => 0.0, 'mode' => 'vertical', 'regions' => [$this->wide]],
        ['t' => 1.967, 'mode' => 'vertical', 'regions' => [$this->wide]],
        ['t' => 2.0, 'mode' => 'vertical', 'regions' => [$punched]],
        ['t' => 2.967, 'mode' => 'vertical', 'regions' => [$punched]],
        ['t' => 3.0, 'mode' => 'vertical', 'regions' => [$this->wide]],
    ]);
});

it('caps the total zoom at 3x over the base zoom and keeps the face anchor', function (): void {
    $closeUp = ['x' => 0.4209, 'y' => 0.06, 'w' => 0.1582, 'h' => 0.5];
    $base = [['t' => 0.0, 'mode' => 'vertical', 'regions' => [$closeUp]]];

    $keyframes = $this->composer->compose($base, keyframeSpec([['t' => [1.0, 2.0], 'kind' => 'laugh']]), 5.0);
    $laugh = $keyframes[2]['regions'][0];

    expect($laugh['h'])->toBe(0.3333)
        ->and($laugh['x'] + $laugh['w'] / 2)->toEqualWithDelta(0.5, 0.0001)
        ->and($laugh['y'] + $laugh['h'] * 0.38)->toEqualWithDelta($closeUp['y'] + $closeUp['h'] * 0.38, 0.0001);
});

it('alternates the rhythm zoom on caption starts at least 1.5s apart', function (): void {
    $base = [['t' => 0.0, 'mode' => 'vertical', 'regions' => [$this->wide]]];
    $captions = [
        ['t' => [0.0, 0.4], 'text' => 'um', 'style' => 'speech', 'pos' => 'bottom'],
        ['t' => [0.5, 1.9], 'text' => 'dois', 'style' => 'speech', 'pos' => 'bottom'],
        ['t' => [1.0, 2.0], 'text' => '*nota*', 'style' => 'note', 'pos' => 'top'],
        ['t' => [2.0, 2.5], 'text' => 'três', 'style' => 'shout', 'pos' => 'bottom'],
    ];

    $keyframes = $this->composer->compose($base, keyframeSpec(captions: $captions), 5.0);

    expect(array_column($keyframes, 't'))->toBe([0.0, 1.967, 2.0])
        ->and($keyframes[0]['regions'][0]['h'])->toBe(0.7692)
        ->and($keyframes[2]['regions'][0])->toBe($this->wide);
});

it('composes the approved spec 08 into ordered keyframes inside the frame', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/spec-08-haaland-soco.json'), true, 512, JSON_THROW_ON_ERROR);
    $spec = (new CutEditValidatorService)->validate($fixture['spec'], $fixture['words'], $fixture['duration'])['spec'];
    $base = [];

    for ($shot = 0; $shot < 20; $shot++) {
        $base[] = ['t' => $shot * 6.0, 'mode' => 'vertical', 'regions' => [$shot % 2 === 0 ? $this->wide : ['x' => 0.55, 'y' => 0.05, 'w' => 0.1977, 'h' => 0.625]]];
    }

    $keyframes = $this->composer->compose($base, $spec, $fixture['duration']);
    $times = array_column($keyframes, 't');

    expect(count($keyframes))->toBeLessThan(400)
        ->and($times)->toBe(array_values(array_unique($times)))
        ->and($times)->toBe(collect($times)->sort()->values()->all());

    foreach ($keyframes as $keyframe) {
        $region = $keyframe['regions'][0];

        expect($region['h'])->toBeGreaterThanOrEqual(0.3333)
            ->and($region['x'])->toBeGreaterThanOrEqual(0.0)
            ->and($region['y'])->toBeGreaterThanOrEqual(0.0)
            ->and($region['x'] + $region['w'])->toBeLessThanOrEqual(1.0001)
            ->and($region['y'] + $region['h'])->toBeLessThanOrEqual(1.0001);
    }
});
