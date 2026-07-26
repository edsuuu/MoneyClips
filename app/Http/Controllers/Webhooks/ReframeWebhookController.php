<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\VideoCutStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\ReframeWebhookRequest;
use App\Http\Resources\StatusResource;
use App\Models\ReframeEdit;
use App\Models\YoutubeShort;
use App\Services\API\Discord\DiscordNotifierService;
use Illuminate\Support\Facades\DB;

final class ReframeWebhookController extends Controller
{
    public function __invoke(ReframeWebhookRequest $request, DiscordNotifierService $discord): StatusResource
    {
        $edit = ReframeEdit::query()->with('videoCut.video')->where('uuid', $request->editUuid())->first();

        if (! $edit instanceof ReframeEdit) {
            return new StatusResource('unknown-job', 404);
        }

        if ($request->failed()) {
            $claimed = ReframeEdit::query()
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
        $video = $edit->videoCut?->video;

        if ($video === null) {
            $claimed = ReframeEdit::query()
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
        $status = DB::transaction(function () use ($edit, $video, $renderedPath): string {
            $claimed = ReframeEdit::query()
                ->whereKey($edit->id)
                ->where('render_status', VideoCutStatusEnum::Generating->value)
                ->update([
                    'render_status' => VideoCutStatusEnum::Ready,
                    'rendered_path' => $renderedPath,
                    'render_error' => null,
                ]);

            if ($claimed !== 1) {
                return 'already-finished';
            }

            $short = YoutubeShort::query()->updateOrCreate(
                ['youtube_id' => 'reframe-'.$edit->uuid],
                [
                    'title' => $video->name,
                    'video_path' => $renderedPath,
                    'downloaded_at' => now(),
                ],
            );

            ReframeEdit::query()->whereKey($edit->id)->update(['youtube_short_id' => $short->id]);

            return 'ready';
        });

        return new StatusResource($status);
    }
}
