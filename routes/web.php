<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\HLSStreamController;
use App\Http\Controllers\MultipartUploadController;
use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\UploadSubtitlesController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home.welcome')->name('home');

Route::get('/login', fn () => to_route('home', ['login' => 1]))->middleware('guest')->name('login');

Route::view('/termos-de-servico', 'legal.terms')->name('legal.terms');
Route::view('/politica-de-privacidade', 'legal.privacy')->name('legal.privacy');

Route::middleware('guest')
    ->prefix('oauth2/google')
    ->name('auth.google.')
    ->controller(OAuthController::class)
    ->group(function (): void {
        Route::get('/redirect', 'loginRedirect')->name('redirect');
        Route::get('/callback', 'loginCallback')->name('callback');
    });

Route::middleware(['auth'])->group(function (): void {
    Route::view('/dashboard', 'dashboard.index')->name('dashboard.index');

    Route::view('/upload', 'upload.create')->name('uploads.create');

    Route::prefix('meus-uploads')->name('uploads.')->group(function (): void {
        Route::view('/', 'uploads.index')->name('index');
        Route::view('/{video:uuid}', 'uploads.show')->name('show');
        Route::get('/{video:uuid}/legendas.vtt', UploadSubtitlesController::class)->name('subtitles');
    });

    Route::prefix('hls/{video:uuid}')
        ->name('hls.')
        ->controller(HLSStreamController::class)
        ->group(function (): void {
            Route::get('/master.m3u8', 'master')->name('master');
            Route::get('/{path}', 'segment')->where('path', '.*')->name('segment');
        });

    Route::post('/client-logs', [ObservabilityController::class, 'browserLog'])
        ->middleware('throttle:client-logs')
        ->name('client-logs.store');

    Route::prefix('uploads')
        ->name('uploads.')
        ->middleware('throttle:uploads')
        ->controller(MultipartUploadController::class)
        ->group(function (): void {
            Route::post('/', 'store')->name('create');
            Route::get('/{video:uuid}/parts', 'parts')->name('parts.index');
            Route::post('/{video:uuid}/parts', 'sign')->name('parts.sign');
            Route::post('/{video:uuid}/complete', 'complete')->name('complete');
        });

    Route::view('/meus-videos', 'videos.index')->name('videos.index');
    Route::view('/agenda', 'schedule.index')->name('agenda.index');
    Route::view('/editor-de-video/{cut}', 'video-editor.index')->name('video-editor.index');
    Route::view('/contas', 'accounts.index')->name('accounts.index');
    Route::view('/observabilidade', 'observability.index')->name('observability.index');

    Route::prefix('oauth/{platform}')
        ->name('oauth.')
        ->controller(OAuthController::class)
        ->group(function (): void {
            Route::get('/connect', 'connect')->name('connect');
            Route::get('/callback', 'callback')->name('callback');
        });
});

require __DIR__.'/settings.php';
