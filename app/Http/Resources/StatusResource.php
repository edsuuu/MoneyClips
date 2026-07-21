<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Desfecho de protocolo (webhooks, observabilidade, acks de upload): o corpo
 * é sempre {status, ...extra} e o proprio resource carrega o codigo HTTP, pra
 * nao espalhar response()->json([...], 404) por controller.
 */
final class StatusResource extends JsonResource
{
    public static $wrap;

    /** @param  array<string, mixed>  $extra */
    public function __construct(
        private readonly string $status,
        private readonly int $code = 200,
        private readonly array $extra = [],
    ) {
        parent::__construct($status);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['status' => $this->status, ...$this->extra];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode($this->code);
    }
}
