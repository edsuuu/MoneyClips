<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\TranscriptionStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\FaceTrackingWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\VideoCut;
use App\Models\VideoCutEdit;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fecha o ciclo do face tracking: grava os keyframes na própria edição (é a
 * mesma coluna que o editor carrega, então o operador já abre a tela com a
 * trajetória pronta pra assistir e ajustar) e a timeline de locutor num
 * artefato à parte, que a legenda usa pra colorir cada pessoa.
 */
final class FaceTrackingWebhookController extends Controller
{
    private const array SPEAKER_PALETTE = ['#ffd166', '#4cc9f0', '#06d6a0', '#ef476f', '#b388ff', '#ff9f1c'];

    public function __invoke(FaceTrackingWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $edit = VideoCutEdit::query()->with('videoCut')->where('uuid', $request->uuid())->first();

        if (! $edit instanceof VideoCutEdit) {
            return new StatusResource('unknown-job', 404);
        }

        if ($edit->tracking_status?->isTerminal()) {
            return new StatusResource('already-finished');
        }

        $claimed = VideoCutEdit::query()
            ->whereKey($edit->id)
            ->where('tracking_status', TranscriptionStatusEnum::Processing->value)
            ->update([
                'tracking_status' => $request->failed() ? TranscriptionStatusEnum::Failed : TranscriptionStatusEnum::Ready,
                'tracking_error' => $request->failed() ? $request->error() : null,
            ]);

        if ($claimed !== 1) {
            return new StatusResource('already-finished');
        }

        if ($request->failed()) {
            $discord->error(
                '❌ Face tracking falhou',
                sprintf('Edição #%d (%s)%s%s', $edit->id, $edit->uuid, PHP_EOL, $request->error() ?? ''),
            );

            return new StatusResource('failure-recorded');
        }

        return $this->persist($edit, $request, $discord);
    }

    private function persist(VideoCutEdit $edit, FaceTrackingWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $keyframes = $request->keyframes();

        try {
            $settings = $edit->settings ?? [];
            $settings['speakerColors'] = $this->seedSpeakerColors(
                $request->speakers(),
                is_array($settings['speakerColors'] ?? null) ? $settings['speakerColors'] : [],
            );

            $edit->fill([
                'mode' => $keyframes[0]['mode'],
                'keyframes' => $keyframes,
                'settings' => $settings,
            ]);

            $source = $request->source();
            if (is_null($edit->source_meta) && ! is_null($source)) {
                $edit->source_meta = $source;
            }

            $edit->save();

            $this->storeSpeakers($edit->videoCut, $request->speakers());
        } catch (Throwable $throwable) {
            Log::channel('daily')->error('[ERRO][FaceTracking] Falha ao gravar o resultado do tracking.', [
                'edit_id' => $edit->id,
                'uuid' => $edit->uuid,
                'exception' => $throwable,
            ]);

            VideoCutEdit::query()->whereKey($edit->id)->update([
                'tracking_status' => TranscriptionStatusEnum::Failed,
                'tracking_error' => $throwable->getMessage(),
            ]);

            $discord->error(
                '❌ Face tracking falhou ao gravar',
                sprintf('Edição #%d%s%s', $edit->id, PHP_EOL, $throwable->getMessage()),
            );

            return new StatusResource('failure-recorded');
        }

        Log::channel('daily')->info('[INFO][FaceTracking] Keyframes gravados.', [
            'edit_id' => $edit->id,
            'keyframes' => count($keyframes),
            'speakers' => count($request->speakers()),
        ]);

        return new StatusResource('ready');
    }

    /**
     * Semeia uma cor por locutor detectado pra que o editor tenha o que
     * mostrar sem precisar ler o artefato de locutores do S3 a cada render.
     * Cor já escolhida pelo operador é preservada.
     *
     * A chave é int porque PHP converte chave string numérica em int de
     * qualquer jeito. O JSON sai como objeto ({"1": "#..."}) porque o id do
     * locutor começa em 1 — invariante garantida na validação do webhook.
     *
     * @param  list<array{start: float, end: float, speaker: int}>  $speakers
     * @param  array<array-key, mixed>  $existing
     * @return array<int, string>
     */
    private function seedSpeakerColors(array $speakers, array $existing): array
    {
        $ids = array_values(array_unique(array_column($speakers, 'speaker')));
        sort($ids);

        $colors = [];

        foreach ($ids as $index => $id) {
            $current = $existing[$id] ?? null;

            $colors[$id] = is_string($current) && preg_match('/^#[0-9a-fA-F]{6}$/', $current) === 1
                ? mb_strtolower($current)
                : self::SPEAKER_PALETTE[$index % count(self::SPEAKER_PALETTE)];
        }

        return $colors;
    }

    /**
     * @param  list<array{start: float, end: float, speaker: int}>  $speakers
     */
    private function storeSpeakers(?VideoCut $cut, array $speakers): void
    {
        if (! $cut instanceof VideoCut || $speakers === []) {
            return;
        }

        Storage::disk('s3')->put(
            $cut->speakersPath(),
            json_encode(['speakers' => $speakers], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }
}
