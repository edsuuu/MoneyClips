<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\FetchTemplateOutputJob;
use App\Models\ProcessingJob;
use App\Services\Api\Discord\DiscordNotifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AutoCaptionWebhookController extends Controller
{
    public function __invoke(Request $request, DiscordNotifierService $discord): JsonResponse
    {
        /** @var array{uuid: string, status: string, error?: string|null} $data */
        $data = $request->validate([
            'uuid' => ['required', 'string'],
            'status' => ['required', 'in:done,failed'],
            'error' => ['nullable', 'string'],
        ]);

        $job = ProcessingJob::query()
            ->where('remote_id', $data['uuid'])
            ->where('type', ProcessingJob::TYPE_TEMPLATE)
            ->latest('id')
            ->first();

        if (! $job instanceof ProcessingJob) {
            return response()->json(['status' => 'unknown-job'], 404);
        }

        // Idempotência: retry do webhook depois do desfecho não refaz nada.
        if (! in_array($job->status, ProcessingJob::PENDING_STATUSES, true)) {
            return response()->json(['status' => 'already-finished']);
        }

        if ($data['status'] === 'failed') {
            $job->fill([
                'status' => 'failed',
                'error' => $data['error'] ?? 'AutoCaption reportou falha sem detalhe.',
                'finished_at' => now(),
            ])->save();

            $discord->error(
                '❌ AutoCaption falhou no render',
                sprintf('Job #%d (remoto %s)%s%s', $job->id, $data['uuid'], PHP_EOL, $job->error ?? ''),
            );

            return response()->json(['status' => 'failed-recorded']);
        }

        // Claim atômico do fetch: retry do webhook enquanto o download ainda
        // roda não pode despachar um segundo FetchTemplateOutputJob.
        $claimed = ProcessingJob::query()
            ->whereKey($job->id)
            ->whereIn('status', ['queued', 'processing'])
            ->update(['status' => ProcessingJob::STATUS_FETCHING]);

        if ($claimed !== 1) {
            return response()->json(['status' => 'fetch-already-queued']);
        }

        dispatch(new FetchTemplateOutputJob($job->id));

        return response()->json(['status' => 'fetch-queued']);
    }
}
