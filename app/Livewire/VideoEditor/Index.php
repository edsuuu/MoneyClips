<?php

declare(strict_types=1);

namespace App\Livewire\Reframe;

use App\Livewire\Concerns\WithToasts;
use App\Models\ReframeEdit;
use App\Models\YoutubeShort;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Index extends Component
{
    use WithToasts;

    public const string DEFAULT_MODE = 'vertical';

    public const array REGION_COUNTS = [
        'vertical' => 1,
        'split' => 2,
        'trio' => 3,
        'spotlight' => 1,
        'centered' => 1,
    ];

    private const int MAX_KEYFRAMES = 120;

    private const float TIME_EPSILON = 0.05;

    private const float MIN_REGION_SIZE = 0.01;

    private const int PICKER_LIMIT = 24;

    #[Url(as: 'video', except: null)]
    public ?int $videoId = null;

    public ?int $editId = null;

    public function mount(): void
    {
        $short = $this->currentShort();
        if (! $short instanceof YoutubeShort) {
            $this->videoId = null;

            return;
        }

        $this->editId = $this->latestEditId($short->id);
    }

    public function selectSource(int $videoId): void
    {
        $short = YoutubeShort::query()->whereNotNull('video_path')->find($videoId);
        if (! $short instanceof YoutubeShort) {
            $this->toast('Vídeo indisponível — atualize a página.', 'danger');

            return;
        }

        $this->videoId = $short->id;
        $this->editId = $this->latestEditId($short->id);
    }

    public function clearSource(): void
    {
        $this->videoId = null;
        $this->editId = null;
    }

    /**
     * Persiste o estado do editor. Fronteira de confiança: o payload vem do
     * client (Alpine) e é validado/clampado aqui; source_path NUNCA vem do
     * payload. Retorna o id do edit salvo, ou null quando rejeitado.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveEdit(array $payload): ?int
    {
        $short = $this->currentShort();
        if (! $short instanceof YoutubeShort) {
            $this->toast('Escolha um vídeo para editar.', 'danger');

            return null;
        }

        $data = $this->sanitizePayload($payload);
        if ($data === null) {
            $this->toast('Edição inválida — recarregue a página e tente de novo.', 'danger');

            return null;
        }

        $editId = is_int($payload['editId'] ?? null) ? $payload['editId'] : $this->editId;
        $edit = $editId !== null
            ? ReframeEdit::query()->where('youtube_short_id', $short->id)->find($editId)
            : null;

        if (! $edit instanceof ReframeEdit) {
            $edit = new ReframeEdit(['youtube_short_id' => $short->id]);
        }

        $edit->fill([
            'source_path' => $short->postableVideoPath(),
            'source_meta' => $data['sourceMeta'],
            'mode' => $data['mode'],
            'keyframes' => $data['keyframes'],
            'settings' => $data['settings'],
        ])->save();

        $this->editId = $edit->id;
        $this->toast('Edição salva.');

        return $edit->id;
    }

    public function refreshUrl(): ?string
    {
        $short = $this->currentShort();

        return $short instanceof YoutubeShort ? $short->presignedUrl() : null;
    }

    private function currentShort(): ?YoutubeShort
    {
        return $this->videoId !== null
            ? YoutubeShort::query()->whereNotNull('video_path')->find($this->videoId)
            : null;
    }

    private function latestEditId(int $shortId): ?int
    {
        $id = ReframeEdit::query()->where('youtube_short_id', $shortId)->latest('id')->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * Estado inicial do editor pro Alpine (@js na view). Edit novo vai com
     * keyframes vazios de propósito: só o client conhece as dimensões
     * naturais do vídeo, então o keyframe default nasce no loadedmetadata.
     *
     * @return array<string, mixed>
     */
    private function editorPayload(YoutubeShort $short): array
    {
        $edit = $this->editId !== null
            ? ReframeEdit::query()->where('youtube_short_id', $short->id)->find($this->editId)
            : null;

        $settings = $edit->settings ?? [];

        return [
            'editId' => $edit?->id,
            'videoUrl' => $short->presignedUrl(),
            'mode' => $edit->mode ?? self::DEFAULT_MODE,
            'keyframes' => $edit->keyframes ?? [],
            'settings' => [
                'version' => 1,
                'background' => (string) ($settings['background'] ?? '#000000'),
            ],
            'sourceMeta' => $edit?->source_meta,
        ];
    }

    /**
     * Valida a estrutura (rejeita) e clampa os valores (silencioso — o
     * client aplica as mesmas regras; aqui é defesa).
     *
     * @param  array<string, mixed>  $payload
     * @return array{mode: string, keyframes: list<array{t: float, regions: list<array{x: float, y: float, w: float, h: float}>}>, settings: array{version: int, background: string}, sourceMeta: array{width: int, height: int, duration: float}}|null
     */
    private function sanitizePayload(array $payload): ?array
    {
        $mode = $payload['mode'] ?? null;
        if (! is_string($mode) || ! array_key_exists($mode, self::REGION_COUNTS)) {
            return null;
        }

        $meta = $payload['sourceMeta'] ?? null;
        if (! is_array($meta) || ! is_numeric($meta['width'] ?? null) || ! is_numeric($meta['height'] ?? null) || ! is_numeric($meta['duration'] ?? null)) {
            return null;
        }

        $width = (int) $meta['width'];
        $height = (int) $meta['height'];
        $duration = (float) $meta['duration'];
        if ($width < 16 || $width > 8192 || $height < 16 || $height > 8192 || $duration <= 0.0 || $duration > 7200.0) {
            return null;
        }

        $rawKeyframes = $payload['keyframes'] ?? null;
        if (! is_array($rawKeyframes) || $rawKeyframes === [] || count($rawKeyframes) > self::MAX_KEYFRAMES) {
            return null;
        }

        $regionCount = self::REGION_COUNTS[$mode];
        $keyframes = [];
        foreach ($rawKeyframes as $rawKeyframe) {
            if (! is_array($rawKeyframe) || ! is_numeric($rawKeyframe['t'] ?? null) || ! is_array($rawKeyframe['regions'] ?? null)) {
                return null;
            }

            $rawRegions = array_values($rawKeyframe['regions']);
            if (count($rawRegions) !== $regionCount) {
                return null;
            }

            $regions = [];
            foreach ($rawRegions as $rawRegion) {
                $region = $this->sanitizeRegion($rawRegion);
                if ($region === null) {
                    return null;
                }

                $regions[] = $region;
            }

            $keyframes[] = [
                't' => round(min(max((float) $rawKeyframe['t'], 0.0), $duration), 3),
                'regions' => $regions,
            ];
        }

        usort($keyframes, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);

        $deduped = [];
        foreach ($keyframes as $keyframe) {
            $last = $deduped === [] ? null : $deduped[count($deduped) - 1];
            if ($last !== null && $keyframe['t'] - $last['t'] < self::TIME_EPSILON) {
                continue;
            }

            $deduped[] = $keyframe;
        }

        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $background = $settings['background'] ?? null;
        if (! is_string($background) || preg_match('/^#[0-9a-fA-F]{6}$/', $background) !== 1) {
            $background = '#000000';
        }

        return [
            'mode' => $mode,
            'keyframes' => $deduped,
            'settings' => ['version' => 1, 'background' => mb_strtolower($background)],
            'sourceMeta' => ['width' => $width, 'height' => $height, 'duration' => round($duration, 3)],
        ];
    }

    /** @return array{x: float, y: float, w: float, h: float}|null */
    private function sanitizeRegion(mixed $rawRegion): ?array
    {
        if (! is_array($rawRegion)) {
            return null;
        }

        foreach (['x', 'y', 'w', 'h'] as $field) {
            if (! is_numeric($rawRegion[$field] ?? null)) {
                return null;
            }
        }

        $w = min(max((float) $rawRegion['w'], self::MIN_REGION_SIZE), 1.0);
        $h = min(max((float) $rawRegion['h'], self::MIN_REGION_SIZE), 1.0);
        $x = min(max((float) $rawRegion['x'], 0.0), 1.0 - $w);
        $y = min(max((float) $rawRegion['y'], 0.0), 1.0 - $h);

        return ['x' => round($x, 4), 'y' => round($y, 4), 'w' => round($w, 4), 'h' => round($h, 4)];
    }

    /** @return array<int, array{id: int, title: string}> */
    private function sources(): array
    {
        return YoutubeShort::query()
            ->whereNotNull('video_path')
            ->latest('id')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'title', 'youtube_id'])
            ->map(fn (YoutubeShort $s): array => [
                'id' => $s->id,
                'title' => $s->title ?? $s->youtube_id,
            ])
            ->values()->all();
    }

    public function render(): View
    {
        $short = $this->currentShort();

        return view('livewire.reframe.index', [
            'video' => $short instanceof YoutubeShort
                ? ['id' => $short->id, 'title' => $short->title ?? $short->youtube_id]
                : null,
            'editorPayload' => $short instanceof YoutubeShort ? $this->editorPayload($short) : null,
            'sources' => $short instanceof YoutubeShort ? [] : $this->sources(),
        ]);
    }
}
