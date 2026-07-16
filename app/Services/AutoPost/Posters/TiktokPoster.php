<?php

declare(strict_types=1);

namespace App\Services\AutoPost\Posters;

use App\Models\PlatformSetting;
use App\Models\SocialAccount;
use App\Services\DiscordNotifier;
use App\Services\TikTok\SessionInvalidException;
use App\Services\TikTok\TiktokUploaderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posta no TikTok via microserviço uploader (Playwright), de forma SÍNCRONA:
 * o Laravel envia o binário do vídeo + cookies do banco e recebe o desfecho
 * na resposta (completed | dry-run | restricted). Roda dentro do job
 * PostSlotToPlatform (fila `posting`) por causa da duração (~15 min no pior
 * caso de verificação de conteúdo).
 */
final readonly class TiktokPoster implements PosterContract
{
    public function __construct(
        private TiktokUploaderClient $client,
        private DiscordNotifier $discord,
    ) {}

    public function platform(): string
    {
        return 'tiktok';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::isEnabled($this->platform());
    }

    public function post(PostTask $task): PosterResult
    {
        $account = SocialAccount::query()
            ->where('platform', 'tiktok')
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (! $account instanceof SocialAccount) {
            return PosterResult::failed($this->platform(), 'Nenhuma conta TikTok ativa cadastrada em /contas.');
        }

        // Curto-circuito: sessão marcada como inválida — postar de novo só
        // acumula falha e flag de spam. O operador renova em /contas.
        if ($account->session_status === SocialAccount::SESSION_INVALID) {
            Log::warning('[AutoPost][TikTok] Sessão inválida — pulando disparo.', ['short_id' => $task->short->id]);

            return PosterResult::failed($this->platform(), 'Sessão inválida — renove os cookies em /contas.');
        }

        $cookies = is_array($account->cookies) ? $account->cookies : [];
        if ($cookies === []) {
            return PosterResult::failed($this->platform(), 'Conta TikTok sem cookies salvos — importe em /contas.');
        }

        try {
            $result = $this->client->postVideo($task->videoPath, $cookies, $task->title, $task->hashtags);
        } catch (SessionInvalidException $exception) {
            $account->forceFill(['session_status' => SocialAccount::SESSION_INVALID])->save();
            $this->discord->error(
                '🔒 Sessão TikTok inválida',
                'O uploader recusou os cookies da conta.'.PHP_EOL.
                'Renove as credenciais em /contas antes do próximo slot.'.PHP_EOL.
                'Detalhe: '.$exception->getMessage(),
            );

            return PosterResult::failed($this->platform(), 'Sessão inválida: '.$exception->getMessage());
        } catch (ConnectionException $exception) {
            // Timeout ≠ falha certa: o Playwright pode ter postado antes do
            // corte. Não repostar automaticamente — o operador confere.
            $message = 'Timeout/conexão com o uploader — verifique manualmente no TikTok se o vídeo saiu. '.$exception->getMessage();
            Log::error('[AutoPost][TikTok] '.$message, ['short_id' => $task->short->id]);
            $this->discord->error('❌ TikTok: timeout no upload', ($task->title ?: $task->short->youtube_id).PHP_EOL.$message);

            return PosterResult::failed($this->platform(), $message);
        } catch (Throwable $throwable) {
            Log::error('[AutoPost][TikTok] Falha ao postar.', ['short_id' => $task->short->id, 'error' => $throwable->getMessage()]);
            $this->discord->error('❌ Falha ao postar no TikTok', ($task->title ?: $task->short->youtube_id).PHP_EOL.$throwable->getMessage());

            return PosterResult::failed($this->platform(), $throwable->getMessage());
        }

        // Post aceito = cookies funcionaram; reflete na conta.
        $account->forceFill([
            'session_status' => SocialAccount::SESSION_VALID,
            'cookies_last_validated_at' => now(),
        ])->save();

        if ($result->isRestricted()) {
            $this->discord->warning(
                '⚠️ TikTok restringiu o post',
                ($task->title ?: $task->short->youtube_id).PHP_EOL.
                'Modal de moderação detectado — o vídeo não volta pro sorteio.'.PHP_EOL.
                'Detalhe: '.($result->detail ?? '—'),
            );

            return PosterResult::restricted($this->platform(), $result->detail);
        }

        if ($result->isDryRun()) {
            Log::info('[AutoPost][TikTok] DRY_RUN — post simulado.', ['short_id' => $task->short->id]);

            return PosterResult::dryRun($this->platform());
        }

        Log::info('[AutoPost][TikTok] Short postado.', ['short_id' => $task->short->id]);
        $this->discord->success('✅ Short postado no TikTok', $task->title ?: $task->short->youtube_id);

        return PosterResult::ok($this->platform());
    }
}
