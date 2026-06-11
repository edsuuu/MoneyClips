<?php

declare(strict_types=1);

use App\Http\Controllers\OAuthController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/oauth2/google/redirect', [OAuthController::class, 'loginRedirect'])->name('auth.google.redirect');
    Route::get('/oauth2/google/callback', [OAuthController::class, 'loginCallback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function (): void {
    Route::view('/videos', 'videos.index')->name('videos.index');
    Route::view('/videos/create', 'videos.create')->name('videos.create');
    Route::view('/videos/{uuid}/editor', 'videos.editor')->name('videos.editor');
    Route::view('/videos/{video}/schedule', 'videos.schedule')->name('videos.schedule');
    Route::get('/videos/{video}/publications', [VideoController::class, 'publications'])->name('videos.publications');
    Route::get('/videos/{video}/thumbnail', [VideoController::class, 'thumbnail'])->name('videos.thumbnail');
    Route::get('/videos/{video}/stream/{path}', [VideoController::class, 'stream'])
        ->where('path', '.*')
        ->name('videos.stream');
    Route::get('/videos/{video}/cut/{type}', [VideoController::class, 'cut'])->name('videos.cut');

    // Auto-postagem de Shorts (pipeline unificado do auto-post).
    Route::view('/shorts', 'shorts.index')->name('shorts.index');

    // Agendamento social: dashboard de publicações e gestão de contas conectadas.
    Route::view('/posts', 'posts.dashboard')->name('posts.dashboard');
    Route::view('/social-accounts', 'settings.accounts')->name('social-accounts');

    // OAuth das redes sociais (conectar contas com 1 clique).
    Route::get('/oauth/{platform}/connect', [OAuthController::class, 'connect'])->name('oauth.connect');
    Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
