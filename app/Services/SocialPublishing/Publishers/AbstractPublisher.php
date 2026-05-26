<?php

declare(strict_types=1);

namespace App\Services\SocialPublishing\Publishers;

use App\Models\File;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Services\SocialPublishing\Contracts\SocialPublisher;
use App\Services\SocialPublishing\OAuth\TokenRefresher;
use App\Services\SocialPublishing\PublishException;
use Illuminate\Support\Facades\Storage;

/**
 * Base dos publishers reais. Centraliza resolução de conta/token, obtenção do
 * arquivo de vídeo do corte (bytes locais ou URL pública) e montagem da legenda.
 */
abstract class AbstractPublisher implements SocialPublisher
{
    public function __construct(protected readonly TokenRefresher $tokenRefresher) {}

    /** Resolve a conta conectada do post, garantindo que está utilizável. */
    protected function requireAccount(ScheduledPost $post): SocialAccount
    {
        $account = $post->account;

        throw_unless($account instanceof SocialAccount, PublishException::class, 'Nenhuma conta conectada para esta plataforma. Conecte uma conta em "Contas sociais".');

        throw_unless($account->is_active, PublishException::class, 'A conta conectada está inativa.');

        throw_if(empty($account->access_token), PublishException::class, 'A conta conectada não tem access token configurado.');

        // Tenta renovar via refresh_token antes de desistir.
        throw_if($account->tokenExpired() && ! $this->tokenRefresher->ensureFresh($account), PublishException::class, 'O token da conta expirou e não foi possível renovar. Reconecte a conta.');

        return $account;
    }

    /** Arquivo de vídeo renderizado do corte (ou do vídeo, como fallback). */
    protected function requireVideoFile(ScheduledPost $post): File
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

    /** Baixa o arquivo do MinIO para um caminho temporário local; o chamador deve apagar. */
    protected function downloadToTemp(File $file): string
    {
        $disk = Storage::disk($file->disk ?: 'minio');
        $contents = $disk->get($file->path);

        throw_if($contents === null, PublishException::class, 'Falha ao ler o arquivo de vídeo do storage.');

        $ext = $file->extension ?: 'mp4';
        $tmp = tempnam(sys_get_temp_dir(), 'pub_').'.'.$ext;
        file_put_contents($tmp, $contents);

        return $tmp;
    }

    /** URL pública temporária do arquivo (para plataformas que buscam por URL). */
    protected function publicUrl(File $file, int $minutes = 180): string
    {
        return $file->temporaryUrl($minutes);
    }

    /** Legenda final: descrição + hashtags com '#'. */
    protected function caption(ScheduledPost $post): string
    {
        $description = mb_trim((string) $post->description);
        $tags = $this->hashtagsString($post);

        return mb_trim($description.($tags !== '' ? "\n\n".$tags : ''));
    }

    protected function hashtagsString(ScheduledPost $post): string
    {
        $tags = array_map(
            static fn (string $t): string => '#'.mb_ltrim($t, '#'),
            $post->hashtagList(),
        );

        return implode(' ', $tags);
    }
}
