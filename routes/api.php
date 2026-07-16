<?php

declare(strict_types=1);

use App\Http\Controllers\AutoCaptionWebhookController;
use App\Http\Controllers\DownloadYoutubeWebhookController;
use App\Http\Controllers\Observability\StoreHeartbeatController;
use App\Http\Controllers\Observability\StoreLogsController;
use App\Http\Middleware\VerifyObservabilityToken;
use Illuminate\Support\Facades\Route;

Route::post('/download-youtube/webhook', DownloadYoutubeWebhookController::class)
    ->name('download-youtube.webhook');

Route::post('/autocaption/webhook', AutoCaptionWebhookController::class)
    ->name('autocaption.webhook');

// Observabilidade (OBSERVABILITY.md): push de logs em lote + heartbeat dos
// microserviços, autenticado por token compartilhado.
Route::prefix('observability')->middleware(VerifyObservabilityToken::class)->group(function (): void {
    Route::post('/logs', StoreLogsController::class)->name('observability.logs');
    Route::post('/heartbeat', StoreHeartbeatController::class)->name('observability.heartbeat');
});
