<?php

declare(strict_types=1);

namespace App\Services\Youtube;

use App\Models\File;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Publica no YouTube (Shorts) via YouTube Data API v3 com upload resumível.
 * Requer SocialAccount com access_token OAuth de escopo youtube.upload.
 */
final class YoutubePublisher
{
    public const string PLATFORM = 'youtube';

    public const string LABEL = 'YouTube Shorts';

    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    public function __construct(private readonly YoutubeTokenRefresher $tokenRefresher) {}

    public function publish(ScheduledPost $post): PublishResult
    {
        $tmp = null;

        try {
            $account = $this->requireAccount($post);
            $file = $this->requireVideoFile($post);
            $tmp = $this->downloadToTemp($file);

            $meta = (array) ($account->meta);
            $privacy = (string) ($meta['privacy_status'] ?? '') ?: 'public';

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
                    'categoryId' => (string) ($meta['category_id'] ?? '') ?: '22',
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

            $videoId = (string) ($upload->json('id'));
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

    private function requireAccount(ScheduledPost $post): SocialAccount
    {
        $account = $post->account;

        throw_unless($account instanceof SocialAccount, PublishException::class, 'Nenhuma conta conectada para esta plataforma. Conecte uma conta em "Contas vinculadas".');

        throw_unless($account->is_active, PublishException::class, 'A conta conectada está inativa.');

        throw_if(empty($account->access_token), PublishException::class, 'A conta conectada não tem access token configurado.');

        // Tenta renovar via refresh_token antes de desistir.
        throw_if($account->tokenExpired() && ! $this->tokenRefresher->ensureFresh($account), PublishException::class, 'O token da conta expirou e não foi possível renovar. Reconecte a conta.');

        return $account;
    }

    private function requireVideoFile(ScheduledPost $post): File
    {
        $cut = $post->cut;

        if ($cut !== null) {
            $file = $cut->files()
                ->where(fn ($q) => $q->where('mime_type', 'like', 'video/%')->orWhere('type', $cut->type))
                ->latest()
                ->first();

            if ($file instanceof File) {
                return $file;
            }
        }

        $video = $post->video;
        $fallback = $video?->fileOfType('legendado') ?? $video?->fileOfType('original');

        if ($fallback instanceof File) {
            return $fallback;
        }

        throw new PublishException('Arquivo de vídeo do corte não encontrado (o corte foi renderizado?).');
    }

    private function downloadToTemp(File $file): string
    {
        $disk = Storage::disk($file->disk ?: 'minio');
        $contents = $disk->get($file->path);

        throw_if($contents === null, PublishException::class, 'Falha ao ler o arquivo de vídeo do storage.');

        $ext = $file->extension ?: 'mp4';
        $tmp = tempnam(sys_get_temp_dir(), 'pub_').'.'.$ext;
        file_put_contents($tmp, $contents);

        return $tmp;
    }

    private function caption(ScheduledPost $post): string
    {
        $description = mb_trim((string) $post->description);
        $tags = implode(' ', array_map(
            static fn (string $t): string => '#'.mb_ltrim($t, '#'),
            $post->hashtagList(),
        ));

        return mb_trim($description.($tags !== '' ? "\n\n".$tags : ''));
    }
}
