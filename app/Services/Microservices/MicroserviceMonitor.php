<?php

declare(strict_types=1);

namespace App\Services\Microservices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Monitor local dos microserviços: faz ping no /health de cada um e lê os logs
 * dos containers via `docker compose logs`. É uma ferramenta de DEV (roda Docker
 * a partir do app) — a rota fica atrás de auth.
 */
final class MicroserviceMonitor
{
    /**
     * Catálogo dos serviços. `docker` é o nome do serviço no docker-compose.yml
     * (null = roda nativo no host, sem logs via Docker).
     *
     * @return list<array{key: string, label: string, url: string, docker: string|null}>
     */
    public function services(): array
    {
        return [
            [
                'key' => 'download-shorts',
                'label' => 'download-shorts',
                'url' => (string) (config('microservices.download_youtube.base_url')) ?: 'http://127.0.0.1:8770',
                'docker' => 'download-shorts',
            ],
            [
                'key' => 'tiktok-uploader',
                'label' => 'tiktok-uploader',
                'url' => (string) (config('microservices.tiktok_post.base_url')) ?: 'http://127.0.0.1:8090',
                'docker' => 'tiktok-uploader',
            ],
            [
                'key' => 'generate-clips',
                'label' => 'generate-clips (nativo)',
                'url' => (string) (config('video-processor.base_url')) ?: 'http://127.0.0.1:8765',
                'docker' => null,
            ],
        ];
    }

    /**
     * Pinga o /health de cada serviço.
     *
     * @return list<array{key: string, label: string, url: string, docker: string|null, up: bool, detail: string}>
     */
    public function statuses(): array
    {
        return array_map(function (array $service): array {
            [$up, $detail] = $this->health($service['url']);

            return [...$service, 'up' => $up, 'detail' => $detail];
        }, $this->services());
    }

    /**
     * Logs do container via `docker compose logs`. Só para serviços dockerizados.
     */
    public function logs(string $dockerService, int $lines = 200): string
    {
        $valid = array_filter(
            $this->services(),
            static fn (array $s): bool => $s['docker'] === $dockerService,
        );

        if ($valid === []) {
            return "Serviço inválido ou não dockerizado: {$dockerService}";
        }

        $lines = max(10, min(1000, $lines));

        try {
            $result = Process::path(base_path())
                ->timeout(15)
                ->run(['docker', 'compose', 'logs', '--no-color', '--tail='.$lines, $dockerService]);

            $output = mb_trim($result->output()."\n".$result->errorOutput());

            return $output !== '' ? $output : '(sem logs)';
        } catch (Throwable $throwable) {
            return 'Falha ao ler os logs: '.$throwable->getMessage();
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function health(string $baseUrl): array
    {
        try {
            $response = Http::timeout(4)->get(mb_rtrim($baseUrl, '/').'/health');
            if (! $response->successful()) {
                return [false, 'HTTP '.$response->status()];
            }

            return [true, mb_trim($response->body())];
        } catch (Throwable $throwable) {
            return [false, $throwable->getMessage()];
        }
    }
}
