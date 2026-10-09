<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoCutEdit;
use App\Services\CutEdit\CutEditKeyframeService;
use App\Services\CutEdit\CutEditValidatorService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-import-type StockAssetItem from CutEditValidatorService
 * @phpstan-import-type ImageItem from CutEditValidatorService
 */
final readonly class VideoCutEditRenderService
{
    private const array OVERLAY_KINDS = ['memes' => 'meme', 'meme_clips' => 'meme_clip', 'emoji' => 'emoji'];

    public function __construct(private CutEditKeyframeService $keyframes) {}

    /**
     * O desfecho chega por webhook chaveado pelo uuid da edição (o serviço ecoa
     * `edit_uuid`). O Laravel manda as chaves de destino — o serviço só escreve
     * onde mandaram. O transcript vai no corpo quando as legendas estão ligadas
     * e a transcrição do corte está pronta.
     *
     * @param  array<mixed>|null  $transcript
     *
     * @throws ConnectionException
     */
    public function startRender(VideoCutEdit $edit, ?array $transcript): void
    {
        $cut = $edit->videoCut;

        throw_unless($cut !== null, RuntimeException::class, 'Edição sem corte pai não tem fonte pra render.');

        $response = $this->client()->post('/reframe', [
            'edit_uuid' => $edit->uuid,
            'source_key' => $cut->clipPath(),
            'output_key' => $edit->renderOutputPath(),
            'source' => $edit->source_meta,
            'keyframes' => $edit->keyframes,
            'settings' => $edit->settings ?? [],
            'transcript' => $transcript,
            'webhook_url' => (string) config('services.video_cut_edit.webhook_url'),
            ...$this->specFields($edit),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Serviço de reframe respondeu %d: %s',
                $response->status(),
                Str::limit($response->body(), 300),
            ));
        }
    }

    /**
     * Spec da edição por IA vira legenda literal, cortes e zoom de punch/ritmo
     * no /reframe. Sem spec, nenhum campo vai: `captions: []` ligaria a legenda
     * literal vazia e apagaria o karaokê da edição manual.
     *
     * ponytail: o banco guarda só os keyframes-base do tracking (editáveis no
     * /editor-de-video) e o punch é composto aqui, a cada render — então o
     * preview do editor não mostra o zoom dos punches, só o render mostra. O
     * upgrade é o preview aplicar o spec.
     *
     * @return array<string, mixed>
     */
    private function specFields(VideoCutEdit $edit): array
    {
        $spec = $edit->spec;

        if (is_null($spec)) {
            return [];
        }

        $fields = [
            'keyframes' => $this->keyframes->compose($edit->keyframes, $spec, (float) ($edit->source_meta['duration'] ?? 0.0)),
            'cuts' => $spec['cuts'],
            'dead_air' => true,
            'captions' => $spec['captions'],
            'caption_preset' => $spec['caption_preset'],
            ...$this->stockFields($spec),
        ];

        $watermark = mb_trim((string) config('services.video_cut_edit.watermark'));

        if ($watermark !== '') {
            $fields['watermark'] = $watermark;
        }

        return $fields;
    }

    /**
     * Imagem (card/small), figurinha, vídeo-meme e emoji viram overlay; o som
     * vai em `sfx[]` junto com o áudio do vídeo-meme (a mesma key, tocando do
     * começo da janela).
     *
     * @param  array{images?: list<ImageItem>, memes?: list<StockAssetItem>, meme_clips?: list<StockAssetItem>, emoji?: list<StockAssetItem>, sfx?: list<StockAssetItem>, ...}  $spec
     * @return array{overlays: list<array{key: string, t: array{0: float, 1: float}, kind: string}>, sfx: list<array{key: string, t: float}>}
     */
    private function stockFields(array $spec): array
    {
        $overlays = [];
        $sfx = [];

        foreach ($spec['images'] ?? [] as $image) {
            $overlays[] = ['key' => $image['key'], 't' => $image['t'], 'kind' => $image['size']];
        }

        foreach (self::OVERLAY_KINDS as $field => $kind) {
            foreach ($spec[$field] ?? [] as $item) {
                $overlays[] = ['key' => $item['key'], 't' => $item['t'], 'kind' => $kind];
            }
        }

        foreach ($spec['meme_clips'] ?? [] as $clip) {
            if (! $clip['has_audio']) {
                continue;
            }

            $sfx[] = ['key' => $clip['key'], 't' => $clip['t'][0]];
        }

        foreach ($spec['sfx'] ?? [] as $sound) {
            $sfx[] = ['key' => $sound['key'], 't' => $sound['t'][0]];
        }

        return ['overlays' => $overlays, 'sfx' => $sfx];
    }

    private function client(): PendingRequest
    {
        $token = (string) config('services.hls.api_token');

        return Http::baseUrl(mb_rtrim((string) config('services.hls.base_url'), '/'))
            ->connectTimeout(10)
            ->timeout((int) config('services.hls.timeout', 60))
            ->when($token !== '', fn (PendingRequest $request): PendingRequest => $request->withToken($token));
    }
}
