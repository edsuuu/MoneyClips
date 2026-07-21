<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\TemplateRenderWebhookRequest;
use App\Jobs\FetchTemplateOutputJob;
use App\Models\ProcessingJob;
use App\Services\Api\Discord\DiscordNotifierService;
use Illuminate\Http\JsonResponse;

final class AutoCaptionWebhookController extends Controller
{
    public function __invoke(TemplateRenderWebhookRequest $request, DiscordNotifierService $discord): JsonResponse
    {
        $job = ProcessingJob::query()
            ->where('remote_id', $request->uuid())
            ->where('type', ProcessingJob::TYPE_TEMPLATE)
            ->latest('id')
            ->first();

        if (! $job instanceof ProcessingJob) {
            return response()->json(['status' => 'unknown-job'], 404);
        }

        if (! in_array($job->status, ProcessingJob::PENDING_STATUSES, true)) {
            return response()->json(['status' => 'already-finished']);
        }

        if ($request->failed()) {
            $job->fill([
                'status' => 'failed',
                'error' => $request->error() ?? 'AutoCaption reportou falha sem detalhe.',
                'finished_at' => now(),
            ])->save();

            $discord->error(
                '❌ AutoCaption falhou no render',
                sprintf('Job #%d (remoto %s)%s%s', $job->id, $request->uuid(), PHP_EOL, $job->error ?? ''),
            );

            return response()->json(['status' => 'failed-recorded']);
        }

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
