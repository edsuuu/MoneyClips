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
 * Publica vídeo em uma Página do Facebook via Graph API.
 * Requer SocialAccount com:
 *  - access_token: Page Access Token com escopos pages_manage_posts/pages_read_engagement
 *  - external_account_id ou meta.page_id: o Page ID
 *
 * Usa file_url (URL pública do MinIO), que o Facebook busca pela internet.
 */
final class FacebookPublisher extends AbstractPublisher
{
    public function key(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    public function publish(ScheduledPost $post): PublishResult
    {
        try {
            $account = $this->requireAccount($post);
            $meta = Cast::arr($account->meta);
            $pageId = Cast::str($meta['page_id'] ?? $account->external_account_id ?? '');

            throw_if($pageId === '', PublishException::class, 'Page ID ausente. Configure external_account_id ou meta.page_id na conta.');

            $file = $this->requireVideoFile($post);
            $videoUrl = $this->publicUrl($file);
            $base = $this->graphBase();

            $title = mb_trim((string) ($post->title ?: ''));

            $response = Http::timeout(120)->asForm()->post(sprintf('%s/%s/videos', $base, $pageId), [
                'file_url' => $videoUrl,
                'title' => mb_substr($title, 0, 255),
                'description' => $this->caption($post),
                'access_token' => (string) $account->access_token,
            ]);

            if (! $response->successful()) {
                return PublishResult::fail('Falha ao publicar o vídeo no Facebook.', ['response' => $response->json() ?? $response->body()]);
            }

            $videoId = Cast::str($response->json('id'));
            $url = $videoId !== '' ? 'https://facebook.com/'.$videoId : null;

            return PublishResult::ok($videoId ?: null, $url, 'Vídeo publicado no Facebook.', ['response' => $response->json()]);
        } catch (PublishException $e) {
            return PublishResult::fail($e->getMessage());
        } catch (Throwable $e) {
            return PublishResult::fail('Erro inesperado ao publicar no Facebook: '.$e->getMessage());
        }
    }

    private function graphBase(): string
    {
        $version = Cast::str(config('social-publishing.graph_version', 'v21.0'));

        return 'https://graph.facebook.com/'.$version;
    }
}
