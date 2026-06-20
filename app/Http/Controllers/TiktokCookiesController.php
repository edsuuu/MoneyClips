<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TikTok\TikTokUploaderClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint chamado pela extensão Chrome `tiktok-cookie-bridge`: recebe os
 * cookies do `.tiktok.com` exportados do navegador do usuário e repassa pro
 * microserviço tiktok-uploader (`POST /session`).
 *
 * Sem token configurado em `services.tiktok_post.bridge_token`, o endpoint
 * responde 503 — fechado por default.
 */
final class TiktokCookiesController extends Controller
{
    public const string LAST_INGEST_CACHE_KEY = 'tiktok.cookies.last_ingest_at';

    public function ingest(Request $request, TikTokUploaderClient $uploader): JsonResponse
    {
        $expected = (string) config('services.tiktok_post.bridge_token', '');
        if ($expected === '') {
            return response()->json(['error' => 'bridge_token não configurado'], 503);
        }

        $provided = (string) $request->header('X-Bridge-Token', '');
        if (! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $rawCookies = $request->input('cookies');
        if (! is_array($rawCookies) || $rawCookies === []) {
            return response()->json(['error' => 'cookies vazios'], 422);
        }

        $cookies = [];
        foreach ($rawCookies as $cookie) {
            if (! is_array($cookie)) {
                continue;
            }

            if (! isset($cookie['name'], $cookie['value'], $cookie['domain'], $cookie['path'])) {
                continue;
            }

            /** @var array<string, mixed> $cookie */
            $cookies[] = $cookie;
        }

        if ($cookies === []) {
            return response()->json(['error' => 'cookies inválidos'], 422);
        }

        try {
            $session = $uploader->injectSession($cookies);
        } catch (Throwable $throwable) {
            Log::warning('TiktokCookiesController: uploader rejeitou os cookies', [
                'error' => $throwable->getMessage(),
            ]);

            return response()->json([
                'error' => 'falha ao injetar sessão no uploader',
                'detail' => $throwable->getMessage(),
            ], 502);
        }

        Cache::put(self::LAST_INGEST_CACHE_KEY, Date::now()->toIso8601String(), Date::now()->addHours(6));

        return response()->json([
            'ok' => true,
            'count' => count($cookies),
            'session' => $session,
        ]);
    }
}
