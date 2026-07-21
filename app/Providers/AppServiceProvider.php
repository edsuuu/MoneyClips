<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Api\Kwai\KwaiPosterService;
use App\Services\Api\Meta\Facebook\FacebookReelsPosterService;
use App\Services\Api\Meta\Instagram\InstagramReelsPosterService;
use App\Services\Api\TikTok\TiktokOfficialPosterService;
use App\Services\Api\Youtube\YoutubePosterService;
use App\Services\AutoPost\PosterRegistryService;
use App\Services\TikTokUploader\TiktokPosterService;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\MultipartUploadService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Override;

final class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // Registro dos Posters por plataforma. Adicionar plataforma nova =
        // criar o *PosterService na pasta da integração (API oficial em
        // App\Services\Api\<Plataforma>; microserviço em App\Services\<Nome>)
        // implementando App\Services\AutoPost\PosterInterface e listar aqui
        // (o toggle vive em platform_settings, editável na /agenda).
        $this->app->singleton(PosterRegistryService::class, fn (Application $app): PosterRegistryService => new PosterRegistryService([
            $app->make(YoutubePosterService::class),
            $app->make(TiktokPosterService::class),
            $app->make(TiktokOfficialPosterService::class),
            $app->make(InstagramReelsPosterService::class),
            $app->make(FacebookReelsPosterService::class),
            $app->make(KwaiPosterService::class),
        ]));

        $this->app->bind(MultipartUploadInterface::class, MultipartUploadService::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();

        // Um envio de 3GB gasta ~10 requisições (assina de 20 em 20 partes), e o
        // teto de sessões abertas é 5 — o limite é folgado pro uso real e fecha
        // o laço de quem chama /uploads em looping.
        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->user()?->id));

        //        Gate::define('viewLogViewer', fn (User $user) => $user->hasRole('Administrador')
        //            ? Response::allow()
        //            : Response::deny(__('This action is unauthorized.')));

        Gate::define('viewLogViewer', fn (?User $user = null): bool => true);
    }

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
