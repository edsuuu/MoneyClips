<?php

declare(strict_types=1);

use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Raiz: logado vai pro estoque, deslogado pra tela de login (Fortify).
Route::get('/', fn () => Auth::check() ? to_route('downloads.index') : to_route('login'))->name('home');
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

    // Contas TikTok (login por email/senha; sem OAuth). Path pt-BR (UX),
    // namespace/view/classe em inglês (App\Livewire\Accounts).
    Route::view('/contas', 'accounts.index')->name('accounts.index');

    Route::view('/microservices', 'microservices.index')->name('microservices.index');

    // Inspeção de metadados de vídeo (ffprobe) de qualquer Short do estoque.
    Route::view('/videos/inspect', 'videos.inspect')->name('videos.inspect');

    // OAuth das redes sociais (conectar contas com 1 clique).
    Route::get('/oauth/{platform}/connect', [OAuthController::class, 'connect'])->name('oauth.connect');
    Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
