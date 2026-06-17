<?php

declare(strict_types=1);

namespace App\Services\Youtube;

use App\Models\SocialAccount;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Posta (faz upload de) um Short baixado no canal do YouTube conectado,
 * via upload resumível da YouTube Data API v3.
 *
 * Usa a mesma SocialAccount (platform=youtube) do fluxo de publicação de
 * cortes — conectada em /social-accounts ou via `php artisan youtube:link` —
 * renovando o access token pelo YoutubeTokenRefresher quando necessário.
 *
 * Baixa o vídeo do MinIO para um arquivo temporário, sobe para o YouTube e
 * marca o Short como postado (guardando o ID do vídeo gerado).
 */
final readonly class ShortsPoster
{
    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    public function __construct(private YoutubeTokenRefresher $tokenRefresher) {}

    /**
     * Posta o Short e retorna o ID do vídeo criado no YouTube.
     */
    public function post(YoutubeShort $short, ?SocialAccount $account = null): string
    {
        $account ??= SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        throw_if($account === null, RuntimeException::class, 'Nenhuma conta do YouTube conectada. Conecte em /social-accounts ou rode: php artisan youtube:link');

        $videoPath = $short->video_path;
        if ($videoPath === null || $videoPath === '') {
            throw new RuntimeException(sprintf('Short %s não tem video_path.', $short->youtube_id));
        }

        throw_if($account->tokenExpired() && ! $this->tokenRefresher->ensureFresh($account), RuntimeException::class, 'O token da conta do YouTube expirou e não foi possível renovar. Reconecte a conta.');

        $localFile = $this->pullToTemp($videoPath);

        try {
            $body = [
                'snippet' => [
                    'title' => $this->buildTitle($short),
                    'description' => $this->buildDescription($short),
                    'tags' => $this->buildTags($short),
                    'categoryId' => (string) (config('youtube_shorts.posting.category_id')) ?: '22',
                ],
                'status' => [
                    'privacyStatus' => $this->privacyStatus(),
                    'selfDeclaredMadeForKids' => false,
                ],
            ];

            $size = (int) filesize($localFile);

            // 1) Inicia a sessão resumível e pega a URL de upload no header Location.
            $init = Http::withToken((string) ($account->access_token))
                ->withHeaders([
                    'X-Upload-Content-Length' => (string) $size,
                    'X-Upload-Content-Type' => 'video/*',
                ])
                ->timeout(60)
                ->post(self::UPLOAD_URL, $body);

            if (! $init->successful()) {
                throw new RuntimeException('Falha ao iniciar upload no YouTube: '.Str::limit($init->body(), 300));
            }

            $uploadUrl = $init->header('Location');
            throw_if($uploadUrl === '', RuntimeException::class, 'O YouTube não retornou a URL de upload.');

            // 2) Envia os bytes do vídeo.
            $upload = Http::withToken((string) ($account->access_token))
                ->withBody((string) file_get_contents($localFile), 'video/mp4')
                ->timeout(900)
                ->put($uploadUrl);

            if (! $upload->successful()) {
                throw new RuntimeException('Falha ao enviar o vídeo ao YouTube: '.Str::limit($upload->body(), 300));
            }

            $videoId = (string) ($upload->json('id'));
            throw_if($videoId === '', RuntimeException::class, 'O YouTube não retornou o ID do vídeo após o upload.');

            $postedAt = now();

            $short->forceFill([
                'youtube_video_id' => $videoId,
                // posted_at fica como marcador legado; posted_youtube_at é a
                // confirmação explícita usada pela UI.
                'posted_at' => $postedAt,
                'posted_youtube_at' => $postedAt,
            ])->save();

            return $videoId;
        } finally {
            @unlink($localFile);
        }
    }

    /**
     * Baixa o vídeo do MinIO para um arquivo temporário local.
     */
    private function pullToTemp(string $path): string
    {
        $disk = Storage::disk((string) (config('youtube_shorts.disk', 'minio')));

        throw_unless($disk->exists($path), RuntimeException::class, 'Vídeo não encontrado no storage: '.$path);

        $tmp = mb_rtrim(sys_get_temp_dir(), '/').'/yt-post-'.Str::random(8).'.mp4';

        $in = $disk->readStream($path);
        throw_if($in === null, RuntimeException::class, 'Não foi possível ler o vídeo do storage: '.$path);

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Não foi possível criar o arquivo temporário.');
        }

        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);

        return $tmp;
    }

    private function buildTitle(YoutubeShort $short): string
    {
        $title = $short->title;
        $base = $title !== null && $title !== '' ? $title : $short->youtube_id;

        return Str::limit($base, 95, '');
    }

    private function buildDescription(YoutubeShort $short): string
    {
        $parts = [];

        $title = $short->title;
        if ($title !== null && $title !== '') {
            $parts[] = $title;
        }

        $hashtags = $short->hashtags ?? [];
        if ($hashtags !== []) {
            $parts[] = implode(' ', $hashtags);
        }

        return mb_trim(implode("\n\n", $parts));
    }

    /**
     * Tags do vídeo a partir das hashtags (sem o '#').
     *
     * @return array<int, string>
     */
    private function buildTags(YoutubeShort $short): array
    {
        $hashtags = $short->hashtags ?? [];

        return array_values(array_filter(array_map(
            static fn (string $tag): string => mb_ltrim($tag, '#'),
            $hashtags,
        )));
    }

    /**
     * Status de privacidade validado contra os valores aceitos pela API.
     *
     * @return 'private'|'public'|'unlisted'
     */
    private function privacyStatus(): string
    {
        return match ((string) (config('youtube_shorts.posting.privacy_status'))) {
            'unlisted' => 'unlisted',
            'private' => 'private',
            default => 'public',
        };
    }
}
