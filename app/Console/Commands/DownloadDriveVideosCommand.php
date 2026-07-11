<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DriveFolder;
use App\Models\DriveVideo;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/**
 * Percorre pastas públicas do Google Drive (config/drive.php ou --folder),
 * baixa os vídeos e sobe pro MinIO (bucket videos) sob
 * `drive/<pasta>/<arquivo>`. Cada vídeo vira uma linha em drive_videos.
 *
 * Toda a lógica (Drive API v3 com API key, listagem recursiva, download com
 * retry, upload) vive aqui no comando — sem service à parte. Autenticação por
 * API key funciona porque as pastas são públicas ("qualquer pessoa com o link").
 *
 * Idempotente: vídeos já baixados (drive_file_id) são pulados; pastas inteiras
 * já concluídas (drive_folders.downloaded) também.
 */
final class DownloadDriveVideosCommand extends Command
{
    private const string DRIVE_API = 'https://www.googleapis.com/drive/v3';

    /** Profundidade máxima de recursão em subpastas (trava anti-loop). */
    private const int MAX_DEPTH = 10;

    /** @var string */
    protected $signature = 'drive:download-videos
        {--folder=* : URLs de pastas do Drive (default: config drive.folders)}
        {--force : reprocessa pastas já marcadas como baixadas (drive_folders.downloaded)}
        {--limit=0 : baixa no máximo N vídeos no total (0 = sem limite)}';

    /** @var string */
    protected $description = 'Baixa os vídeos de pastas públicas do Google Drive para o MinIO.';

    public function handle(): int
    {
        $folders = $this->folders();

        if ($folders === []) {
            $this->error('Nenhuma pasta configurada (config/drive.php → folders) nem passada via --folder.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $downloaded = 0;
        $skipped = 0;
        $failed = 0;
        $consecutiveFailures = 0;
        $maxConsecutive = max(1, (int) config('drive.abort_after_consecutive_failures', 3));
        $aborted = false;

        foreach ($folders as $folderUrl) {
            if ($aborted) {
                break;
            }

            try {
                $folderId = $this->folderIdFromUrl($folderUrl);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $failed++;

                continue;
            }

            $folder = DriveFolder::query()->firstOrNew(['drive_folder_id' => $folderId]);

            // Pula a pasta inteira se já foi baixada por completo num run anterior.
            if ($folder->downloaded && ! $force) {
                $this->line(sprintf('📁 <info>%s</info> — <comment>já baixada, pulando</comment> (use --force pra refazer)', $folder->name ?? $folderId));

                continue;
            }

            $folderName = $this->folderName($folderId) ?? $folderId;
            $this->line(sprintf('📁 <info>%s</info> (%s)', $folderName, $folderId));

            $folderFailed = 0;

            // Feedback de scan: a listagem desce em subpastas e é entregue em
            // stream (generator), então já baixamos o 1º vídeo sem esperar
            // escanear a árvore toda. $onFolder avisa qual subpasta está sendo
            // varrida pra nunca parecer travado.
            $onFolder = function (string $path): void {
                $this->line('  🔍 <comment>escaneando</comment> '.$path);
            };

            $found = 0;

            try {
                foreach ($this->listVideos($folderId, $onFolder) as $video) {
                    $found++;
                    $label = ($video['path'] === '' ? '' : $video['path'].'/').$video['name'];

                    if (DriveVideo::query()->where('drive_file_id', $video['id'])->exists()) {
                        $this->line('  ⏭  '.$label.' <comment>(já existe)</comment>');
                        $skipped++;

                        continue;
                    }

                    try {
                        $this->downloadOne($folderUrl, $folderId, $folderName, $video, $label);
                        $downloaded++;
                        $consecutiveFailures = 0;
                    } catch (Throwable $e) {
                        $this->error(sprintf('  ✗ %s: %s', $label, $this->shortError($e->getMessage())));
                        $failed++;
                        $folderFailed++;
                        $consecutiveFailures++;
                    }

                    // Disjuntor: se falhar N vezes seguidas, o IP provavelmente
                    // está bloqueado pelo Google (403 "Sorry", tráfego incomum).
                    // Aborta em vez de moer cada arquivo com 65s de retry.
                    if ($consecutiveFailures >= $maxConsecutive) {
                        $this->newLine();
                        $this->error(sprintf(
                            '⛔ %d downloads falharam em sequência — IP provavelmente bloqueado pelo Google (tráfego incomum). Aguarde e rode de novo (é idempotente: retoma de onde parou).',
                            $consecutiveFailures,
                        ));
                        $aborted = true;

                        break;
                    }

                    // Limite total de downloads (--limit): para tudo ao atingir.
                    if ($limit > 0 && $downloaded >= $limit) {
                        $this->newLine();
                        $this->info(sprintf('🎯 Limite de %d download(s) atingido — parando.', $limit));
                        $aborted = true;

                        break;
                    }

                    // Espaça os downloads pra não disparar o bloqueio "tráfego
                    // incomum" (403 Sorry) do Google em rajadas.
                    $this->throttle();
                }
            } catch (Throwable $e) {
                $this->error('  Falha ao listar a pasta: '.$e->getMessage());
                $failed++;

                continue;
            }

            // Persiste o estado da pasta. Só marca `downloaded=true` quando a
            // pasta terminou sem nenhuma falha e sem o disjuntor abortar — assim
            // um run futuro pula a pasta; se ficou parcial, continua false e o
            // dedupe por vídeo retoma de onde parou.
            $complete = $folderFailed === 0 && ! $aborted;
            $folder->fill([
                'url' => $folderUrl,
                'name' => $folderName,
                'videos_count' => $found,
                'downloaded' => $complete,
                'downloaded_at' => $complete ? Date::now() : $folder->downloaded_at,
            ])->save();

            $this->line(sprintf(
                '  <info>%d vídeos</info> nesta pasta%s.',
                $found,
                $complete ? ' — <info>pasta concluída ✓</info>' : ' (parcial)',
            ));
        }

        $this->newLine();
        $this->info(sprintf('Concluído: %d baixados, %d já existentes, %d falhas.', $downloaded, $skipped, $failed));

        return $failed > 0 && $downloaded === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{id: string, name: string, mimeType: string, size: int|null, path: string}  $video
     */
    private function downloadOne(
        string $folderUrl,
        string $folderId,
        string $folderName,
        array $video,
        string $label,
    ): void {
        $tmpPath = tempnam(sys_get_temp_dir(), 'drive_');

        throw_if($tmpPath === false, 'Não foi possível criar arquivo temporário.');

        try {
            $bar = $this->createDownloadBar($label, $video['size'] ?? 0);
            $totalKnown = ($video['size'] ?? 0) > 0;

            $this->download($video['id'], $tmpPath, function (int $downloaded, int $downloadTotal) use ($bar, &$totalKnown): void {
                if (! $totalKnown && $downloadTotal > 0) {
                    $bar->setMaxSteps($downloadTotal);
                    $totalKnown = true;
                }

                $bar->setMessage($this->formatBytes($downloaded).' / '.$this->formatBytes(max($downloadTotal, $downloaded)));
                $bar->setProgress($downloaded);
            });

            $bar->finish();
            $this->newLine();

            $bytes = File::size($tmpPath);

            // Guard: nunca gravar arquivo vazio (bloqueio/erro do Drive que
            // escapou). Falha aqui pra reprocessar no próximo run.
            throw_if($bytes === 0, sprintf('Download vazio (0 bytes) para %s.', $video['name']));

            $objectPath = $this->objectPath($folderName, $video['path'], $video['id'], $video['name']);

            $this->output->write('  ⬆ enviando ao MinIO...');

            $stream = fopen($tmpPath, 'rb');
            throw_if($stream === false, sprintf('Não foi possível abrir %s.', $tmpPath));

            Storage::disk('s3')->writeStream($objectPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $nestedFolder = $video['path'] === '' ? $folderName : $folderName.'/'.$video['path'];

            DriveVideo::query()->create([
                'drive_file_id' => $video['id'],
                'drive_folder_id' => $folderId,
                'drive_folder_url' => $folderUrl,
                'folder_name' => $nestedFolder,
                'title' => $video['name'],
                'video_path' => $objectPath,
                'mime_type' => $video['mimeType'],
                'size_bytes' => $bytes,
                'downloaded_at' => Date::now(),
            ]);

            // Reescreve a linha do "enviando..." com o ✓ final.
            $this->output->write("\r\033[K");
            $this->line(sprintf('  ✓ <info>%s</info> (%s) → %s', $label, $this->formatBytes($bytes), $objectPath));
        } finally {
            File::delete($tmpPath);
        }
    }

    private function createDownloadBar(string $label, int $totalBytes): ProgressBar
    {
        $bar = $this->output->createProgressBar($totalBytes);
        $bar->setBarWidth(30);
        // %message% carrega "X MB / Y MB" (setado no callback) — não é
        // re-parseado, então nomes de arquivo com % ficam seguros.
        $bar->setFormat(sprintf('  ⬇ %s [%%bar%%] %%percent:3s%%%%  %%message%%', $this->escapeFormat($label)));
        $bar->setMessage('');
        $bar->start();

        return $bar;
    }

    /** Escapa % pra não colidir com os placeholders do formato do ProgressBar. */
    private function escapeFormat(string $text): string
    {
        return str_replace('%', '%%', $text);
    }

    /** Formata bytes em B/KB/MB/GB (sem depender da ext-intl do Number::fileSize). */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return sprintf('%.1f %s', $bytes / 1024 ** $power, $units[$power]);
    }

    /** Encurta mensagens de erro longas (ex.: a página HTML "Sorry" do Google). */
    private function shortError(string $message): string
    {
        $message = mb_trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > 140 ? mb_substr($message, 0, 140).'…' : $message;
    }

    private function throttle(): void
    {
        $delayMs = max(0, (int) config('drive.download_delay_ms', 800));

        if ($delayMs > 0) {
            Sleep::usleep($delayMs * 1000);
        }
    }

    /**
     * Caminho no MinIO: `drive/<pasta-topo>/<canal>/<id-curto>-<arquivo>`, onde
     * `<canal>` é a subpasta IMEDIATA de onde o vídeo veio (a origem). As demais
     * subpastas intermediárias são achatadas pra não gerar caminhos gigantes.
     * Vídeos direto na raiz da pasta-topo ficam sem o segmento `<canal>`.
     *
     * O prefixo de 8 chars do id do arquivo garante unicidade quando o mesmo
     * nome aparece em subpastas diferentes, sem precisar checar colisão.
     */
    private function objectPath(string $folderName, string $relativePath, string $fileId, string $fileName): string
    {
        $prefix = mb_trim((string) config('drive.path_prefix', 'drive'), '/');
        $segments = [$prefix, Str::slug($folderName) ?: 'sem-nome'];

        // Subpasta imediata = o "canal" de origem (última parte do caminho).
        $parts = array_values(array_filter(explode('/', $relativePath), static fn (string $p): bool => $p !== ''));
        if ($parts !== []) {
            $segments[] = Str::slug($parts[count($parts) - 1]) ?: 'sub';
        }

        $shortId = mb_substr(Str::slug($fileId), 0, 8) ?: 'file';
        $segments[] = $shortId.'-'.$this->sanitizeFileName($fileName);

        return implode('/', $segments);
    }

    /**
     * Mantém a extensão e um nome legível, sem caracteres inseguros pra key S3.
     */
    private function sanitizeFileName(string $fileName): string
    {
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $slug = Str::slug($base) ?: 'video';

        return $extension === '' ? $slug : sprintf('%s.%s', $slug, $extension);
    }

    /**
     * @return list<string>
     */
    private function folders(): array
    {
        /** @var list<string> $fromOption */
        $fromOption = (array) $this->option('folder');
        $fromOption = array_values(array_filter($fromOption, static fn (string $url): bool => mb_trim($url) !== ''));

        if ($fromOption !== []) {
            return $fromOption;
        }

        /** @var array<int, mixed> $configured */
        $configured = (array) config('drive.folders', []);

        return array_values(array_filter($configured, static fn (mixed $url): bool => is_string($url) && mb_trim($url) !== ''));
    }

    // ---------------------------------------------------------------------
    // Google Drive API v3 (pastas públicas, só API key — HTTP puro).
    // ---------------------------------------------------------------------

    /** Extrai o ID da pasta de uma URL do Drive (.../folders/<ID>) ou do ID cru. */
    private function folderIdFromUrl(string $url): string
    {
        if (preg_match('#/folders/([A-Za-z0-9_-]+)#', $url, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#^[A-Za-z0-9_-]{10,}$#', mb_trim($url)) === 1) {
            return mb_trim($url);
        }

        throw new RuntimeException('URL de pasta do Drive inválida: '.$url);
    }

    private function folderName(string $folderId): ?string
    {
        /** @var array<string, mixed> $response */
        $response = $this->driveClient()
            ->get('/files/'.$folderId, ['fields' => 'name', 'supportsAllDrives' => 'true'])
            ->throw()
            ->json();

        $name = $response['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Entrega os vídeos (mimeType video/*) da pasta UM A UM (generator),
     * descendo recursivamente em subpastas. Cada item traz `path`: o caminho
     * relativo das subpastas ('' na raiz), pra espelhar a estrutura no MinIO.
     *
     * @param  (callable(string): void)|null  $onFolder  chamado ao entrar em cada subpasta
     * @return Generator<int, array{id: string, name: string, mimeType: string, size: int|null, path: string}>
     */
    private function listVideos(string $folderId, ?callable $onFolder = null): Generator
    {
        yield from $this->collectVideos($folderId, '', 0, $onFolder);
    }

    /**
     * @param  (callable(string): void)|null  $onFolder
     * @return Generator<int, array{id: string, name: string, mimeType: string, size: int|null, path: string}>
     */
    private function collectVideos(string $folderId, string $relativePath, int $depth, ?callable $onFolder): Generator
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if ($onFolder !== null && $relativePath !== '') {
            $onFolder($relativePath);
        }

        $pageToken = null;

        do {
            /** @var array<string, mixed> $response */
            $response = $this->driveClient()
                ->get('/files', array_filter([
                    'q' => sprintf("'%s' in parents and trashed = false", $folderId),
                    'fields' => 'nextPageToken, files(id, name, mimeType, size)',
                    'pageSize' => 1000,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                    'pageToken' => $pageToken,
                ], static fn (mixed $value): bool => $value !== null))
                ->throw()
                ->json();

            /** @var list<array<string, mixed>> $files */
            $files = (array) ($response['files'] ?? []);

            foreach ($files as $file) {
                $id = $file['id'] ?? null;
                $name = $file['name'] ?? null;
                if (! is_string($id)) {
                    continue;
                }

                if (! is_string($name)) {
                    continue;
                }

                $mimeType = is_string($file['mimeType'] ?? null) ? $file['mimeType'] : '';

                if ($mimeType === 'application/vnd.google-apps.folder') {
                    $childPath = $relativePath === '' ? $name : $relativePath.'/'.$name;
                    yield from $this->collectVideos($id, $childPath, $depth + 1, $onFolder);

                    continue;
                }

                if (! str_contains($mimeType, 'video/')) {
                    continue;
                }

                $size = $file['size'] ?? null;

                yield [
                    'id' => $id,
                    'name' => $name,
                    'mimeType' => $mimeType,
                    'size' => is_numeric($size) ? (int) $size : null,
                    'path' => $relativePath,
                ];
            }

            $pageToken = is_string($response['nextPageToken'] ?? null) ? $response['nextPageToken'] : null;
        } while ($pageToken !== null);
    }

    /**
     * Baixa o arquivo pro $destination em streaming (sink). O Google bloqueia
     * rajadas com 403 "Sorry" (tráfego incomum), daí o retry com backoff.
     *
     * @param  (callable(int, int): void)|null  $onProgress  recebe (bytesBaixados, bytesTotais)
     */
    private function download(string $fileId, string $destination, ?callable $onProgress = null): void
    {
        $retries = max(1, (int) config('drive.download_retries', 4));

        $request = $this->driveClient()->retry(
            $retries,
            static fn (int $attempt): int => (int) (5000 * 3 ** ($attempt - 1)),
            static fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && in_array($e->response->status(), [403, 429, 500, 502, 503], true)),
            throw: true,
        );

        if ($onProgress !== null) {
            $request = $request->withOptions([
                'progress' => static function (mixed $downloadTotal, mixed $downloadedBytes) use ($onProgress): void {
                    $onProgress((int) $downloadedBytes, (int) $downloadTotal);
                },
            ]);
        }

        $request
            ->sink($destination)
            ->get('/files/'.$fileId, ['alt' => 'media', 'supportsAllDrives' => 'true'])
            ->throw();
    }

    private function driveClient(): PendingRequest
    {
        $apiKey = (string) config('drive.api_key');

        throw_if($apiKey === '', RuntimeException::class, 'GOOGLE_DRIVE_API_KEY não configurada (config/drive.php).');

        return Http::baseUrl(self::DRIVE_API)
            ->timeout((int) config('drive.timeout', 300))
            ->withQueryParameters(['key' => $apiKey])
            ->acceptJson();
    }
}
