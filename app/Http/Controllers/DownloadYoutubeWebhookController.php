<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DownloadYoutube\DownloadYoutubeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DownloadYoutubeWebhookController extends Controller
{
    public function __invoke(Request $request, DownloadYoutubeImportService $service): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $result = $service->importWebhookPayload($payload);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }
}
