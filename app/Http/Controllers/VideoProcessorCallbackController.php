<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Status;
use App\Services\VideoProcessor\VideoProcessorCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VideoProcessorCallbackController extends Controller
{
    public function __invoke(Request $request, VideoProcessorCallbackService $service): JsonResponse
    {
        $this->assertToken($request);

        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        if (empty($payload['video_id'])) {
            return response()->json(['error' => 'video_id ausente'], 422);
        }

        $video = $service->handle($payload);
        $status = $video->status;

        return response()->json([
            'ok' => true,
            'video_uuid' => $video->uuid,
            'status' => $status instanceof Status ? $status->key : null,
        ]);
    }

    private function assertToken(Request $request): void
    {
        $expectedVal = config('services.video_processor.callback_token');
        $expected = is_string($expectedVal) ? $expectedVal : '';
        if ($expected === '') {
            return; // sem token configurado = sem validação (dev)
        }

        $provided = (string) $request->header('Authorization', '');
        $accepted = [$expected, 'Bearer '.$expected];

        abort_unless(in_array($provided, $accepted, true), 401, 'Token de callback inválido.');
    }
}
