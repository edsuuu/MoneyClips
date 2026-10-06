<?php

declare(strict_types=1);

use App\Services\CutSuggestion\CutSuggestionData;
use App\Services\CutSuggestion\CutSuggestionValidatorService;

beforeEach(function (): void {
    config([
        'services.cut_suggestion.min_duration' => 70,
        'services.cut_suggestion.max_duration' => 170,
        'services.cut_suggestion.min_gap' => 0.0,
        'services.cut_suggestion.max_cuts' => 20,
    ]);

    $this->validator = new CutSuggestionValidatorService;
});

it('drops a suggestion shorter than the minimum duration', function (): void {
    $suggestions = $this->validator->validate([
        new CutSuggestionData(10.0, 50.0, 9, 'curto demais'),
        new CutSuggestionData(100.0, 175.0, 8, 'bom'),
    ], 600.0);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->start)->toBe(100.0);
});

it('discards a suggestion longer than the maximum duration instead of truncating it', function (): void {
    $suggestions = $this->validator->validate([
        new CutSuggestionData(10.0, 400.0, 9, 'longo demais'),
    ], 600.0);

    expect($suggestions)->toBe([]);
});

it('clamps a suggestion that runs past the end of the video', function (): void {
    $suggestions = $this->validator->validate([
        new CutSuggestionData(100.0, 900.0, 9, 'passou do fim'),
    ], 200.0);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->end)->toBe(200.0);
});

it('never trusts the order the model returned', function (): void {
    $suggestions = $this->validator->validate([
        new CutSuggestionData(300.0, 370.0, 7, 'terceiro'),
        new CutSuggestionData(10.0, 80.0, 8, 'primeiro'),
        new CutSuggestionData(150.0, 220.0, 6, 'segundo'),
    ], 600.0);

    expect(array_map(static fn (CutSuggestionData $cut): float => $cut->start, $suggestions))
        ->toBe([10.0, 150.0, 300.0]);
});

it('keeps the higher score when two suggestions sit closer than the minimum gap', function (): void {
    config(['services.cut_suggestion.min_gap' => 1.0]);

    $suggestions = $this->validator->validate([
        new CutSuggestionData(10.0, 80.0, 4, 'fraco'),
        new CutSuggestionData(80.5, 150.5, 9, 'forte'),
    ], 600.0);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->score)->toBe(9)
        ->and($suggestions[0]->reason)->toBe('forte');
});

it('discards the weaker overlap instead of shifting it', function (): void {
    $suggestions = $this->validator->validate([
        new CutSuggestionData(10.0, 80.0, 9, 'forte'),
        new CutSuggestionData(79.5, 150.5, 2, 'fraco'),
    ], 600.0);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->score)->toBe(9);
});

it('keeps two suggestions that only touch each other', function (): void {
    $suggestions = $this->validator->validate([
        new CutSuggestionData(395.5, 509.7, 8, 'antes'),
        new CutSuggestionData(509.7, 613.4, 6, 'suco'),
    ], 2000.0);

    expect(array_map(static fn (CutSuggestionData $cut): string => $cut->reason, $suggestions))
        ->toBe(['antes', 'suco']);
});

it('caps the number of cuts at the configured maximum', function (): void {
    config(['services.cut_suggestion.max_cuts' => 3]);

    $raw = [];
    for ($index = 0; $index < 10; $index++) {
        $start = 100.0 * $index;
        $raw[] = new CutSuggestionData($start, $start + 70.0, 5, 'corte '.$index);
    }

    expect($this->validator->validate($raw, 5000.0))->toHaveCount(3);
});

it('caps by score and returns the survivors in time order', function (): void {
    config(['services.cut_suggestion.max_cuts' => 2]);

    $suggestions = $this->validator->validate([
        new CutSuggestionData(0.0, 80.0, 3, 'cedo e fraco'),
        new CutSuggestionData(400.0, 480.0, 7, 'tardio'),
        new CutSuggestionData(200.0, 280.0, 9, 'forte'),
    ], 600.0);

    expect(array_map(static fn (CutSuggestionData $cut): string => $cut->reason, $suggestions))
        ->toBe(['forte', 'tardio']);
});

it('returns nothing when the model answers with nothing usable', function (): void {
    expect($this->validator->validate([], 600.0))->toBe([]);
});
