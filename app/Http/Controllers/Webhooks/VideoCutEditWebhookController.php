<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\VideoCutStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\VideoCutEditWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\File;
use App\Models\VideoCutEdit;
use App\Models\YoutubeShort;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\DB;

final class VideoCutEditWebhookController extends Controller
{
    public function __invoke(VideoCutEditWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $edit = VideoCutEdit::query()->with('videoCut.video')->where('uuid', $request->editUuid())->first();

        if (! $edit instanceof VideoCutEdit) {
            return new StatusResource('unknown-job', 404);
        }

        if ($request->failed()) {
            $claimed = VideoCutEdit::query()
                ->whereKey($edit->id)
                ->where('render_status', VideoCutStatusEnum::Generating->value)
                ->update([
                    'render_status' => VideoCutStatusEnum::Failed,
                    'render_error' => $request->error() ?? 'O render do corte editado falhou sem detalhe.',
                ]);

            if ($claimed === 1) {
                $discord->error(
                    '❌ Render de corte editado falhou',
                    sprintf('Edição #%d (%s)%s%s', $edit->id, $edit->uuid, PHP_EOL, $request->error() ?? ''),
                );
            }

            return new StatusResource('failure-recorded');
        }

        // Corte/vídeo pai são soft-deletáveis durante o render: sem eles não há
        // path de saída nem estoque — fecha como falha em vez de estourar 500 e
        // deixar a edição presa em "generating".
        $cut = $edit->videoCut;
        $video = $cut?->video;

        if ($cut === null || $video === null) {
            $claimed = VideoCutEdit::query()
                ->whereKey($edit->id)
                ->where('render_status', VideoCutStatusEnum::Generating->value)
                ->update([
                    'render_status' => VideoCutStatusEnum::Failed,
                    'render_error' => 'O corte foi removido durante o render.',
                ]);

            if ($claimed === 1) {
                $discord->error(
                    '❌ Render de corte editado sem corte pai',
                    sprintf('Edição #%d (%s): corte removido durante o render.', $edit->id, $edit->uuid),
                );
            }

            return new StatusResource('failure-recorded');
        }

        $renderedPath = $edit->renderOutputPath();

        // Transação: se a criação do short falhar, o claim desfaz e o retry do
        // webhook refaz o conjunto — sem edição "ready" com estoque faltando.
        // ponytail: o render virar YoutubeShort é o modelo de estoque atual;
        // corrigir pra um artefato próprio é dívida conhecida.
        $status = DB::transaction(function () use ($edit, $cut, $video, $renderedPath): string {
            $claimed = VideoCutEdit::query()
                ->whereKey($edit->id)
                ->where('render_status', VideoCutStatusEnum::Generating->value)
                ->update([
                    'render_status' => VideoCutStatusEnum::Ready,
                    'render_error' => null,
                ]);

            if ($claimed !== 1) {
                return 'already-finished';
            }

            File::query()->updateOrCreate(
                ['video_cut_id' => $cut->id, 'type' => File::EDIT, 'path' => $renderedPath],
                ['video_id' => $video->id, 'mime_type' => 'video/mp4'],
            );

            $short = YoutubeShort::query()->updateOrCreate(
                ['youtube_id' => 'reframe-'.$edit->uuid],
                [
                    'title' => $video->name,
                    'video_path' => $renderedPath,
                    'downloaded_at' => now(),
                ],
            );

            VideoCutEdit::query()->whereKey($edit->id)->update(['youtube_short_id' => $short->id]);

            return 'ready';
        });

        return new StatusResource($status);
    }
}
