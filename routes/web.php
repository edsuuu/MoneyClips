<?php

declare(strict_types=1);

use App\Http\Controllers\OAuthController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/auth/google/redirect', [OAuthController::class, 'loginRedirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [OAuthController::class, 'loginCallback'])->name('auth.google.callback');
    Route::get('/oauth2/google/redirect', [OAuthController::class, 'loginRedirect'])->name('auth.google.redirect.legacy');
    Route::get('/oauth2/google/callback', [OAuthController::class, 'loginCallback'])->name('auth.google.callback.legacy');
});

Route::middleware(['auth'])->group(function (): void {
    Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');
    Route::get('/videos/create', [VideoController::class, 'create'])->name('videos.create');
    Route::get('/videos/{video}/transcript', [VideoController::class, 'transcript'])->name('videos.transcript');
    Route::get('/videos/{video}/editor', [VideoController::class, 'editor'])->name('videos.editor');
    Route::get('/videos/{video}/schedule', [VideoController::class, 'schedule'])->name('videos.schedule');
    Route::get('/videos/{video}/stream/{path}', [VideoController::class, 'stream'])
        ->where('path', '.*')
        ->name('videos.stream');

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
