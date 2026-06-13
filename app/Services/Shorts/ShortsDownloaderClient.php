<?php

declare(strict_types=1);

namespace App\Services\Shorts;

use App\Support\Cast;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente HTTP do microserviço Python download-shorts (porta 8770).
 *
 * O fluxo é fire-and-forget: o Laravel envia a URL do canal e o microserviço
 * baixa os Shorts, sobe para o storage (Contabo) e mantém os itens no banco
 * DELE com dispatch_status=pending. Nada é enviado de volta ao terminar o
 * lote (dispatch_on_complete=false, sem webhook) — o acompanhamento é feito
 * consultando o status do job.
 */
final class ShortsDownloaderClient
{
    /** Cria um job de download para o canal e retorna o job_id do microserviço. */
    public function createJob(string $channelUrl): string
    {
        /** @var array<string, mixed> $res */
        $res = $this->client()
            ->post('/shorts/download', [
                'channel_url' => $channelUrl,
                'dispatch_on_complete' => false,
            ])
            ->throw()
            ->json();

        $jobId = Cast::str($res['job_id'] ?? '');

        throw_if($jobId === '', RuntimeException::class, 'Microserviço download-shorts não retornou job_id.');

        return $jobId;
    }

    /**
     * Consulta o status de um job (contadores de pending/completed/failed etc).
     *
     * @return array<string, mixed>
     */
    public function jobStatus(string $jobId): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->client()
            ->get(sprintf('/shorts/download/%s', $jobId))
            ->throw()
            ->json();

        return $res;
    }

    /**
     * Lista itens do estoque do microserviço (deduplicados por youtube_id),
     * cada um com youtube_id, title, hashtags e storage_path.
     *
     * @return list<array<string, mixed>>
     */
    public function listItems(string $status = 'completed', int $limit = 500): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->client()
            ->get('/shorts/items', ['status' => $status, 'limit' => $limit])
            ->throw()
            ->json();

        /** @var list<array<string, mixed>> $items */
        $items = array_values(array_filter(Cast::arr($res['items'] ?? []), is_array(...)));

        return $items;
    }

    /** Verifica se o microserviço está no ar. */
    public function isHealthy(): bool
    {
        try {
            return $this->client()
                ->timeout(5)
                ->get('/health')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function client(): PendingRequest
    {
        $baseUrl = Cast::str(config('shorts-downloader.base_url')) ?: 'http://127.0.0.1:8770';
        $timeout = Cast::int(config('shorts-downloader.timeout')) ?: 30;

        return Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }
}
