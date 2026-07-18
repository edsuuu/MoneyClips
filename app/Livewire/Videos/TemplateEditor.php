<?php

declare(strict_types=1);

namespace App\Livewire\Videos;

use App\Livewire\Concerns\WithToasts;
use App\Models\YoutubeShort;
use App\Services\Processing\TemplateRenderOptionsData;
use App\Services\Processing\TemplateStyleEnum;
use App\Services\Processing\VideoProcessingService;
use App\Support\Hashtags;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Editor de template (tab do /meus-videos, design Estoque.dc.html):
 * escolhe o vídeo base do estoque, o estilo (variants do AutoCaption),
 * nome do canal/@handle e título/hashtags do post. "Salvar como pronto
 * para postar" dispara o render e marca o vídeo como pronto ao concluir.
 */
final class TemplateEditor extends Component
{
    use WithToasts;

    public ?int $sourceId = null;

    public string $style = 'white';

    public string $channelName = '';

    public string $channelHandle = '';

    public string $title = '';

    public string $hashtags = '';

    public function mount(): void
    {
        $style = TemplateStyleEnum::tryFrom((string) config('services.autocaption.default_style'));
        $this->style = ($style ?? TemplateStyleEnum::White)->value;

        $this->channelName = (string) config('services.autocaption.channel_name');
        $this->channelHandle = (string) config('services.autocaption.channel_handle');
    }

    #[On('template-editor-select')]
    public function selectSource(int $shortId): void
    {
        $short = YoutubeShort::query()->find($shortId);
        if (! $short instanceof YoutubeShort) {
            return;
        }

        $this->sourceId = $short->id;
        $this->title = $short->title ?? '';
        $this->hashtags = Hashtags::toInput($short->hashtags);
    }

    public function setStyle(string $style): void
    {
        $this->style = TemplateStyleEnum::tryFrom($style) instanceof TemplateStyleEnum ? $style : 'white';
    }

    public function save(): void
    {
        $short = $this->sourceId !== null ? YoutubeShort::query()->find($this->sourceId) : null;
        if (! $short instanceof YoutubeShort) {
            $this->toast('Escolha o vídeo base do template.', 'danger');

            return;
        }

        if (mb_trim($this->channelName) === '') {
            $this->toast('Informe o nome do canal.', 'danger');

            return;
        }

        // Título/hashtags editados valem pro post final.
        $title = mb_trim($this->title);
        $short->title = $title === '' ? $short->title : $title;
        $short->hashtags = Hashtags::parse($this->hashtags);
        $short->save();

        try {
            resolve(VideoProcessingService::class)->startTemplateRender($short, new TemplateRenderOptionsData(
                style: $this->currentStyle(),
                channelName: mb_trim($this->channelName),
                channelHandle: mb_trim($this->channelHandle),
                markReady: true,
            ));
        } catch (Throwable $throwable) {
            $this->toast($throwable->getMessage(), 'danger');

            return;
        }

        $this->toast('Render enfileirado — o vídeo aparece em "Com template" quando terminar.');
        $this->dispatch('template-queued');
    }

    // ── Internos ─────────────────────────────────────────────────────────

    private function currentStyle(): TemplateStyleEnum
    {
        return TemplateStyleEnum::tryFrom($this->style) ?? TemplateStyleEnum::White;
    }

    /**
     * Classes de apresentação por estilo (prévia grande e miniatura do
     * seletor), consumidas via @class na view.
     *
     * @return array{preview: string, swatch: string, hasHeader: bool}
     */
    private function stylePresentation(TemplateStyleEnum $style): array
    {
        return match ($style) {
            TemplateStyleEnum::White => ['preview' => 'border-slate-300 bg-slate-100 text-slate-900', 'swatch' => 'bg-slate-200', 'hasHeader' => true],
            TemplateStyleEnum::Black => ['preview' => 'border-slate-700 bg-black text-slate-50', 'swatch' => 'bg-black', 'hasHeader' => true],
            TemplateStyleEnum::Vertical => ['preview' => 'border-slate-700 bg-slate-950 text-slate-50', 'swatch' => 'bg-slate-950', 'hasHeader' => false],
        };
    }

    public function render(): View
    {
        $current = $this->currentStyle();
        $presentation = $this->stylePresentation($current);
        $channelName = mb_trim($this->channelName);

        $sources = YoutubeShort::query()
            ->whereNotNull('video_path')
            ->whereNull('template_rendered_at')
            ->whereNull('posted_youtube_at')
            ->whereNull('posted_tiktok_at')
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'youtube_id'])
            ->map(fn (YoutubeShort $s): array => [
                'id' => $s->id,
                'title' => $s->title ?? $s->youtube_id,
                'selected' => $this->sourceId === $s->id,
            ])
            ->values()->all();

        return view('livewire.videos.template-editor', [
            'sources' => $sources,
            'styles' => array_map(fn (TemplateStyleEnum $style): array => [
                'value' => $style->value,
                'label' => $style->label(),
                'selected' => $current === $style,
                'swatchClass' => $this->stylePresentation($style)['swatch'],
                'hasHeader' => $this->stylePresentation($style)['hasHeader'],
            ], TemplateStyleEnum::cases()),
            'previewClass' => $presentation['preview'],
            'showHeader' => $presentation['hasHeader'],
            'isVertical' => $current === TemplateStyleEnum::Vertical,
            'channelInitial' => mb_strtoupper(mb_substr($channelName === '' ? 'C' : $channelName, 0, 1)),
            'channelNameDisplay' => $channelName !== '' ? $channelName : 'Nome do canal',
            'channelHandleDisplay' => mb_trim($this->channelHandle) !== '' ? mb_trim($this->channelHandle) : '@handle',
            'captionPositionLabel' => $current->captionPosition() === 'inside' ? 'sobre o vídeo' : 'abaixo do vídeo',
        ]);
    }
}
