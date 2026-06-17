<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Status;
use App\Models\StatusLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Centraliza transições de status e registra status_logs.
 * Aplica-se a qualquer model com coluna status_id (Video, Cut).
 */
final class StatusService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function transition(Model $model, string $toKey, ?string $message = null, array $context = []): void
    {
        $toId = Status::idFor($toKey);
        if ($toId === 0) {
            return;
        }

        $fromId = $model->getAttribute('status_id');
        $fromId = is_numeric($fromId) ? (int) $fromId : null;

        if ($model->getAttribute('status_id') !== $toId) {
            $model->setAttribute('status_id', $toId);
            $model->save();
        }

        StatusLog::query()->create([
            'statusable_type' => $model->getMorphClass(),
            'statusable_id' => $model->getKey(),
            'from_status_id' => $fromId,
            'to_status_id' => $toId,
            'message' => $message,
            'context' => $context ?: null,
        ]);
    }
}
