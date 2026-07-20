<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `hls` e `reencode` viraram `video`; `autocaption` virou `transcriber`. Os
 * heartbeats são upsert por nome de serviço, então os nomes antigos ficam como
 * linhas órfãs — e o `observability:check-heartbeats` varre a tabela inteira,
 * o que faria cada uma disparar um "🔴 Microserviço fora do ar" no Discord e
 * nunca mais se recuperar.
 */
return new class extends Migration
{
    private const array RENAMED = ['hls', 'reencode', 'autocaption'];

    public function up(): void
    {
        DB::table('service_heartbeats')->whereIn('service', self::RENAMED)->delete();
    }

    public function down(): void
    {
        // Sem volta: heartbeat é estado efêmero — o próprio serviço recria a
        // linha no ciclo seguinte de 30s.
    }
};
