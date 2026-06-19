<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Monitor local dos microserviços: faz ping no /health de cada um e lê os logs
 * dos containers pelo Docker Engine API. É uma ferramenta de DEV — a rota fica
 * atrás de auth.
 */
final class MicroserviceMonitor
{
    private const string DOCKER_SOCKET = '/var/run/docker.sock';

    /**
     * Catálogo dos serviços. `docker` é o nome do serviço no docker-compose.yml
     * (null = roda nativo no host, sem logs via Docker).
     *
     * @return list<array{key: string, label: string, url: string, docker: string|null}>
     */
    public function services(): array
    {
        $generateClipsDocker = $this->dockerServiceIfContainerExists('generate-clips');

        return [
            [
                'key' => 'download-shorts',
                'label' => 'download-shorts',
                'url' => (string) (config('services.download_youtube.base_url')) ?: 'http://127.0.0.1:8770',
                'docker' => 'download-shorts',
            ],
            [
                'key' => 'tiktok-uploader',
                'label' => 'tiktok-uploader',
                'url' => (string) (config('services.tiktok_post.base_url')) ?: 'http://127.0.0.1:8090',
                'docker' => 'tiktok-uploader',
            ],
            [
                'key' => 'generate-clips',
                'label' => $generateClipsDocker === null ? 'generate-clips (nativo)' : 'generate-clips (Docker)',
                'url' => (string) (config('services.video_processor.base_url')) ?: 'http://127.0.0.1:8765',
                'docker' => $generateClipsDocker,
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
     * Logs do container via Docker Engine API. Só para serviços dockerizados.
     */
    public function logs(string $dockerService, int $lines = 200): string
    {
        $valid = array_filter(
            $this->services(),
            static fn (array $s): bool => $s['docker'] === $dockerService,
        );

        if ($valid === []) {
            return 'Serviço inválido ou não dockerizado: '.$dockerService;
        }

        $lines = max(10, min(1000, $lines));

        try {
            if (! is_readable(self::DOCKER_SOCKET)) {
                return 'Docker socket não está disponível para o container Laravel. Reinicie o Sail após subir o compose atualizado.';
            }

            $containerId = $this->containerIdFor($dockerService);
            if ($containerId === null) {
                return 'Container não encontrado para o serviço: '.$dockerService;
            }

            $response = $this->docker()
                ->get('/containers/'.$containerId.'/logs', [
                    'stdout' => '1',
                    'stderr' => '1',
                    'timestamps' => '1',
                    'tail' => (string) $lines,
                ]);

            if (! $response->successful()) {
                return 'Falha ao ler os logs: Docker API HTTP '.$response->status();
            }

            $output = mb_trim($this->decodeDockerLogStream($response->body()));

            if ($output === '') {
                return '(sem logs)';
            }

            return $this->formatLogTimestamps($this->stripAnsi($output));
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

    private function containerIdFor(string $service): ?string
    {
        $response = $this->docker()
            ->get('/containers/json', [
                'all' => '1',
                'filters' => json_encode([
                    'label' => ['com.docker.compose.service='.$service],
                ], JSON_THROW_ON_ERROR),
            ]);

        if (! $response->successful()) {
            return null;
        }

        $containers = $response->json();
        if (! is_array($containers)) {
            return null;
        }

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }

            $id = $container['Id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    private function dockerServiceIfContainerExists(string $service): ?string
    {
        if (! is_readable(self::DOCKER_SOCKET)) {
            return null;
        }

        try {
            return $this->containerIdFor($service) === null ? null : $service;
        } catch (Throwable) {
            return null;
        }
    }

    private function docker(): PendingRequest
    {
        return Http::baseUrl('http://docker')
            ->timeout(10)
            ->withOptions([
                'curl' => [
                    CURLOPT_UNIX_SOCKET_PATH => self::DOCKER_SOCKET,
                ],
            ]);
    }

    private function decodeDockerLogStream(string $body): string
    {
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            return $body;
        }

        fwrite($stream, $body);
        $length = ftell($stream);
        if ($length === false) {
            fclose($stream);

            return $body;
        }

        rewind($stream);

        $decoded = '';
        while (ftell($stream) + 8 <= $length) {
            $header = fread($stream, 8);
            if ($header === false || mb_strlen($header) !== 8) {
                break;
            }

            $frameLength = unpack('Nlength', $header, 4)['length'] ?? 0;
            $position = ftell($stream);
            if (! is_int($frameLength) || $frameLength <= 0 || $position === false || $position + $frameLength > $length) {
                break;
            }

            $payload = fread($stream, $frameLength);
            if ($payload === false) {
                break;
            }

            $decoded .= $payload;
        }

        fclose($stream);

        if ($decoded === '') {
            return $body;
        }

        return $decoded;
    }

    private function formatLogTimestamps(string $value): string
    {
        return preg_replace_callback(
            '/^(?<timestamp>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)(?<space>\s+)/m',
            function (array $matches): string {
                try {
                    return CarbonImmutable::parse($matches['timestamp'])
                        ->timezone((string) config('app.timezone', 'America/Sao_Paulo'))
                        ->format('d/m/Y H:i:s').$matches['space'];
                } catch (Throwable) {
                    return $matches[0];
                }
            },
            $value,
        ) ?? $value;
    }

    private function stripAnsi(string $value): string
    {
        return preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $value) ?? $value;
    }
}
