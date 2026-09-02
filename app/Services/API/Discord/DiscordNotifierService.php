<?php

declare(strict_types=1);

namespace App\Services\API\Discord;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class DiscordNotifierService
{
    private const int COLOR_SUCCESS = 0x2E_CC_71;

    private const int COLOR_WARNING = 0xF1_C4_0F;

    private const int COLOR_ERROR = 0xE7_4C_3C;

    public function success(string $title, string $message, ?string $url = null): void
    {
        $this->send($title, $message, self::COLOR_SUCCESS, $url);
    }

    public function warning(string $title, string $message): void
    {
        $this->send($title, $message, self::COLOR_WARNING);
    }

    public function error(string $title, string $message): void
    {
        $this->send($title, $message, self::COLOR_ERROR);
    }

    private function send(string $title, string $message, int $color, ?string $url = null): void
    {
        $webhook = (string) (config('services.discord.webhook'));
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
            Log::channel('daily')->warning('[WARN][DiscordNotifierService] Falha ao enviar webhook.', [
                'exception' => $throwable,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]);
        }
    }
}
