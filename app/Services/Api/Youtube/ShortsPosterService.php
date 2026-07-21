<?php

declare(strict_types=1);

namespace App\Services\Api\Youtube;

use App\Models\SocialAccount;
use App\Models\YoutubeShort;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ShortsPosterService
{
    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    public function __construct(private YoutubeTokenRefresherService $tokenRefresher) {}

    public function post(YoutubeShort $short, ?SocialAccount $account = null): string
    {
        $account ??= SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        throw_if($account === null, RuntimeException::class, 'Nenhuma conta do YouTube conectada. Conecte em /contas ou rode: php artisan youtube:link');

        $videoPath = $short->postableVideoPath();
        if ($videoPath === '') {
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
                    'categoryId' => (string) (config('services.youtube_shorts.posting.category_id')) ?: '22',
                ],
                'status' => [
                    'privacyStatus' => $this->privacyStatus(),
                    'selfDeclaredMadeForKids' => false,
                ],
            ];

            $size = (int) filesize($localFile);

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

                'posted_at' => $postedAt,
                'posted_youtube_at' => $postedAt,
            ])->save();

            return $videoId;
        } finally {
            @unlink($localFile);
        }
    }

    private function pullToTemp(string $path): string
    {
        $disk = Storage::disk();

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
        return match ((string) (config('services.youtube_shorts.posting.privacy_status'))) {
            'unlisted' => 'unlisted',
            'private' => 'private',
            default => 'public',
        };
    }
}
