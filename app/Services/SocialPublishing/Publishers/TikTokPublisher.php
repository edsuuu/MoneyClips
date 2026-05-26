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
 * Publica no TikTok via Content Posting API v2 (source FILE_UPLOAD, chunk único).
 * Requer SocialAccount com access_token de escopo video.publish/video.upload.
 *
 * Observação: apps não auditados pelo TikTok só conseguem privacy_level
 * SELF_ONLY (rascunho privado). Configure meta.privacy_level quando aprovado.
 */
final class TikTokPublisher extends AbstractPublisher
{
    private const string INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';

    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function publish(ScheduledPost $post): PublishResult
    {
        $tmp = null;

        try {
            $account = $this->requireAccount($post);
            $file = $this->requireVideoFile($post);
            $tmp = $this->downloadToTemp($file);
            $size = (int) filesize($tmp);

            $meta = Cast::arr($account->meta);
            $privacy = Cast::str($meta['privacy_level'] ?? '') ?: 'SELF_ONLY';

            $title = mb_trim((string) ($post->title ?: ''));
            $caption = mb_trim($title."\n".$this->hashtagsString($post));

            // 1) init: cria a sessão de upload.
            $init = Http::withToken((string) $account->access_token)
                ->timeout(60)
                ->post(self::INIT_URL, [
                    'post_info' => [
                        'title' => mb_substr($caption !== '' ? $caption : $title, 0, 2200),
                        'privacy_level' => $privacy,
                        'disable_comment' => false,
                        'disable_duet' => false,
                        'disable_stitch' => false,
                    ],
                    'source_info' => [
                        'source' => 'FILE_UPLOAD',
                        'video_size' => $size,
                        'chunk_size' => $size,
                        'total_chunk_count' => 1,
                    ],
                ]);

            $error = $init->json('error.code');
            if (! $init->successful() || ($error !== null && $error !== 'ok')) {
                return PublishResult::fail('Falha ao iniciar publicação no TikTok.', ['response' => $init->json() ?? $init->body()]);
            }

            $publishId = Cast::str($init->json('data.publish_id'));
            $uploadUrl = Cast::str($init->json('data.upload_url'));

            if ($uploadUrl === '') {
                return PublishResult::fail('TikTok não retornou a URL de upload.', ['response' => $init->json()]);
            }

            // 2) envia o arquivo (chunk único).
            $upload = Http::withHeaders([
                'Content-Type' => 'video/mp4',
                'Content-Range' => sprintf('bytes 0-%d/%d', $size - 1, $size),
            ])
                ->withBody((string) file_get_contents($tmp), 'video/mp4')
                ->timeout(600)
                ->put($uploadUrl);

            if (! $upload->successful()) {
                return PublishResult::fail('Falha ao enviar o vídeo ao TikTok.', ['response' => $upload->body()]);
            }

            return PublishResult::ok($publishId ?: null, null, 'Vídeo enviado ao TikTok (processando).', ['publish_id' => $publishId]);
        } catch (PublishException $e) {
            return PublishResult::fail($e->getMessage());
        } catch (Throwable $e) {
            return PublishResult::fail('Erro inesperado ao publicar no TikTok: '.$e->getMessage());
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
