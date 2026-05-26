<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\VideoProcessor\Contracts\VideoProcessorProviderInterface;
use App\Services\VideoProcessor\Providers\HttpVideoProcessorProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Override;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\TikTok\Provider as TikTokProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Contrato -> implementação HTTP. Trocar o provider aqui (ex: fake nos testes).
        $this->app->bind(VideoProcessorProviderInterface::class, HttpVideoProcessorProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Registra o provider TikTok do socialiteproviders (Google e Facebook são nativos do Socialite).
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('tiktok', TikTokProvider::class);
        });

        Gate::define('viewLogViewer', fn (User $user) => $user->hasRole('Administrador')
            ? Response::allow()
            : Response::deny(__('This action is unauthorized.')));
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
