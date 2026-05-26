<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing\Publishers;

use App\Models\ScheduledPost;
use App\Services\SocialPublishing\PublishException;
use App\Services\SocialPublishing\PublishResult;
use App\Support\Cast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Publica Reels no Instagram via Graph API (conta Instagram Business/Creator).
 * Requer SocialAccount com:
 *  - access_token: token de página (longo) com escopos instagram_content_publish
 *  - external_account_id ou meta.ig_user_id: o IG User ID
 *
 * O Instagram BUSCA o vídeo por URL pública — a URL temporária do MinIO precisa
 * ser acessível pela internet (em produção, atrás de um domínio público).
 */
final class InstagramPublisher extends AbstractPublisher
{
    public function key(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram Reels';
    }

    public function publish(ScheduledPost $post): PublishResult
    {
        try {
            $account = $this->requireAccount($post);
            $meta = Cast::arr($account->meta);
            $igUserId = Cast::str($meta['ig_user_id'] ?? $account->external_account_id ?? '');

            throw_if($igUserId === '', PublishException::class, 'IG User ID ausente. Configure external_account_id ou meta.ig_user_id na conta.');

            $file = $this->requireVideoFile($post);
            $videoUrl = $this->publicUrl($file);
            $base = $this->graphBase();
            $token = (string) $account->access_token;

            // 1) cria o container do Reels.
            $container = Http::timeout(60)->asForm()->post(sprintf('%s/%s/media', $base, $igUserId), [
                'media_type' => 'REELS',
                'video_url' => $videoUrl,
                'caption' => $this->caption($post),
                'access_token' => $token,
            ]);

            if (! $container->successful()) {
                return PublishResult::fail('Falha ao criar o container do Reels.', ['response' => $container->json() ?? $container->body()]);
            }

            $creationId = Cast::str($container->json('id'));
            if ($creationId === '') {
                return PublishResult::fail('Instagram não retornou o id do container.', ['response' => $container->json()]);
            }

            // 2) aguarda o processamento do vídeo (status FINISHED).
            $ready = $this->waitForContainer($base, $creationId, $token);
            if (! $ready) {
                return PublishResult::fail('O Instagram não terminou de processar o vídeo a tempo.', ['creation_id' => $creationId]);
            }

            // 3) publica o container.
            $publish = Http::timeout(60)->asForm()->post(sprintf('%s/%s/media_publish', $base, $igUserId), [
                'creation_id' => $creationId,
                'access_token' => $token,
            ]);

            if (! $publish->successful()) {
                return PublishResult::fail('Falha ao publicar o Reels.', ['response' => $publish->json() ?? $publish->body()]);
            }

            $mediaId = Cast::str($publish->json('id'));

            return PublishResult::ok($mediaId ?: null, null, 'Reels publicado no Instagram.', ['response' => $publish->json()]);
        } catch (PublishException $e) {
            return PublishResult::fail($e->getMessage());
        } catch (Throwable $e) {
            return PublishResult::fail('Erro inesperado ao publicar no Instagram: '.$e->getMessage());
        }
    }

    private function waitForContainer(string $base, string $creationId, string $token, int $attempts = 12, int $sleepSeconds = 5): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            $status = Http::timeout(30)->get(sprintf('%s/%s', $base, $creationId), [
                'fields' => 'status_code',
                'access_token' => $token,
            ]);

            $code = Cast::str($status->json('status_code'));
            if ($code === 'FINISHED') {
                return true;
            }

            if ($code === 'ERROR') {
                return false;
            }

            Sleep::sleep($sleepSeconds);
        }

        return false;
    }

    private function graphBase(): string
    {
        $version = Cast::str(config('social-publishing.graph_version', 'v21.0'));

        return 'https://graph.facebook.com/'.$version;
    }
}
