<?php

declare(strict_types=1);

use App\Http\Middleware\RequirePasswordUnlessGoogleAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'password.confirm' => RequirePasswordUnlessGoogleAuthenticated::class,
        ]);

        // Quem já está logado não vê tela de guest (ex.: /login) — vai pro estoque.
        $middleware->redirectUsersTo('/meus-videos');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
