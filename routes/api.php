<?php

declare(strict_types=1);

use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\Webhooks\AutoCaptionWebhookController;
use App\Http\Controllers\Webhooks\CutWebhookController;
use App\Http\Controllers\Webhooks\DownloadVideoWebhookController;
use App\Http\Controllers\Webhooks\DownloadYoutubeWebhookController;
use App\Http\Controllers\Webhooks\HLSWebhookController;
use App\Http\Controllers\Webhooks\TiktokPostWebhookController;
use App\Http\Controllers\Webhooks\TranscribeWebhookController;
use App\Http\Controllers\Webhooks\VideoCutEditWebhookController;
use App\Http\Middleware\VerifyObservabilityToken;
use Illuminate\Support\Facades\Route;

Route::prefix('webhook')->name('webhook.')->group(function (): void {
    Route::post('/download-youtube', DownloadYoutubeWebhookController::class)->name('download-youtube');
    Route::post('/autocaption', AutoCaptionWebhookController::class)->name('autocaption');

    Route::middleware(VerifyObservabilityToken::class)->group(function (): void {
        Route::post('/download-video', DownloadVideoWebhookController::class)->name('download-video');
        Route::post('/hls', HLSWebhookController::class)->name('hls');
        Route::post('/cut', CutWebhookController::class)->name('cut');
        Route::post('/video-cut-edit', VideoCutEditWebhookController::class)->name('video-cut-edit');
        Route::post('/tiktok-posts', TiktokPostWebhookController::class)->name('tiktok-posts');
        Route::post('/transcribe', TranscribeWebhookController::class)->name('transcribe');
    });
});

Route::prefix('observability')->middleware(VerifyObservabilityToken::class)->group(function (): void {
    Route::post('/logs', [ObservabilityController::class, 'logs'])->name('observability.logs');
    Route::post('/heartbeat', [ObservabilityController::class, 'heartbeat'])->name('observability.heartbeat');
});
