<?php

declare(strict_types=1);

namespace App\Livewire\VideoEditor;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Livewire\Concerns\EditsTranscript;
use App\Livewire\Concerns\WithToasts;
use App\Models\ReframeEdit;
use App\Models\VideoCut;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
    use EditsTranscript;
    use WithToasts;

    public const string DEFAULT_MODE = 'vertical';

    public const array REGION_COUNTS = [
        'vertical' => 1,
        'split' => 2,
        'trio' => 3,
        'centered' => 1,
    ];

    private const int MAX_KEYFRAMES = 120;

    private const float TIME_EPSILON = 0.05;

    private const float MIN_REGION_SIZE = 0.01;

    public VideoCut $cut;

    public ?int $editId = null;

    /**
     * O dono é filtrado aqui (via vídeo pai) porque a rota é `Route::view`,
     * que não dispara model binding. Só corte pronto tem arquivo pra editar.
     */
    public function mount(string $uuid): void
    {
        $cut = VideoCut::query()->with('video')->where('uuid', $uuid)->firstOrFail();

        abort_unless($cut->video->user_id === auth()->id(), 404);
        abort_unless($cut->status === VideoCutStatusEnum::Ready, 404);

        $this->cut = $cut;
        $this->editId = $this->latestEditId();
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
        $data = $this->sanitizePayload($payload);
        if ($data === null) {
            $this->toast('Edição inválida — recarregue a página e tente de novo.', 'danger');

            return null;
        }

        $editId = is_int($payload['editId'] ?? null) ? $payload['editId'] : $this->editId;
        $edit = $editId !== null
            ? ReframeEdit::query()->where('video_cut_id', $this->cut->id)->find($editId)
            : null;

        if (! $edit instanceof ReframeEdit) {
            $edit = new ReframeEdit(['video_cut_id' => $this->cut->id]);
        }

        $edit->fill([
            'source_path' => $this->cut->clipPath(),
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
        return $this->cut->presignedUrl();
    }

    /**
     * Só o texto é editável — start/end vêm do transcriber (autoritativo) e
     * nunca do cliente. Aplica text por índice, igual ao editor do vídeo longo.
     *
     * @param  list<array{i?: mixed, text?: mixed}>  $edits
     */
    /** @param  list<array{i?: mixed, text?: mixed}>  $edits */
    public function saveTranscript(array $edits): bool
    {
        return $this->saveTranscriptText($this->cut->transcriptPath(), $edits);
    }

    private function latestEditId(): ?int
    {
        $id = ReframeEdit::query()->where('video_cut_id', $this->cut->id)->latest('id')->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * Estado inicial do editor pro Alpine (@js na view). Edit novo vai com
     * keyframes vazios de propósito: só o client conhece as dimensões
     * naturais do vídeo, então o keyframe default nasce no loadedmetadata.
     *
     * @return array<string, mixed>
     */
    private function editorPayload(): array
    {
        $edit = $this->editId !== null
            ? ReframeEdit::query()->where('video_cut_id', $this->cut->id)->find($this->editId)
            : null;

        $settings = $edit->settings ?? [];

        return [
            'editId' => $edit?->id,
            'videoUrl' => $this->cut->presignedUrl(),
            'mode' => $edit->mode ?? self::DEFAULT_MODE,
            'keyframes' => $edit->keyframes ?? [],
            'settings' => [
                'version' => 1,
                'background' => (string) ($settings['background'] ?? '#000000'),
                'captions' => (bool) ($settings['captions'] ?? false),
                'captionColor' => (string) ($settings['captionColor'] ?? '#ffffff'),
                'captionCase' => (string) ($settings['captionCase'] ?? 'sentence'),
            ],
            'sourceMeta' => $edit?->source_meta,
        ];
    }

    /**
     * Valida a estrutura (rejeita) e clampa os valores (silencioso — o
     * client aplica as mesmas regras; aqui é defesa).
     *
     * @param  array<string, mixed>  $payload
     * @return array{mode: string, keyframes: list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>, settings: array{version: int, background: string, captions: bool, captionColor: string, captionCase: string}, sourceMeta: array{width: int, height: int, duration: float}}|null
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

        $keyframes = [];
        foreach ($rawKeyframes as $rawKeyframe) {
            if (! is_array($rawKeyframe) || ! is_numeric($rawKeyframe['t'] ?? null) || ! is_array($rawKeyframe['regions'] ?? null)) {
                return null;
            }

            $keyframeMode = $rawKeyframe['mode'] ?? null;
            if (! is_string($keyframeMode) || ! array_key_exists($keyframeMode, self::REGION_COUNTS)) {
                return null;
            }

            $rawRegions = array_values($rawKeyframe['regions']);
            if (count($rawRegions) !== self::REGION_COUNTS[$keyframeMode]) {
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
                'mode' => $keyframeMode,
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

        $captionColor = $settings['captionColor'] ?? null;
        if (! is_string($captionColor) || preg_match('/^#[0-9a-fA-F]{6}$/', $captionColor) !== 1) {
            $captionColor = '#ffffff';
        }

        $captionCase = $settings['captionCase'] ?? null;
        if (! is_string($captionCase) || ! in_array($captionCase, ['sentence', 'upper', 'lower'], true)) {
            $captionCase = 'sentence';
        }

        return [
            'mode' => $mode,
            'keyframes' => $deduped,
            'settings' => [
                'version' => 1,
                'background' => mb_strtolower($background),
                'captions' => (bool) ($settings['captions'] ?? false),
                'captionColor' => mb_strtolower($captionColor),
                'captionCase' => $captionCase,
            ],
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

    public function render(): View
    {
        return view('livewire.video-editor.index', [
            'editorPayload' => $this->editorPayload(),
            'backUrl' => route('uploads.show', $this->cut->video->uuid),
            'transcriptSegments' => $this->transcriptSegmentsFrom(
                $this->cut->transcriptPath(),
                $this->cut->transcription_status === TranscriptionStatusEnum::Ready,
            ),
        ]);
    }
}
