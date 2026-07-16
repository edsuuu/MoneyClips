<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\AutoPost\PosterRegistryService;
use App\Services\Kwai\KwaiPosterService;
use App\Services\Meta\Facebook\FacebookReelsPosterService;
use App\Services\Meta\Instagram\InstagramReelsPosterService;
use App\Services\TikTok\Official\TiktokOfficialPosterService;
use App\Services\TikTok\Unofficial\TiktokPosterService;
use App\Services\Youtube\YoutubePosterService;
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
        // criar o *PosterService na pasta da plataforma (App\Services\TikTok,
        // Youtube, Meta\..., Kwai) implementando App\Contracts\PosterInterface
        // e listar aqui (o toggle vive em platform_settings, editável na /agenda).
        $this->app->singleton(PosterRegistryService::class, fn (Application $app): PosterRegistryService => new PosterRegistryService([
            $app->make(YoutubePosterService::class),
            $app->make(TiktokPosterService::class),
            $app->make(TiktokOfficialPosterService::class),
            $app->make(InstagramReelsPosterService::class),
            $app->make(FacebookReelsPosterService::class),
            $app->make(KwaiPosterService::class),
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
