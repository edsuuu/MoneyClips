<?php

declare(strict_types=1);

namespace App\Livewire\Uploads;

use App\Enums\TranscriptionStatusEnum;
use App\Enums\VideoCutStatusEnum;
use App\Enums\VideoStatusEnum;
use App\Jobs\StartCutRenderJob;
use App\Livewire\Concerns\EditsTranscript;
use App\Livewire\Concerns\WithToasts;
use App\Models\File;
use App\Models\Video;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

use function in_array;
use function route;
use function view;

final class Show extends Component
{
    use EditsTranscript;
    use WithToasts;

    public Video $video;

    public ?string $fallbackUrl = null;

    /**
     * O dono e filtrado aqui, e nao no resolveRouteBinding do Video: a rota e
     * `Route::view`, que nao dispara model binding.
     */
    public function mount(string $uuid): void
    {
        $this->video = Video::query()
            ->with('files')
            ->where('uuid', $uuid)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        // Assinada UMA vez: presigned muda a cada assinatura, e um fallbackUrl
        // novo a cada wire:poll mudaria o x-data do player no morph — o Alpine
        // re-inicializa o componente e o vídeo pisca pro poster.
        $this->fallbackUrl = $this->video->presignedUrl();
    }

    /**
     * Só o texto é editável — start/end/words vêm do S3 (autoritativo) e nunca
     * do cliente. Nada de apagar/adicionar segmento: aplica text por índice.
     *
     * @param  list<array{i?: mixed, text?: mixed}>  $edits
     */
    public function saveTranscript(array $edits): bool
    {
        return $this->saveTranscriptText($this->video->transcriptPath(), $edits);
    }

    public function addCut(float $start, float $end): void
    {
        $startSeconds = (int) $start;
        $endSeconds = (int) $end;

        if (! is_finite($start) || ! is_finite($end) || ! $this->validCutBounds($startSeconds, $endSeconds)) {
            return;
        }

        if ($this->duplicateCutExists($startSeconds, $endSeconds)) {
            $this->toast('Esse corte já existe.', 'danger');

            return;
        }

        $this->video->cuts()->create([
            'start_seconds' => $startSeconds,
            'end_seconds' => $endSeconds,
        ]);
    }

    public function updateCut(int $cutId, string $start, string $end): void
    {
        $startSeconds = $this->parseTimecode($start);
        $endSeconds = $this->parseTimecode($end);

        if ($startSeconds === null || $endSeconds === null) {
            $this->toast('Tempo inválido — use o formato mm:ss.', 'danger');

            return;
        }

        if (! $this->validCutBounds($startSeconds, $endSeconds)) {
            return;
        }

        $cut = $this->video->cuts()->whereKey($cutId)->first();

        if (! $cut instanceof VideoCut || $cut->status === VideoCutStatusEnum::Generating) {
            $this->toast('Este corte não pode ser editado agora.', 'danger');

            return;
        }

        if ($cut->start_seconds === $startSeconds && $cut->end_seconds === $endSeconds) {
            return;
        }

        if ($this->duplicateCutExists($startSeconds, $endSeconds, $cutId)) {
            $this->toast('Esse corte já existe.', 'danger');

            return;
        }

        $cut->update([
            'start_seconds' => $startSeconds,
            'end_seconds' => $endSeconds,
            'status' => VideoCutStatusEnum::Draft,
            'transcription_status' => null,
            'error' => null,
        ]);

        $this->toast('Corte atualizado — gere o clip de novo.');
    }

    public function removeCut(int $cutId): void
    {
        $cut = $this->video->cuts()->whereKey($cutId)->first();

        if (! $cut instanceof VideoCut) {
            return;
        }

        if ($cut->status === VideoCutStatusEnum::Generating) {
            $this->toast('Não dá pra apagar um corte enquanto ele está sendo gerado.', 'danger');

            return;
        }

        $edits = VideoCutEdit::query()
            ->where('video_cut_id', $cut->id)
            ->get(['id', 'uuid', 'render_status']);

        if ($edits->contains(fn (VideoCutEdit $edit): bool => $edit->render_status === VideoCutStatusEnum::Generating)) {
            $this->toast('Não dá pra apagar um corte com edição sendo gerada.', 'danger');

            return;
        }

        try {
            Storage::disk('s3')->deleteDirectory($cut->prefix());
        } catch (Throwable $throwable) {
            report($throwable);
            $this->toast('Não foi possível apagar os arquivos do corte. Tente de novo.', 'danger');

            return;
        }

        // Os renders das edições moram dentro do prefixo apagado: shorts de
        // corte editado ainda não postados sairiam do estoque apontando pra
        // arquivo morto — vão junto. Postados ficam como histórico.
        YoutubeShort::query()
            ->whereIn('youtube_id', $edits->map(fn (VideoCutEdit $edit): string => 'reframe-'.$edit->uuid))
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at')
            ->delete();

        $cut->files()->delete();
        $cut->delete();
    }

    public function generateCut(int $cutId): void
    {
        $claimed = VideoCut::query()
            ->whereKey($cutId)
            ->where('video_id', $this->video->id)
            ->whereIn('status', [VideoCutStatusEnum::Draft, VideoCutStatusEnum::Failed])
            ->update([
                'status' => VideoCutStatusEnum::Generating,
                'transcription_status' => null,
                'error' => null,
            ]);

        if ($claimed !== 1) {
            $this->toast('Este corte já está em geração ou pronto.', 'danger');

            return;
        }

        dispatch(new StartCutRenderJob($cutId));
    }

    public function suggestAiCuts(): never
    {
        dd('Implementar depois');
    }

    private function duplicateCutExists(int $start, int $end, ?int $ignoreId = null): bool
    {
        $query = $this->video->cuts()
            ->where('start_seconds', $start)
            ->where('end_seconds', $end);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }

    private function validCutBounds(int $start, int $end): bool
    {
        $duration = (int) $this->video->duration_seconds;

        if ($start < 0 || $end <= $start || $end > $duration) {
            $this->toast('Corte inválido.', 'danger');

            return false;
        }

        if ($end - $start > VideoCut::MAX_DURATION_SECONDS) {
            $this->toast('O corte pode ter no máximo 3 minutos.', 'danger');

            return false;
        }

        return true;
    }

    private function parseTimecode(string $raw): ?int
    {
        $raw = mb_trim($raw);

        if (preg_match('/^\d+(:\d{1,2}){0,2}$/', $raw) !== 1) {
            return null;
        }

        $seconds = 0;
        foreach (explode(':', $raw) as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }

        return $seconds;
    }

    private function timecode(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $rest = sprintf('%02d:%02d', intdiv($seconds % 3600, 60), $seconds % 60);

        return $hours > 0 ? $hours.':'.$rest : $rest;
    }

    /** @return array<string, float|int|string>|null */
    private function storyboard(Video $video): ?array
    {
        $file = $video->file(File::STORYBOARD);

        if (! $file instanceof File) {
            return null;
        }

        return [
            'url' => route('hls.segment', [$video->uuid, 'storyboard.jpg']),
            'cols' => (int) ($file->meta['cols'] ?? 0),
            'rows' => (int) ($file->meta['rows'] ?? 0),
            'interval' => (float) ($file->meta['interval'] ?? 0),
            'tileWidth' => (int) ($file->meta['tile_width'] ?? 0),
            'tileHeight' => (int) ($file->meta['tile_height'] ?? 0),
        ];
    }

    public function render(): View
    {
        $video = $this->video->fresh(['files', 'cuts']) ?? $this->video;
        $isPackaging = in_array($video->status, [VideoStatusEnum::Downloading, VideoStatusEnum::Uploaded, VideoStatusEnum::Packaging], true);
        $transcription = $video->transcription_status;

        return view('livewire.uploads.show', [
            'dateLabel' => $video->created_at?->format('d/m/Y H:i') ?? '—',
            'isReady' => $video->isReady(),
            'isPackaging' => $isPackaging,
            'isTranscribing' => $transcription === TranscriptionStatusEnum::Processing,
            'transcriptionLabel' => $transcription?->label(),
            'transcriptionBadgeClass' => $transcription?->badgeClass() ?? '',
            'subtitlesUrl' => $transcription === TranscriptionStatusEnum::Ready
                ? route('uploads.subtitles', $video->uuid)
                : null,
            'transcriptSegments' => $this->transcriptSegmentsFrom(
                $video->transcriptPath(),
                $video->transcription_status === TranscriptionStatusEnum::Ready,
            ),
            'captionsKey' => $video->uuid,
            'statusLabel' => $video->status->label(),
            'progress' => $video->progress,
            'error' => $video->error,
            'hlsUrl' => $video->isReady() ? route('hls.master', $video->uuid) : null,
            'fallbackUrl' => $this->fallbackUrl,
            'posterUrl' => $video->file(File::POSTER) === null ? null : route('hls.segment', [$video->uuid, 'poster.jpg']),
            'renditions' => $video->renditions(),
            'storyboard' => $this->storyboard($video),
            'durationSeconds' => (int) $video->duration_seconds,
            'cutItems' => $video->cuts->values()->map(fn (VideoCut $cut, int $index): array => [
                'id' => $cut->id,
                'number' => $index + 1,
                'title' => 'Corte '.($index + 1),
                'start' => $cut->start_seconds,
                'end' => $cut->end_seconds,
                'startLabel' => $this->timecode($cut->start_seconds),
                'endLabel' => $this->timecode($cut->end_seconds),
                'durationShort' => $cut->end_seconds - $cut->start_seconds < 60
                    ? ($cut->end_seconds - $cut->start_seconds).'s'
                    : $this->timecode($cut->end_seconds - $cut->start_seconds),
                'statusLabel' => $cut->status->label(),
                'badgeClass' => $cut->status->badgeClass(),
                'isAi' => $cut->is_ai_generated,
                'isGenerating' => $cut->status === VideoCutStatusEnum::Generating,
                'isFailed' => $cut->status === VideoCutStatusEnum::Failed,
                'canGenerate' => $cut->status->canGenerate(),
                'editorUrl' => $cut->status === VideoCutStatusEnum::Ready
                    ? route('video-editor.index', $cut->uuid)
                    : null,
            ])->all(),
            'hasBusyCuts' => $video->cuts->contains(
                fn (VideoCut $cut): bool => $cut->status === VideoCutStatusEnum::Generating
                    || $cut->transcription_status === TranscriptionStatusEnum::Processing,
            ),
        ]);
    }
}
