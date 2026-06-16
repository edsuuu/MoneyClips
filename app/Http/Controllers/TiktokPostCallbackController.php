<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TiktokPost;
use App\Models\YoutubeShort;
use App\Support\Cast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

final class TiktokPostCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{job_id: string, video_id: string, status: string, title?: string|null, error?: string|null, finished_at?: string|null} $validated */
        $validated = $request->validate([
            'job_id' => ['required', 'string'],
            'video_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:completed,dry-run,failed'],
            'session_valid' => ['sometimes', 'boolean'],
            'login_failed' => ['sometimes', 'boolean'],
            'title' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
            'finished_at' => ['nullable', 'date'],
        ]);

        $status = $validated['status'];
        $finishedAtValue = Cast::str($validated['finished_at'] ?? '');
        $finishedAt = $finishedAtValue !== ''
            ? Date::parse($finishedAtValue)
            : now();

        TiktokPost::query()->updateOrCreate(
            ['uuid' => $validated['job_id']],
            [
                'youtube_id' => $validated['video_id'],
                'video_key' => sprintf('shorts/%s.mp4', $validated['video_id']),
                'title' => Cast::str($validated['title'] ?? '') ?: null,
                'status' => $status,
                'error' => Cast::str($validated['error'] ?? '') ?: null,
                'posted_at' => $status === 'completed' ? $finishedAt : null,
            ],
        );

        // Confirmação explícita de sucesso no TikTok na fonte única (youtube_shorts),
        // casando pelo youtube_id. Só conta publicação real (completed), não dry-run.
        if ($status === 'completed') {
            YoutubeShort::query()
                ->where('youtube_id', $validated['video_id'])
                ->whereNull('posted_tiktok_at')
                ->update(['posted_tiktok_at' => $finishedAt]);
        }

        return response()->json(['ok' => true]);
    }
}
