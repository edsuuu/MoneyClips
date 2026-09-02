<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ao contrário do editor (que clampa float torto vindo do browser), aqui a
 * faixa é validada e a request é REJEITADA: keyframe fora de 0–1 vindo do
 * serviço é bug do serviço, e silenciar isso renderiza crop errado sem aviso.
 * O tracking só produz recorte de altura cheia, daí `mode` aceitar só
 * `vertical` — outro modo aqui é contrato quebrado, não caso de uso novo.
 */
final class FaceTrackingWebhookRequest extends FormRequest
{
    private const int MAX_KEYFRAMES = 120;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed'],
            'error' => ['nullable', 'string'],

            'keyframes' => ['required_if:status,done', 'array', 'min:1', 'max:'.self::MAX_KEYFRAMES],
            'keyframes.*.t' => ['required', 'numeric', 'min:0'],
            'keyframes.*.mode' => ['required', 'string', 'in:vertical'],
            'keyframes.*.regions' => ['required', 'array', 'min:1', 'max:3'],
            'keyframes.*.regions.*.x' => ['required', 'numeric', 'between:0,1'],
            'keyframes.*.regions.*.y' => ['required', 'numeric', 'between:0,1'],
            'keyframes.*.regions.*.w' => ['required', 'numeric', 'between:0,1'],
            'keyframes.*.regions.*.h' => ['required', 'numeric', 'between:0,1'],

            'speakers' => ['nullable', 'array'],
            'speakers.*.start' => ['required', 'numeric', 'min:0'],
            'speakers.*.end' => ['required', 'numeric', 'min:0'],
            'speakers.*.speaker' => ['required', 'integer', 'min:1'],

            'source' => ['nullable', 'array'],
            'source.width' => ['required_with:source', 'integer', 'min:16', 'max:8192'],
            'source.height' => ['required_with:source', 'integer', 'min:16', 'max:8192'],
            'source.duration' => ['required_with:source', 'numeric', 'min:0'],
        ];
    }

    public function uuid(): string
    {
        return (string) $this->validated('uuid');
    }

    public function failed(): bool
    {
        return $this->validated('status') === 'failed';
    }

    /** @return list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}> */
    public function keyframes(): array
    {
        $keyframes = $this->validated('keyframes');

        if (! is_array($keyframes)) {
            return [];
        }

        $normalized = [];

        foreach ($keyframes as $keyframe) {
            if (! is_array($keyframe)) {
                continue;
            }

            if (! is_array($keyframe['regions'] ?? null)) {
                continue;
            }

            $regions = [];

            foreach (array_values($keyframe['regions']) as $region) {
                if (! is_array($region)) {
                    continue;
                }

                $regions[] = [
                    'x' => round((float) $region['x'], 4),
                    'y' => round((float) $region['y'], 4),
                    'w' => round((float) $region['w'], 4),
                    'h' => round((float) $region['h'], 4),
                ];
            }

            $normalized[] = [
                't' => round((float) $keyframe['t'], 3),
                'mode' => (string) $keyframe['mode'],
                'regions' => $regions,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        return $normalized;
    }

    /** @return list<array{start: float, end: float, speaker: int}> */
    public function speakers(): array
    {
        $speakers = $this->validated('speakers');

        if (! is_array($speakers)) {
            return [];
        }

        $normalized = [];

        foreach ($speakers as $speaker) {
            if (! is_array($speaker)) {
                continue;
            }

            $normalized[] = [
                'start' => round((float) $speaker['start'], 3),
                'end' => round((float) $speaker['end'], 3),
                'speaker' => (int) $speaker['speaker'],
            ];
        }

        return $normalized;
    }

    /** @return array{width: int, height: int, duration: float}|null */
    public function source(): ?array
    {
        $source = $this->validated('source');

        if (! is_array($source)) {
            return null;
        }

        return [
            'width' => (int) ($source['width'] ?? 0),
            'height' => (int) ($source['height'] ?? 0),
            'duration' => round((float) ($source['duration'] ?? 0), 3),
        ];
    }

    public function error(): ?string
    {
        $error = $this->validated('error');

        return is_string($error) ? $error : null;
    }
}
