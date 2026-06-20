<?php

declare(strict_types=1);

use App\Http\Controllers\DownloadYoutubeWebhookController;
use App\Http\Controllers\TiktokCookiesController;
use App\Http\Controllers\TiktokPostCallbackController;
use App\Http\Controllers\VideoProcessorCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/video-processor/callbacks', VideoProcessorCallbackController::class)
    ->name('video-processor.callbacks');

Route::post('/tiktok-posts/callback', TiktokPostCallbackController::class)
    ->name('tiktok-posts.callback');

Route::post('/download-youtube/webhook', DownloadYoutubeWebhookController::class)
    ->name('download-youtube.webhook');

// Recebe cookies da extensão Chrome `tiktok-cookie-bridge` e repassa pro
// uploader (POST /session). Protegido por X-Bridge-Token (TIKTOK_BRIDGE_TOKEN).
Route::post('/tiktok/cookies/ingest', [TiktokCookiesController::class, 'ingest'])
    ->name('tiktok.cookies.ingest');
