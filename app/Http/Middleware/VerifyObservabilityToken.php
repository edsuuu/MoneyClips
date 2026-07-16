<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth dos endpoints de observabilidade: token compartilhado no header
 * X-Observability-Token, comparado com services.observability.token (env
 * OBSERVABILITY_TOKEN). Sem token configurado, rejeita tudo (fail-closed) e
 * loga o motivo — evita rodar aberto por engano.
 */
final class VerifyObservabilityToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.observability.token', '');

        if ($expected === '') {
            Log::warning('[Observability] OBSERVABILITY_TOKEN não configurado — requisição rejeitada.');

            return response()->json(['detail' => 'observability token não configurado'], 503);
        }

        if (! hash_equals($expected, (string) $request->header('X-Observability-Token', ''))) {
            return response()->json(['detail' => 'não autorizado'], 401);
        }

        return $next($request);
    }
}
