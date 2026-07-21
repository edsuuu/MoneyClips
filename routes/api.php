<?php

declare(strict_types=1);

use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\Webhooks\AutoCaptionWebhookController;
use App\Http\Controllers\Webhooks\DownloadYoutubeWebhookController;
use App\Http\Controllers\Webhooks\HLSWebhookController;
use App\Http\Controllers\Webhooks\TiktokPostWebhookController;
use App\Http\Middleware\VerifyObservabilityToken;
use Illuminate\Support\Facades\Route;

Route::post('/download-youtube/webhook', DownloadYoutubeWebhookController::class)
    ->name('download-youtube.webhook');

Route::post('/autocaption/webhook', AutoCaptionWebhookController::class)
    ->name('autocaption.webhook');

Route::post('/hls/webhook', HLSWebhookController::class)
    ->middleware(VerifyObservabilityToken::class)
    ->name('hls.webhook');

Route::post('/tiktok-posts/webhook', TiktokPostWebhookController::class)
    ->middleware(VerifyObservabilityToken::class)
    ->name('tiktok-posts.webhook');

Route::prefix('observability')->middleware(VerifyObservabilityToken::class)->group(function (): void {
    Route::post('/logs', [ObservabilityController::class, 'logs'])->name('observability.logs');
    Route::post('/heartbeat', [ObservabilityController::class, 'heartbeat'])->name('observability.heartbeat');
});
