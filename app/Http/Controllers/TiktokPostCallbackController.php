<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SocialPost;
use App\Models\YoutubeShort;
use App\Services\DiscordNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do microserviço tiktok-uploader. Recebe o resultado final do upload
 * (completed | dry-run | failed), persiste no ledger tiktok_posts, marca
 * posted_tiktok_at no estoque (youtube_shorts) e dispara o Discord.
 */
final class TiktokPostCallbackController extends Controller
{
    public function __construct(private readonly DiscordNotifier $discord) {}

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
        $videoId = $validated['video_id'];
        $title = (string) ($validated['title'] ?? '') ?: null;
        $error = (string) ($validated['error'] ?? '') ?: null;
        $finishedAtValue = (string) ($validated['finished_at'] ?? '');
        $finishedAt = $finishedAtValue !== ''
            ? Date::parse($finishedAtValue)
            : now();

        Log::info('[TiktokCallback] webhook recebido.', [
            'job_id' => $validated['job_id'],
            'video_id' => $videoId,
            'status' => $status,
            'title' => $title,
        ]);

        SocialPost::query()->updateOrCreate(['uuid' => $validated['job_id']], [
            'platform' => SocialPost::PLATFORM_TIKTOK,
            'youtube_id' => $videoId,
            'video_key' => sprintf('shorts/%s.mp4', $videoId),
            'title' => $title,
            'status' => $status,
            'error' => $error,
            'posted_at' => $status === 'completed' ? $finishedAt : null,
        ]);

        // Confirmação explícita do sucesso na fonte única (youtube_shorts),
        // casando pelo youtube_id. Só conta publicação real (completed).
        if ($status === 'completed') {
            YoutubeShort::query()
                ->where('youtube_id', $videoId)
                ->whereNull('posted_tiktok_at')
                ->update(['posted_tiktok_at' => $finishedAt]);

            $this->discord->success(
                '🎵 Short postado no TikTok',
                $title ?? $videoId,
            );
        } elseif ($status === 'failed') {
            $this->discord->error(
                '❌ Falha ao postar Short no TikTok',
                'Video: '.($title ?? $videoId).PHP_EOL.'Erro: '.($error ?? 'sem detalhes'),
            );
        }

        return response()->json(['ok' => true]);
    }
}
