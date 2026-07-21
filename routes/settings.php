<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function (): void {
    Route::redirect('configuracoes', 'configuracoes/perfil');

    Route::view('configuracoes/perfil', 'settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('configuracoes/aparencia', 'settings.appearance')->name('appearance.edit');

    Route::view('configuracoes/seguranca', 'settings.security')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('security.edit');
});
