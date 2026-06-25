<?php

declare(strict_types=1);

use App\Http\Controllers\DownloadYoutubeWebhookController;
use App\Http\Controllers\TiktokPostCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/tiktok-posts/callback', TiktokPostCallbackController::class)
    ->name('tiktok-posts.callback');

Route::post('/download-youtube/webhook', DownloadYoutubeWebhookController::class)
    ->name('download-youtube.webhook');
