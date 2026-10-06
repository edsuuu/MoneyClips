<?php

declare(strict_types=1);

use App\Models\VideoCutEdit;
use App\Services\CutEdit\CutEditValidatorService;

beforeEach(function (): void {
    $this->validator = new CutEditValidatorService;
    $this->fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/spec-17-sovaco-peixe.json'), true, 512, JSON_THROW_ON_ERROR);
});

function validateCutEdit(array $fixture, array $changes = []): array
{
    return (new CutEditValidatorService)->validate([...$fixture['spec'], ...$changes], $fixture['words'], $fixture['duration']);
}

function captionsWithStyle(array $captions, array $indexes, string $style): array
{
    foreach ($indexes as $index) {
        $captions[$index]['style'] = $style;
    }

    return $captions;
}

it('passes the approved prototype spec 17 converted to clip time', function (): void {
    $result = validateCutEdit($this->fixture);
    $spec = $result['spec'];
    $first = $this->fixture['spec']['captions'][0];

    expect($result['hard'])->toBe([])
        ->and($result['soft'])->toBe([])
        ->and($spec['version'])->toBe(1)
        ->and($spec['caption_preset'])->toBeIn(CutEditValidatorService::CAPTION_PRESETS)
        ->and($spec['title'])->toBe('CASTANHARI beijou o SOVACO de língua 😂😂😂 @programapanico')
        ->and($spec['hashtags'])->toHaveCount(6)
        ->and($spec['cuts'])->toBe([[0.0, 0.73], [95.05, 96.0]])
        ->and($spec['captions'])->toHaveCount(72)
        ->and(array_column($spec['captions'], 'style'))->toContain('shout', 'note')
        ->and($spec['captions'][0]['t'][0])->toBe(round($this->fixture['words'][$first['w'][0]]['start'] - 0.08, 2))
        ->and($spec['punches'])->toHaveCount(12);
});

it('closes the approved prototype spec 08 but asks for the style band it predates', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/spec-08-haaland-soco.json'), true, 512, JSON_THROW_ON_ERROR);

    $result = validateCutEdit($fixture);

    expect($result['hard'])->toBe([])
        ->and($result['soft'])->toContain('7% dos blocos fora de speech (alvo 12–20%)');
});

it('times each caption from its words with the draft rule', function (): void {
    $words = [
        ['word' => 'qual', 'start' => 0.5, 'end' => 0.8],
        ['word' => 'a', 'start' => 0.8, 'end' => 1.0],
        ['word' => 'comida', 'start' => 1.0, 'end' => 1.4],
        ['word' => 'favorita', 'start' => 1.5, 'end' => 2.0],
        ['word' => 'dele?', 'start' => 6.0, 'end' => 6.5],
    ];

    $spec = $this->validator->validate(['captions' => [
        ['w' => [4, 4], 'text' => 'dele?', 'pos' => 'top', 'style' => 'speech'],
        ['w' => [0, 2], 'text' => 'qual a comida', 'pos' => 'bottom', 'style' => 'speech'],
        ['w' => [3, 3], 'text' => 'FAVORITA', 'pos' => 'bottom', 'style' => 'shout'],
    ]], $words, 6.6)['spec'];

    expect($spec['captions'])->toBe([
        ['t' => [0.42, 1.42], 'text' => 'qual a comida', 'style' => 'speech', 'pos' => 'bottom'],
        ['t' => [1.42, 2.25], 'text' => 'FAVORITA', 'style' => 'shout', 'pos' => 'bottom'],
        ['t' => [5.92, 6.6], 'text' => 'dele?', 'style' => 'speech', 'pos' => 'top'],
    ]);
});

it('rejects without retry when the edit drops below 60 seconds', function (): void {
    $result = validateCutEdit($this->fixture, ['cuts' => [[0.0, 0.73], [10.0, 50.0], [95.05, 96.0]]]);

    expect($result['hard'])->toContain('duração 54s < 60')
        ->and($result['hard'])->toContain('removido 42% do trecho');
});

it('rejects a stitched edit and a long inner cut', function (): void {
    $stitched = validateCutEdit($this->fixture, ['cuts' => [[20.0, 23.0], [40.0, 43.0]]]);
    $longCut = validateCutEdit($this->fixture, ['cuts' => [[5.0, 51.0]]]);

    expect($stitched['hard'])->toBe(['costura: gaps 3.0s, 3.0s'])
        ->and($longCut['hard'])->toContain('corte interno de 46s > 45s');
});

it('rejects when the AI says the cut does not work', function (): void {
    $result = validateCutEdit($this->fixture, ['verdict' => 'reject', 'reason' => 'não tem graça']);

    expect($result['hard'])->toBe(['IA recusou o corte: não tem graça']);
});

it('asks for a retry when one emphasis style repeats 3 blocks in a row', function (): void {
    $captions = captionsWithStyle($this->fixture['spec']['captions'], [20, 21, 22], 'punch');

    $result = validateCutEdit($this->fixture, ['captions' => $captions]);

    expect($result['hard'])->toBe([])
        ->and($result['soft'])->toHaveCount(1)
        ->and($result['soft'][0])->toStartWith('3 blocos seguidos com style punch');
});

it('asks for a retry when the styled blocks fall outside 12–20%', function (): void {
    $plain = captionsWithStyle($this->fixture['spec']['captions'], range(0, 66), 'speech');
    $loud = captionsWithStyle($this->fixture['spec']['captions'], range(0, 66, 4), 'art');

    expect(validateCutEdit($this->fixture, ['captions' => $plain])['soft'])->toBe(['7% dos blocos fora de speech (alvo 12–20%)'])
        ->and(validateCutEdit($this->fixture, ['captions' => $loud])['soft'])->toContain('36% dos blocos fora de speech (alvo 12–20%)');
});

it('asks for a retry when a note covers speech', function (): void {
    $notes = [...$this->fixture['spec']['notes'], ['t' => [10.0, 12.0], 'text' => '*por cima*', 'pos' => 'bottom']];

    $result = validateCutEdit($this->fixture, ['notes' => $notes]);

    expect($result['soft'])->toBe(['nota por cima de fala: "*por cima*"']);
});

it('asks for a retry on missing title and bad hashtags', function (): void {
    $result = validateCutEdit($this->fixture, ['title' => '  ', 'hashtags' => ['#cortes', '#Cortes', 'podpah']]);

    expect($result['soft'])->toBe(['título vazio', 'hashtags: precisa de 4 a 6, sem repetir (veio 3)']);
});

it('asks for a retry on a punch with an unknown kind', function (): void {
    $punches = [...$this->fixture['spec']['punches'], ['t' => [5.0, 6.0], 'kind' => 'shake']];

    $result = validateCutEdit($this->fixture, ['punches' => $punches]);

    expect($result['soft'])->toBe(['punch 12: tempo ou kind inválido'])
        ->and($result['spec']['punches'])->toHaveCount(12);
});

it('asks for a retry when captions repeat or skip words', function (): void {
    $captions = $this->fixture['spec']['captions'];
    $captions[5]['w'] = [$captions[4]['w'][0], $captions[5]['w'][1]];
    $missing = array_slice($this->fixture['spec']['captions'], 0, 55);
    $outOfRange = [...$this->fixture['spec']['captions'], ['w' => [5, 999], 'text' => 'x', 'pos' => 'bottom', 'style' => 'speech']];

    expect(validateCutEdit($this->fixture, ['captions' => $captions])['soft'])->toHaveCount(1)
        ->and(validateCutEdit($this->fixture, ['captions' => $captions])['soft'][0])->toContain('repete palavras')
        ->and(validateCutEdit($this->fixture, ['captions' => $missing])['soft'])->toContain('legenda cobre 83% das palavras mantidas (mín 95%)')
        ->and(validateCutEdit($this->fixture, ['captions' => $outOfRange])['soft'])->toBe(['legenda 67: índices w inválidos']);
});

it('drops captions whose words were all cut and merges overlapping cuts', function (): void {
    $merged = validateCutEdit($this->fixture, ['cuts' => [[-3.0, 0.73], [95.05, 120.0], [95.5, 96.0]]]);
    $trimmed = validateCutEdit($this->fixture, ['cuts' => [[0.0, 0.73], [90.0, 96.0]]]);
    $lastWord = $this->fixture['words'][$this->fixture['spec']['captions'][66]['w'][0]];

    expect($merged['spec']['cuts'])->toBe([[0.0, 0.73], [95.05, 96.0]])
        ->and($lastWord['start'])->toBeGreaterThan(90.0)
        ->and(array_column($trimmed['spec']['captions'], 'text'))->not->toContain($this->fixture['spec']['captions'][66]['text']);
});

it('never repeats the caption preset of the previous short', function (): void {
    VideoCutEdit::factory()->create(['spec' => ['caption_preset' => 'verde']]);

    $presets = [];

    for ($run = 0; $run < 20; $run++) {
        $presets[] = validateCutEdit($this->fixture)['spec']['caption_preset'];
    }

    expect($presets)->not->toContain('verde');
});

it('skips a caption with no duration instead of sending start >= end to the render', function (): void {
    $words = [
        ['word' => 'oi', 'start' => 10.0, 'end' => 10.0],
        ['word' => 'tchau', 'start' => 10.0, 'end' => 10.3],
    ];

    $result = $this->validator->validate(['captions' => [
        ['w' => [0, 0], 'text' => 'oi', 'pos' => 'bottom', 'style' => 'speech'],
        ['w' => [1, 1], 'text' => 'tchau', 'pos' => 'bottom', 'style' => 'speech'],
    ]], $words, 12.0);

    expect(array_column($result['spec']['captions'], 'text'))->toBe(['tchau'])
        ->and($result['soft'])->toContain('legenda 0: sem duração (palavras no mesmo instante)');

    foreach ($result['spec']['captions'] as $caption) {
        expect($caption['t'][0])->toBeLessThan($caption['t'][1]);
    }
});
