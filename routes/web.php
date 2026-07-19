<?php

declare(strict_types=1);

use App\Http\Controllers\HLSStreamController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\Upload\AbortMultipartUploadController;
use App\Http\Controllers\Upload\CompleteMultipartUploadController;
use App\Http\Controllers\Upload\CreateMultipartUploadController;
use App\Http\Controllers\Upload\ListUploadPartsController;
use App\Http\Controllers\Upload\SignUploadPartsController;
use App\Livewire\Uploads\Show as ShowUpload;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home.welcome')->name('home');

Route::get('/login', fn () => to_route('home', ['login' => 1]))->middleware('guest')->name('login');

Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/oauth2/google/redirect', [OAuthController::class, 'loginRedirect'])->name('auth.google.redirect');
    Route::get('/oauth2/google/callback', [OAuthController::class, 'loginCallback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function (): void {
    Route::view('/dashboard', 'dashboard.index')->name('dashboard.index');

    Route::view('/upload', 'upload.index')->name('upload.index');

    Route::view('/meus-uploads', 'uploads.index')->name('uploads.index');
    Route::get('/meus-uploads/{video:uuid}', ShowUpload::class)->name('uploads.show');

    // Manifests e segmentos sob o mesmo prefixo: as URIs relativas do ffmpeg
    // resolvem sem reescrita, e o cookie de sessão autentica cada request.
    Route::get('/hls/{video:uuid}/master.m3u8', [HLSStreamController::class, 'master'])->name('hls.master');
    Route::get('/hls/{video:uuid}/{path}', [HLSStreamController::class, 'segment'])
        ->where('path', '.*')
        ->name('hls.segment');

    // Upload multipart: o Laravel só assina e confere — os bytes vão do browser
    // direto para o MinIO, fora dos limites do PHP.
    Route::post('/uploads', CreateMultipartUploadController::class)->name('uploads.create');
    Route::get('/uploads/{video:uuid}/parts', ListUploadPartsController::class)->name('uploads.parts.index');
    Route::post('/uploads/{video:uuid}/parts', SignUploadPartsController::class)->name('uploads.parts.sign');
    Route::post('/uploads/{video:uuid}/complete', CompleteMultipartUploadController::class)->name('uploads.complete');
    Route::delete('/uploads/{video:uuid}', AbortMultipartUploadController::class)->name('uploads.abort');

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
