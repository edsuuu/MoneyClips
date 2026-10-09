<?php

declare(strict_types=1);

namespace App\Services\CutEdit;

use App\Helpers\Hashtags;
use App\Models\StockAsset;
use App\Models\VideoCutEdit;
use Illuminate\Support\Arr;

/**
 * Porte do qa.py do protótipo + regras do estilo oguxta. Recebe o spec que a
 * IA escreveu (legenda por ÍNDICE de palavra, tempos em segundos do clip) e
 * devolve o spec convertido, com o tempo de cada legenda calculado aqui pela
 * regra do draft(). Erro duro = o corte não fecha (reject, sem retry); erro
 * mole = 1 retry com a lista anexada; aviso = item do estoque (figurinha,
 * vídeo-meme, emoji, som) descartado sem retry. Limiares = faixa medida nos
 * shorts do gusta.
 *
 * @phpstan-type StockAssetItem array{asset_id: string, key: string, t: array{0: float, 1: float}, has_audio: bool}
 */
final readonly class CutEditValidatorService
{
    public const array CAPTION_PRESETS = ['verde', 'branco_italico', 'branco_limpo'];

    public const array SPEECH_STYLES = ['speech', 'shout', 'punch', 'aside'];

    private const array STYLES = ['speech', 'shout', 'punch', 'aside', 'note', 'art'];

    private const array EMPHASIS_STYLES = ['shout', 'punch', 'aside', 'art'];

    private const array POSITIONS = ['bottom', 'top'];

    private const array PUNCH_KINDS = ['punch', 'laugh', 'slow'];

    private const float MIN_DURATION = 60.0;

    private const float SEAM_GAP = 2.5;

    private const float MAX_INNER_CUT = 45.0;

    private const float MAX_REMOVED = 0.35;

    private const float MAX_AVG_WORDS = 3.2;

    private const int MAX_WORDS = 7;

    private const float MAX_CAPS_SHARE = 0.25;

    private const float MAX_NOTES_PER_MINUTE = 5.5;

    private const float MAX_ART_PER_MINUTE = 3.0;

    private const float MIN_STYLED_SHARE = 0.12;

    private const float MAX_STYLED_SHARE = 0.20;

    private const float MIN_COVERAGE = 0.95;

    private const float LEAD_IN = 0.08;

    private const float TAIL = 0.25;

    private const array ASSET_RATES = ['memes' => 3.0, 'meme_clips' => 2.0, 'emoji' => 1.0, 'sfx' => 4.0];

    private const float HOOK = 25.0;

    private const int MAX_HOOK_MEMES = 5;

    private const float MIN_MEME_GAP = 4.0;

    /**
     * @param  array<mixed>  $spec
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  array<string, array<string, StockAsset>>  $assets
     * @return array{hard: list<string>, soft: list<string>, warnings: list<string>, spec: array{version: int, caption_preset: string, cuts: list<array{0: float, 1: float}>, captions: list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>, punches: list<array{t: array{0: float, 1: float}, kind: string}>, title: string, hashtags: list<string>, memes: list<StockAssetItem>, meme_clips: list<StockAssetItem>, emoji: list<StockAssetItem>, sfx: list<StockAssetItem>}}
     */
    public function validate(array $spec, array $words, float $duration, array $assets = []): array
    {
        $hard = [];
        $soft = [];

        if (($spec['verdict'] ?? 'edit') === 'reject') {
            $hard[] = sprintf('IA recusou o corte: %s', is_string($spec['reason'] ?? null) ? $spec['reason'] : 'sem motivo');
        }

        $cuts = $this->cuts($spec['cuts'] ?? [], $duration);
        $keep = $this->keep($cuts, $duration);
        $kept = 0.0;

        foreach ($keep as [$start, $end]) {
            $kept += $end - $start;
        }

        $gaps = [];
        $keepCount = count($keep);

        for ($index = 1; $index < $keepCount; $index++) {
            $gaps[] = $keep[$index][0] - $keep[$index - 1][1];
        }

        $span = $keep === [] ? 0.0 : $keep[count($keep) - 1][1] - $keep[0][0];
        $seams = array_values(array_filter($gaps, static fn (float $gap): bool => $gap > self::SEAM_GAP));
        $longestGap = max([0.0, ...$gaps]);

        if ($kept < self::MIN_DURATION) {
            $hard[] = sprintf('duração %.0fs < 60', $kept);
        }

        if (count($seams) > 1) {
            $hard[] = sprintf('costura: gaps %s', implode(', ', array_map(static fn (float $gap): string => sprintf('%.1fs', $gap), $seams)));
        }

        if ($longestGap > self::MAX_INNER_CUT) {
            $hard[] = sprintf('corte interno de %.0fs > 45s', $longestGap);
        }

        if ($span > 0 && ($span - $kept) / $span > self::MAX_REMOVED) {
            $hard[] = sprintf('removido %.0f%% do trecho', 100 * ($span - $kept) / $span);
        }

        $blocks = $this->wordBlocks($spec['captions'] ?? [], $words, $cuts, $soft);
        $captions = [...$this->timedBlocks($blocks, $words, $duration, $soft), ...$this->notes($spec['notes'] ?? [], $duration, $soft)];

        usort($captions, static fn (array $a, array $b): int => $a['t'][0] <=> $b['t'][0]);

        $this->checkCaptions($captions, $blocks, $words, $cuts, $kept, $soft);

        $punches = $this->punches($spec['punches'] ?? [], $duration, $soft);

        $warnings = [];
        $stock = $this->stockAssets($spec, $assets, $captions, $words, $cuts, $duration, $kept, $warnings);

        $title = is_string($spec['title'] ?? null) ? mb_trim($spec['title']) : '';
        $rawTags = array_values(array_filter(Arr::wrap($spec['hashtags'] ?? []), is_string(...)));
        $hashtags = Hashtags::parse(implode(' ', $rawTags));

        if ($title === '') {
            $soft[] = 'título vazio';
        }

        if (count($hashtags) < 4 || count($hashtags) > 6 || count(array_unique(array_map(mb_strtolower(...), $hashtags))) !== count($rawTags)) {
            $soft[] = sprintf('hashtags: precisa de 4 a 6, sem repetir (veio %d)', count($rawTags));
        }

        return [
            'hard' => $hard,
            'soft' => $soft,
            'warnings' => $warnings,
            'spec' => [
                'version' => 1,
                'caption_preset' => $this->captionPreset(),
                'cuts' => $cuts,
                'captions' => $captions,
                'punches' => $punches,
                'title' => $title,
                'hashtags' => $hashtags,
                ...$stock,
            ],
        ];
    }

    /**
     * Na ordem do tempo, o que quebra regra cai com aviso e não pede retry:
     * Short sem meme é melhor que edição perdida.
     *
     * @param  array<mixed>  $spec
     * @param  array<string, array<string, StockAsset>>  $assets
     * @param  list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>  $captions
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<array{0: float, 1: float}>  $cuts
     * @param  list<string>  $warnings
     * @return array{memes: list<StockAssetItem>, meme_clips: list<StockAssetItem>, emoji: list<StockAssetItem>, sfx: list<StockAssetItem>}
     */
    private function stockAssets(array $spec, array $assets, array $captions, array $words, array $cuts, float $duration, float $kept, array &$warnings): array
    {
        $minutes = max($kept, 1.0) / 60;
        $placed = ['memes' => [], 'meme_clips' => [], 'emoji' => [], 'sfx' => []];

        foreach (self::ASSET_RATES as $field => $rate) {
            $candidates = [];

            foreach (Arr::wrap($spec[$field] ?? []) as $index => $item) {
                $asset = is_array($item) && is_string($item['asset_id'] ?? null) ? ($assets[$field][$item['asset_id']] ?? null) : null;

                if (! is_array($item) || is_null($asset)) {
                    $warnings[] = sprintf('%s %d: asset fora do estoque liberado pra este clip', $field, $index);

                    continue;
                }

                $length = ($asset->duration_ms ?? 0) / 1000;
                $span = match ($field) {
                    'memes', 'emoji' => $this->blockSpan($item['w'] ?? null, $captions, $words, $cuts),
                    'meme_clips' => $this->silentSpan($item['t'] ?? null, $length, $words, $cuts, $duration),
                    'sfx' => $this->soundSpan($item['t'] ?? null, $length, $cuts, $duration),
                };

                if (is_string($span)) {
                    $warnings[] = sprintf('%s %d: %s', $field, $index, $span);

                    continue;
                }

                $candidates[] = ['asset_id' => $asset->id, 'key' => $asset->storage_key, 't' => $span, 'has_audio' => $asset->has_audio];
            }

            usort($candidates, static fn (array $a, array $b): int => $a['t'][0] <=> $b['t'][0]);

            foreach ($candidates as $candidate) {
                $limit = $this->assetLimit($field, $candidate, $placed, $rate, $minutes, $cuts);

                if (! is_null($limit)) {
                    $warnings[] = sprintf('%s em %.1fs: %s', $field, $candidate['t'][0], $limit);

                    continue;
                }

                $placed[$field][] = $candidate;
            }
        }

        return $placed;
    }

    /**
     * @param  StockAssetItem  $candidate
     * @param  array{memes: list<StockAssetItem>, meme_clips: list<StockAssetItem>, emoji: list<StockAssetItem>, sfx: list<StockAssetItem>}  $placed
     * @param  list<array{0: float, 1: float}>  $cuts
     */
    private function assetLimit(string $field, array $candidate, array $placed, float $rate, float $minutes, array $cuts): ?string
    {
        $same = $placed[$field] ?? [];

        if (in_array($candidate['asset_id'], array_column($same, 'asset_id'), true)) {
            return 'asset repetido no clip';
        }

        if (count($same) + 1 > $rate * $minutes) {
            return sprintf('passa de %g por minuto', $rate);
        }

        if ($field === 'emoji' && array_any($placed['memes'], static fn (array $meme): bool => $meme['t'][0] < $candidate['t'][1] && $meme['t'][1] > $candidate['t'][0])) {
            return 'em cima de uma figurinha';
        }

        if ($field !== 'memes' || $same === []) {
            return null;
        }

        $start = $this->outputTime($candidate['t'][0], $cuts);
        $hook = array_filter($same, fn (array $meme): bool => $this->outputTime($meme['t'][0], $cuts) < self::HOOK);

        if ($start < self::HOOK && count($hook) >= self::MAX_HOOK_MEMES) {
            return sprintf('passa de %d figurinhas nos primeiros %gs', self::MAX_HOOK_MEMES, self::HOOK);
        }

        if ($start - $this->outputTime($same[count($same) - 1]['t'][0], $cuts) < self::MIN_MEME_GAP) {
            return sprintf('a menos de %gs da figurinha anterior', self::MIN_MEME_GAP);
        }

        return null;
    }

    /**
     * Figurinha e emoji ficam acima da legenda durante o bloco da palavra `w`.
     *
     * @param  list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>  $captions
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<array{0: float, 1: float}>  $cuts
     * @return array{0: float, 1: float}|string
     */
    private function blockSpan(mixed $index, array $captions, array $words, array $cuts): array|string
    {
        if (! is_int($index) || ! isset($words[$index])) {
            return 'índice w inválido';
        }

        $middle = ($words[$index]['start'] + $words[$index]['end']) / 2;

        if ($this->insideCut($middle, $cuts)) {
            return 'palavra cortada';
        }

        foreach ($captions as $caption) {
            if (in_array($caption['style'], self::SPEECH_STYLES, true) && $caption['t'][0] <= $middle && $middle <= $caption['t'][1]) {
                return $caption['t'];
            }
        }

        return 'palavra sem legenda';
    }

    /**
     * Vídeo-meme cobre a tela com o próprio áudio pela duração do asset: a
     * janela inteira tem que caber no clip, fora dos cuts e sem palavra.
     *
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<array{0: float, 1: float}>  $cuts
     * @return array{0: float, 1: float}|string
     */
    private function silentSpan(mixed $start, float $length, array $words, array $cuts, float $duration): array|string
    {
        if (! is_numeric($start)) {
            return 't inválido';
        }

        $span = [round((float) $start, 2), round((float) $start + $length, 2)];

        if ($span[0] < 0 || $span[1] > $duration || array_any($cuts, static fn (array $cut): bool => $cut[0] < $span[1] && $cut[1] > $span[0])) {
            return 'fora do clip ou cruzando um corte';
        }

        if (array_any($words, static fn (array $word): bool => $word['start'] < $span[1] && $word['end'] > $span[0])) {
            return 'em cima de fala';
        }

        return $span;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $cuts
     * @return array{0: float, 1: float}|string
     */
    private function soundSpan(mixed $start, float $length, array $cuts, float $duration): array|string
    {
        if (! is_numeric($start)) {
            return 't inválido';
        }

        $time = round((float) $start, 2);

        if ($time < 0 || $time >= $duration || $this->insideCut($time, $cuts)) {
            return 'fora do clip ou dentro de um corte';
        }

        return [$time, round($time + $length, 2)];
    }

    /**
     * Tempo no vídeo final = o do clip menos o que os cuts tiram antes dele.
     * O ar morto, que o render corta depois, fica fora da conta.
     *
     * @param  list<array{0: float, 1: float}>  $cuts
     */
    private function outputTime(float $time, array $cuts): float
    {
        $removed = 0.0;

        foreach ($cuts as [$start, $end]) {
            if ($start < $time) {
                $removed += min($end, $time) - $start;
            }
        }

        return $time - $removed;
    }

    /**
     * @param  list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>  $captions
     * @param  list<array{w: array{0: int, 1: int}, text: string, style: string, pos: string}>  $blocks
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<array{0: float, 1: float}>  $cuts
     * @param  list<string>  $soft
     */
    private function checkCaptions(array $captions, array $blocks, array $words, array $cuts, float $kept, array &$soft): void
    {
        $speech = array_values(array_filter($captions, static fn (array $caption): bool => in_array($caption['style'], self::SPEECH_STYLES, true)));
        $notes = array_values(array_filter($captions, static fn (array $caption): bool => $caption['style'] === 'note'));
        $minutes = max($kept, 1.0) / 60;

        if ($speech === []) {
            $soft[] = 'sem legenda de fala';

            return;
        }

        $wordCounts = array_map(static fn (array $caption): int => count(preg_split('/\s+/u', mb_trim($caption['text'])) ?: []), $speech);

        if (max($wordCounts) > self::MAX_WORDS || array_sum($wordCounts) / count($wordCounts) > self::MAX_AVG_WORDS) {
            $soft[] = sprintf('palavras/bloco média %.1f máx %d (limite 3.2 e 7)', array_sum($wordCounts) / count($wordCounts), max($wordCounts));
        }

        $shouting = array_filter($speech, $this->hasCapsWord(...));

        if (count($shouting) / count($speech) > self::MAX_CAPS_SHARE) {
            $soft[] = sprintf('caixa alta em %.0f%% dos blocos (máx 25%%)', 100 * count($shouting) / count($speech));
        }

        if (count($notes) / $minutes > self::MAX_NOTES_PER_MINUTE) {
            $soft[] = sprintf('%.1f notas/min (máx 5.5)', count($notes) / $minutes);
        }

        $art = array_filter($captions, static fn (array $caption): bool => $caption['style'] === 'art');

        if (count($art) / $minutes > self::MAX_ART_PER_MINUTE) {
            $soft[] = sprintf('%.1f textos-arte/min (máx 3)', count($art) / $minutes);
        }

        foreach ($notes as $note) {
            if ($note['pos'] !== 'bottom') {
                continue;
            }

            foreach ($speech as $caption) {
                if (min($note['t'][1], $caption['t'][1]) - max($note['t'][0], $caption['t'][0]) > 0.1) {
                    $soft[] = sprintf('nota por cima de fala: "%s"', $note['text']);

                    break;
                }
            }
        }

        $styled = array_filter($captions, static fn (array $caption): bool => $caption['style'] !== 'speech');
        $styledShare = count($styled) / count($captions);

        if ($styledShare < self::MIN_STYLED_SHARE || $styledShare > self::MAX_STYLED_SHARE) {
            $soft[] = sprintf('%.0f%% dos blocos fora de speech (alvo 12–20%%)', 100 * $styledShare);
        }

        $captionCount = count($captions);

        for ($index = 2; $index < $captionCount; $index++) {
            $style = $captions[$index]['style'];

            if (in_array($style, self::EMPHASIS_STYLES, true) && $captions[$index - 1]['style'] === $style && $captions[$index - 2]['style'] === $style) {
                $soft[] = sprintf('3 blocos seguidos com style %s (a partir de %.1fs)', $style, $captions[$index - 2]['t'][0]);

                break;
            }
        }

        $this->checkWordOrder($blocks, $words, $cuts, $soft);
    }

    /**
     * Fala tem que seguir a ordem das palavras sem repetir nenhuma e cobrir
     * quase todas as que sobram depois dos cortes. Texto-arte e nota são a voz
     * do editor: podem cair em cima da fala e não contam pra cobertura.
     *
     * @param  list<array{w: array{0: int, 1: int}, text: string, style: string, pos: string}>  $blocks
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<array{0: float, 1: float}>  $cuts
     * @param  list<string>  $soft
     */
    private function checkWordOrder(array $blocks, array $words, array $cuts, array &$soft): void
    {
        $covered = [];
        $lastIndex = -1;

        foreach ($blocks as $block) {
            if (! in_array($block['style'], self::SPEECH_STYLES, true)) {
                continue;
            }

            if ($block['w'][0] <= $lastIndex) {
                $soft[] = sprintf('legenda "%s" repete palavras do bloco anterior (w %d)', $block['text'], $block['w'][0]);
            }

            $lastIndex = max($lastIndex, $block['w'][1]);

            for ($index = $block['w'][0]; $index <= $block['w'][1]; $index++) {
                $covered[$index] = true;
            }
        }

        $keptWords = 0;
        $coveredWords = 0;

        foreach ($words as $index => $word) {
            if ($this->insideCut(($word['start'] + $word['end']) / 2, $cuts)) {
                continue;
            }

            $keptWords++;
            $coveredWords += isset($covered[$index]) ? 1 : 0;
        }

        if ($keptWords > 0 && $coveredWords / $keptWords < self::MIN_COVERAGE) {
            $soft[] = sprintf('legenda cobre %.0f%% das palavras mantidas (mín 95%%)', 100 * $coveredWords / $keptWords);
        }
    }

    /**
     * Blocos por índice de palavra, ordenados. Bloco com todas as palavras
     * dentro de um corte cai em silêncio.
     *
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<array{0: float, 1: float}>  $cuts
     * @param  list<string>  $soft
     * @return list<array{w: array{0: int, 1: int}, text: string, style: string, pos: string}>
     */
    private function wordBlocks(mixed $raw, array $words, array $cuts, array &$soft): array
    {
        $blocks = [];

        foreach (Arr::wrap($raw) as $index => $item) {
            $range = is_array($item) ? array_values(Arr::wrap($item['w'] ?? null)) : [];
            $first = $range[0] ?? null;
            $last = $range[1] ?? null;
            $text = is_array($item) && is_string($item['text'] ?? null) ? mb_trim($item['text']) : '';
            $style = is_array($item) ? ($item['style'] ?? 'speech') : null;
            $pos = is_array($item) ? ($item['pos'] ?? 'bottom') : null;

            if (! is_int($first) || ! is_int($last) || $first < 0 || $first > $last || $last >= count($words)) {
                $soft[] = sprintf('legenda %d: índices w inválidos', $index);

                continue;
            }

            if ($text === '' || ! in_array($style, self::STYLES, true) || ! in_array($pos, self::POSITIONS, true)) {
                $soft[] = sprintf('legenda %d: texto vazio ou style/pos inválido', $index);

                continue;
            }

            $allCut = true;

            for ($word = $first; $word <= $last; $word++) {
                $allCut = $allCut && $this->insideCut(($words[$word]['start'] + $words[$word]['end']) / 2, $cuts);
            }

            if ($allCut) {
                continue;
            }

            $blocks[] = ['w' => [$first, $last], 'text' => $text, 'style' => $style, 'pos' => $pos];
        }

        usort($blocks, static fn (array $a, array $b): int => $a['w'][0] <=> $b['w'][0]);

        return $blocks;
    }

    /**
     * Regra do draft() do render.py: entra 0.08s antes da 1ª palavra e sai
     * 0.25s depois da última, sem invadir o próximo bloco (que começa depois
     * da última palavra deste).
     *
     * @param  list<array{w: array{0: int, 1: int}, text: string, style: string, pos: string}>  $blocks
     * @param  list<array{word: string, start: float, end: float}>  $words
     * @param  list<string>  $soft
     * @return list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>
     */
    private function timedBlocks(array $blocks, array $words, float $duration, array &$soft): array
    {
        $captions = [];
        $blockCount = count($blocks);

        foreach ($blocks as $index => $block) {
            $end = $words[$block['w'][1]]['end'] + self::TAIL;

            for ($next = $index + 1; $next < $blockCount; $next++) {
                if ($blocks[$next]['w'][0] > $block['w'][1]) {
                    $end = min($end, $words[$blocks[$next]['w'][0]]['start'] - self::LEAD_IN);

                    break;
                }
            }

            $start = round(max(0.0, $words[$block['w'][0]]['start'] - self::LEAD_IN), 2);
            $end = round(min($duration, $end), 2);

            if ($end <= $start) {
                $soft[] = sprintf('legenda %d: sem duração (palavras no mesmo instante)', $index);

                continue;
            }

            $captions[] = [
                't' => [$start, $end],
                'text' => $block['text'],
                'style' => $block['style'],
                'pos' => $block['pos'],
            ];
        }

        return $captions;
    }

    /**
     * @param  list<string>  $soft
     * @return list<array{t: array{0: float, 1: float}, text: string, style: string, pos: string}>
     */
    private function notes(mixed $raw, float $duration, array &$soft): array
    {
        $notes = [];

        foreach (Arr::wrap($raw) as $index => $item) {
            $span = is_array($item) ? $this->span($item['t'] ?? null, $duration) : null;
            $text = is_array($item) && is_string($item['text'] ?? null) ? mb_trim($item['text']) : '';
            $pos = is_array($item) ? ($item['pos'] ?? 'bottom') : null;

            if (is_null($span) || $text === '' || ! in_array($pos, self::POSITIONS, true)) {
                $soft[] = sprintf('nota %d: tempo, texto ou pos inválido', $index);

                continue;
            }

            $notes[] = ['t' => $span, 'text' => $text, 'style' => 'note', 'pos' => $pos];
        }

        return $notes;
    }

    /**
     * @param  list<string>  $soft
     * @return list<array{t: array{0: float, 1: float}, kind: string}>
     */
    private function punches(mixed $raw, float $duration, array &$soft): array
    {
        $punches = [];

        foreach (Arr::wrap($raw) as $index => $item) {
            $span = is_array($item) ? $this->span($item['t'] ?? null, $duration) : null;
            $kind = is_array($item) ? ($item['kind'] ?? null) : null;

            if (is_null($span) || ! in_array($kind, self::PUNCH_KINDS, true)) {
                $soft[] = sprintf('punch %d: tempo ou kind inválido', $index);

                continue;
            }

            $punches[] = ['t' => $span, 'kind' => $kind];
        }

        usort($punches, static fn (array $a, array $b): int => $a['t'][0] <=> $b['t'][0]);

        return $punches;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function cuts(mixed $raw, float $duration): array
    {
        $spans = [];

        foreach (Arr::wrap($raw) as $item) {
            $span = $this->span($item, $duration);

            if (! is_null($span)) {
                $spans[] = $span;
            }
        }

        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($spans as $span) {
            $last = count($merged) - 1;

            if ($last >= 0 && $span[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $span[1]);

                continue;
            }

            $merged[] = $span;
        }

        return $merged;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $cuts
     * @return list<array{0: float, 1: float}>
     */
    private function keep(array $cuts, float $duration): array
    {
        $keep = [];
        $cursor = 0.0;

        foreach ($cuts as [$start, $end]) {
            if ($start > $cursor) {
                $keep[] = [$cursor, $start];
            }

            $cursor = max($cursor, $end);
        }

        if ($cursor < $duration) {
            $keep[] = [$cursor, $duration];
        }

        return $keep;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function span(mixed $raw, float $duration): ?array
    {
        $values = array_values(Arr::wrap($raw));

        if (count($values) !== 2 || ! is_numeric($values[0]) || ! is_numeric($values[1])) {
            return null;
        }

        $start = round(max(0.0, (float) $values[0]), 2);
        $end = round(min($duration, (float) $values[1]), 2);

        return $end > $start ? [$start, $end] : null;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $cuts
     */
    private function insideCut(float $time, array $cuts): bool
    {
        foreach ($cuts as [$start, $end]) {
            if ($time >= $start && $time <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{t: array{0: float, 1: float}, text: string, style: string, pos: string}  $caption
     */
    private function hasCapsWord(array $caption): bool
    {
        return array_any(preg_split('/\s+/u', $caption['text']) ?: [], static fn (string $word): bool => mb_strlen($word) > 1 && mb_strtoupper($word) === $word && mb_strtolower($word) !== $word);
    }

    /**
     * Preset-base sorteado por Short, nunca igual ao do Short anterior: é o
     * "nunca no mesmo padrão" entre vídeos medido no gusta.
     */
    private function captionPreset(): string
    {
        $previous = VideoCutEdit::query()->whereNotNull('spec')->latest('id')->first()?->spec['caption_preset'] ?? null;

        return (string) Arr::random(array_values(array_diff(self::CAPTION_PRESETS, [$previous])));
    }
}
