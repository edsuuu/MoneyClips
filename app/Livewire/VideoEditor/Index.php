<?php

declare(strict_types=1);

namespace App\Livewire\VideoEditor;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Jobs\StartFaceTrackingJob;
use App\Jobs\StartVideoCutEditRenderJob;
use App\Livewire\Concerns\WithToasts;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\View\View;
use Livewire\Component;

final class Index extends Component
{
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
     * client (Alpine) e é validado/clampado aqui. A fonte é sempre o clipe do
     * corte pai — nunca vem do payload. Retorna o id do edit salvo, ou null.
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
            ? VideoCutEdit::query()->where('video_cut_id', $this->cut->id)->find($editId)
            : null;

        if (! $edit instanceof VideoCutEdit) {
            $edit = new VideoCutEdit(['video_cut_id' => $this->cut->id]);
        }

        $edit->fill([
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
     * Claim atômico do render: só uma geração por vez por edição. O desfecho
     * chega pelo webhook /api/webhook/reframe. Retorna o status novo pro
     * Alpine, ou null quando nada foi disparado.
     */
    public function generateRender(): ?string
    {
        $edit = $this->editId !== null
            ? VideoCutEdit::query()->where('video_cut_id', $this->cut->id)->find($this->editId)
            : null;

        if (! $edit instanceof VideoCutEdit) {
            $this->toast('Salve a edição antes de gerar o corte.', 'danger');

            return null;
        }

        // ponytail: generating parado há 30 min é webhook perdido (serviço caiu
        // depois do 202) — o re-claim manual destrava; watchdog em cron se doer.
        $claimed = VideoCutEdit::query()
            ->whereKey($edit->id)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('render_status')
                ->orWhereIn('render_status', [VideoCutStatusEnum::Ready->value, VideoCutStatusEnum::Failed->value])
                ->orWhere(fn (Builder $stale): Builder => $stale
                    ->where('render_status', VideoCutStatusEnum::Generating->value)
                    ->where('updated_at', '<', now()->subMinutes(30))))
            ->update([
                'render_status' => VideoCutStatusEnum::Generating,
                'render_error' => null,
            ]);

        if ($claimed !== 1) {
            $this->toast('Este corte editado já está em geração.', 'danger');

            return null;
        }

        dispatch(new StartVideoCutEditRenderJob($edit->id));
        $this->toast('Corte editado em geração — ele aparece em /meus-videos quando ficar pronto.');

        return VideoCutStatusEnum::Generating->value;
    }

    /**
     * Claim atômico do face tracking. O desfecho chega pelo webhook
     * /api/webhook/face-tracking, que SOBRESCREVE os keyframes da edição — o
     * client confirma com o operador antes de chamar, senão ajuste manual
     * some sem aviso.
     */
    public function generateTracking(): ?string
    {
        $edit = $this->editId !== null
            ? VideoCutEdit::query()->where('video_cut_id', $this->cut->id)->find($this->editId)
            : null;

        if (! $edit instanceof VideoCutEdit) {
            $this->toast('Salve a edição antes de gerar o tracking.', 'danger');

            return null;
        }

        // ponytail: mesmo destravamento do render — processing parado há 30 min
        // é webhook perdido; watchdog em cron se isso passar a doer.
        $claimed = VideoCutEdit::query()
            ->whereKey($edit->id)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('tracking_status')
                ->orWhereIn('tracking_status', [TranscriptionStatusEnum::Ready->value, TranscriptionStatusEnum::Failed->value])
                ->orWhere(fn (Builder $stale): Builder => $stale
                    ->where('tracking_status', TranscriptionStatusEnum::Processing->value)
                    ->where('updated_at', '<', now()->subMinutes(30))))
            ->update([
                'tracking_status' => TranscriptionStatusEnum::Processing,
                'tracking_error' => null,
            ]);

        if ($claimed !== 1) {
            $this->toast('O tracking desta edição já está rodando.', 'danger');

            return null;
        }

        dispatch(new StartFaceTrackingJob($edit->id));
        $this->toast('Tracking em andamento — recarregue em instantes pra ver os keyframes.');

        return TranscriptionStatusEnum::Processing->value;
    }

    private function latestEditId(): ?int
    {
        $id = VideoCutEdit::query()->where('video_cut_id', $this->cut->id)->latest('id')->value('id');

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
            ? VideoCutEdit::query()->where('video_cut_id', $this->cut->id)->find($this->editId)
            : null;

        $settings = $edit->settings ?? [];
        $speakerColors = $this->sanitizeSpeakerColors($settings['speakerColors'] ?? null);

        $baseSettings = [
            'version' => 1,
            'background' => (string) ($settings['background'] ?? '#000000'),
            'captions' => (bool) ($settings['captions'] ?? false),
            'captionColor' => (string) ($settings['captionColor'] ?? '#ffffff'),
            'captionCase' => (string) ($settings['captionCase'] ?? 'sentence'),
        ];

        return [
            'editId' => $edit?->id,
            'renderStatus' => $edit?->render_status?->value,
            'trackingStatus' => $edit?->tracking_status?->value,
            'videoUrl' => $this->cut->presignedUrl(),
            'mode' => $edit->mode ?? self::DEFAULT_MODE,
            'keyframes' => $edit->keyframes ?? [],
            'settings' => $speakerColors === [] ? $baseSettings : $baseSettings + ['speakerColors' => $speakerColors],
            'sourceMeta' => $edit?->source_meta,
        ];
    }

    /**
     * Valida a estrutura (rejeita) e clampa os valores (silencioso — o
     * client aplica as mesmas regras; aqui é defesa).
     *
     * @param  array<string, mixed>  $payload
     * @return array{mode: string, keyframes: list<array{t: float, mode: string, regions: list<array{x: float, y: float, w: float, h: float}>}>, settings: array{version: int, background: string, captions: bool, captionColor: string, captionCase: string, speakerColors?: array<int, string>}, sourceMeta: array{width: int, height: int, duration: float}}|null
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

        $sanitized = [
            'version' => 1,
            'background' => mb_strtolower($background),
            'captions' => (bool) ($settings['captions'] ?? false),
            'captionColor' => mb_strtolower($captionColor),
            'captionCase' => $captionCase,
        ];

        // Só entra quando há locutor detectado: edição sem tracking mantém o
        // settings idêntico ao que sempre foi gravado.
        $speakerColors = $this->sanitizeSpeakerColors($settings['speakerColors'] ?? null);
        if ($speakerColors !== []) {
            $sanitized['speakerColors'] = $speakerColors;
        }

        return [
            'mode' => $mode,
            'keyframes' => $deduped,
            'settings' => $sanitized,
            'sourceMeta' => ['width' => $width, 'height' => $height, 'duration' => round($duration, 3)],
        ];
    }

    /**
     * Mapa id do locutor -> hex da legenda. A chave é int porque PHP converte
     * chave string numérica em int; o JSON ainda sai como objeto porque o id
     * começa em 1, e é assim que o serviço de render faz o lookup.
     *
     * @return array<int, string>
     */
    private function sanitizeSpeakerColors(mixed $rawColors): array
    {
        if (! is_array($rawColors)) {
            return [];
        }

        $colors = [];

        foreach ($rawColors as $speaker => $color) {
            if (! is_numeric($speaker)) {
                continue;
            }

            if ((int) $speaker < 1) {
                continue;
            }

            if (! is_string($color)) {
                continue;
            }

            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
                continue;
            }

            $colors[(int) $speaker] = mb_strtolower($color);
        }

        return $colors;
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
        ]);
    }
}
