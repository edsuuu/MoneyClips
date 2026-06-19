<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia mensagens para um webhook do Discord (postagens com sucesso, avisos de
 * estoque baixo e falhas de postagem). Se o webhook não estiver configurado,
 * simplesmente não faz nada.
 */
final class DiscordNotifier
{
    private const int COLOR_SUCCESS = 0x2E_CC_71;

    private const int COLOR_WARNING = 0xF1_C4_0F;

    private const int COLOR_ERROR = 0xE7_4C_3C;

    /**
     * Notifica sucesso (embed verde). `url` torna o título clicável.
     */
    public function success(string $title, string $message, ?string $url = null): void
    {
        $this->send($title, $message, self::COLOR_SUCCESS, $url);
    }

    /**
     * Notifica um aviso (embed amarelo).
     */
    public function warning(string $title, string $message): void
    {
        $this->send($title, $message, self::COLOR_WARNING);
    }

    /**
     * Notifica um erro (embed vermelho).
     */
    public function error(string $title, string $message): void
    {
        $this->send($title, $message, self::COLOR_ERROR);
    }

    private function send(string $title, string $message, int $color, ?string $url = null): void
    {
        $webhook = (string) (config('services.youtube_shorts.discord_webhook'));
        if ($webhook === '') {
            return;
        }

        $embed = [
            'title' => Str::limit($title, 250),
            'description' => Str::limit($message, 4000),
            'color' => $color,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($url !== null && $url !== '') {
            $embed['url'] = $url;
        }

        try {
            Http::asJson()
                ->timeout(10)
                ->post($webhook, ['embeds' => [$embed]])
                ->throw();
        } catch (Throwable $throwable) {
            // Nunca deixar a notificação derrubar o fluxo principal.
            Log::warning('[DiscordNotifier] Falha ao enviar webhook.', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
