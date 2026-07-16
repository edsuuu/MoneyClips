<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\AutoPost\PosterRegistry;
use App\Services\AutoPost\Posters\FacebookReelsPoster;
use App\Services\AutoPost\Posters\InstagramReelsPoster;
use App\Services\AutoPost\Posters\KwaiPoster;
use App\Services\AutoPost\Posters\TiktokOfficialPoster;
use App\Services\AutoPost\Posters\TiktokPoster;
use App\Services\AutoPost\Posters\YoutubePoster;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Override;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Registro dos Posters por plataforma. Adicionar plataforma nova =
        // criar o Poster em App\Services\AutoPost\Posters e listar aqui
        // (o toggle vive em platform_settings, editável na /agenda).
        $this->app->singleton(PosterRegistry::class, fn (Application $app): PosterRegistry => new PosterRegistry([
            $app->make(YoutubePoster::class),
            $app->make(TiktokPoster::class),
            $app->make(TiktokOfficialPoster::class),
            $app->make(InstagramReelsPoster::class),
            $app->make(FacebookReelsPoster::class),
            $app->make(KwaiPoster::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        //        Gate::define('viewLogViewer', fn (User $user) => $user->hasRole('Administrador')
        //            ? Response::allow()
        //            : Response::deny(__('This action is unauthorized.')));

        Gate::define('viewLogViewer', fn (?User $user = null): bool => true);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
