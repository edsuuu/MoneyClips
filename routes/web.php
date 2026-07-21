<?php

declare(strict_types=1);

use App\Http\Controllers\HLSStreamController;
use App\Http\Controllers\MultipartUploadController;
use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home.welcome')->name('home');

Route::get('/login', fn () => to_route('home', ['login' => 1]))->middleware('guest')->name('login');

Route::view('/termos-de-servico', 'legal.terms')->name('legal.terms');
Route::view('/politica-de-privacidade', 'legal.privacy')->name('legal.privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/oauth2/google/redirect', [OAuthController::class, 'loginRedirect'])->name('auth.google.redirect');
    Route::get('/oauth2/google/callback', [OAuthController::class, 'loginCallback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function (): void {
    Route::view('/dashboard', 'dashboard.index')->name('dashboard.index');

    Route::view('/upload', 'upload.index')->name('upload.index');
    Route::view('/meus-uploads', 'uploads.index')->name('uploads.index');
    Route::view('/meus-uploads/{video:uuid}', 'uploads.show')->name('uploads.show');

    Route::get('/hls/{video:uuid}/master.m3u8', [HLSStreamController::class, 'master'])->name('hls.master');
    Route::get('/hls/{video:uuid}/{path}', [HLSStreamController::class, 'segment'])
        ->where('path', '.*')
        ->name('hls.segment');

    Route::post('/uploads', [MultipartUploadController::class, 'store'])->name('uploads.create');
    Route::get('/uploads/{video:uuid}/parts', [MultipartUploadController::class, 'parts'])->name('uploads.parts.index');
    Route::post('/uploads/{video:uuid}/parts', [MultipartUploadController::class, 'sign'])->name('uploads.parts.sign');
    Route::post('/uploads/{video:uuid}/complete', [MultipartUploadController::class, 'complete'])->name('uploads.complete');
    Route::delete('/uploads/{video:uuid}', [MultipartUploadController::class, 'destroy'])->name('uploads.abort');

    Route::view('/meus-videos', 'videos.index')->name('videos.index');
    Route::redirect('/downloads', '/meus-videos');

    Route::view('/agenda', 'schedule.index')->name('agenda.index');

    Route::view('/estudio-de-cortes', 'reframe.index')->name('reframe.index');

    Route::view('/social-accounts', 'settings.accounts')->name('social-accounts');

    Route::view('/contas', 'accounts.index')->name('accounts.index');

    Route::view('/observabilidade', 'observability.index')->name('observability.index');
    Route::redirect('/microservices', '/observabilidade');

    Route::get('/oauth/{platform}/connect', [OAuthController::class, 'connect'])->name('oauth.connect');
    Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
});

require __DIR__.'/settings.php';
