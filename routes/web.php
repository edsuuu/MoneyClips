<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/oauth2/google/redirect', [OAuthController::class, 'loginRedirect'])->name('auth.google.redirect');
    Route::get('/oauth2/google/callback', [OAuthController::class, 'loginCallback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function (): void {
    Route::view('/downloads', 'downloads.index')->name('downloads.index');

    // Visão semanal do schedule (horários sorteados + status por slot).
    // Path em pt-BR (UX); namespace/view/classe em inglês (App\Livewire\Schedule).
    Route::view('/agenda', 'schedule.index')->name('agenda.index');

    Route::view('/social-accounts', 'settings.accounts')->name('social-accounts');

    // Captura de cookies do TikTok pelo navegador do usuário (extensão Chrome).
    Route::view('/tiktok/connect', 'tiktok-auth.connect')->name('tiktok.connect');

    Route::view('/microservices', 'microservices.index')->name('microservices.index');

    // OAuth das redes sociais (conectar contas com 1 clique).
    Route::get('/oauth/{platform}/connect', [OAuthController::class, 'connect'])->name('oauth.connect');
    Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
