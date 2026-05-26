<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing\Publishers;

use App\Models\ScheduledPost;
use App\Services\SocialPublishing\PublishException;
use App\Services\SocialPublishing\PublishResult;
use App\Support\Cast;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Publica no YouTube (Shorts) via YouTube Data API v3 com upload resumível.
 * Requer SocialAccount com access_token OAuth de escopo youtube.upload.
 */
final class YouTubePublisher extends AbstractPublisher
{
    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    public function key(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube Shorts';
    }

    public function publish(ScheduledPost $post): PublishResult
    {
        $tmp = null;

        try {
            $account = $this->requireAccount($post);
            $file = $this->requireVideoFile($post);
            $tmp = $this->downloadToTemp($file);

            $meta = Cast::arr($account->meta);
            $privacy = Cast::str($meta['privacy_status'] ?? '') ?: 'public';

            $title = mb_trim((string) ($post->title ?: 'Short'));
            // Reforça que é Short para o YouTube classificar corretamente.
            if (! str_contains(mb_strtolower($title), '#shorts')) {
                $title = mb_substr($title, 0, 90).' #Shorts';
            }

            $body = [
                'snippet' => [
                    'title' => mb_substr($title, 0, 100),
                    'description' => $this->caption($post),
                    'tags' => array_slice($post->hashtagList(), 0, 15),
                    'categoryId' => Cast::str($meta['category_id'] ?? '') ?: '22',
                ],
                'status' => [
                    'privacyStatus' => $privacy,
                    'selfDeclaredMadeForKids' => false,
                ],
            ];

            $size = (int) filesize($tmp);

            // 1) Inicia a sessão resumível e pega a URL de upload no header Location.
            $init = Http::withToken((string) $account->access_token)
                ->withHeaders([
                    'X-Upload-Content-Length' => (string) $size,
                    'X-Upload-Content-Type' => 'video/*',
                ])
                ->timeout(60)
                ->post(self::UPLOAD_URL, $body);

            if (! $init->successful()) {
                return PublishResult::fail('Falha ao iniciar upload no YouTube.', ['response' => $init->json() ?? $init->body()]);
            }

            $uploadUrl = $init->header('Location');
            if ($uploadUrl === '') {
                return PublishResult::fail('YouTube não retornou a URL de upload.', ['headers' => $init->headers()]);
            }

            // 2) Envia os bytes do vídeo.
            $upload = Http::withToken((string) $account->access_token)
                ->withBody((string) file_get_contents($tmp), 'video/mp4')
                ->timeout(600)
                ->put($uploadUrl);

            if (! $upload->successful()) {
                return PublishResult::fail('Falha ao enviar o vídeo ao YouTube.', ['response' => $upload->json() ?? $upload->body()]);
            }

            $videoId = Cast::str($upload->json('id'));
            $url = $videoId !== '' ? 'https://youtube.com/shorts/'.$videoId : null;

            return PublishResult::ok($videoId ?: null, $url, 'Vídeo publicado no YouTube.', ['response' => $upload->json()]);
        } catch (PublishException $e) {
            return PublishResult::fail($e->getMessage());
        } catch (Throwable $e) {
            return PublishResult::fail('Erro inesperado ao publicar no YouTube: '.$e->getMessage());
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
