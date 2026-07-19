<?php

declare(strict_types=1);

use App\Http\Controllers\AutoCaptionWebhookController;
use App\Http\Controllers\DownloadYoutubeWebhookController;
use App\Http\Controllers\HLSWebhookController;
use App\Http\Controllers\Observability\StoreHeartbeatController;
use App\Http\Controllers\Observability\StoreLogsController;
use App\Http\Controllers\TiktokPostWebhookController;
use App\Http\Middleware\VerifyObservabilityToken;
use Illuminate\Support\Facades\Route;

Route::post('/download-youtube/webhook', DownloadYoutubeWebhookController::class)
    ->name('download-youtube.webhook');

Route::post('/autocaption/webhook', AutoCaptionWebhookController::class)
    ->name('autocaption.webhook');

// O desfecho promove o vídeo a reproduzível e pode apagar o binário original
// (status `rejected`) — vai atrás do token compartilhado.
Route::post('/hls/webhook', HLSWebhookController::class)
    ->middleware(VerifyObservabilityToken::class)
    ->name('hls.webhook');

// Único webhook que ESCREVE credenciais (cookies/session_status) — vai atrás
// do token compartilhado (o uploader manda o mesmo OBSERVABILITY_TOKEN que já
// usa pros logs/heartbeat; zero env nova).
Route::post('/tiktok-posts/webhook', TiktokPostWebhookController::class)
    ->middleware(VerifyObservabilityToken::class)
    ->name('tiktok-posts.webhook');

// Observabilidade (OBSERVABILITY.md): push de logs em lote + heartbeat dos
// microserviços, autenticado por token compartilhado.
Route::prefix('observability')->middleware(VerifyObservabilityToken::class)->group(function (): void {
    Route::post('/logs', StoreLogsController::class)->name('observability.logs');
    Route::post('/heartbeat', StoreHeartbeatController::class)->name('observability.heartbeat');
});
