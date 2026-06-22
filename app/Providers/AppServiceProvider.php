<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\AutoPost\AutoPostDispatcher;
use App\Services\AutoPost\Posters\TiktokPoster;
use App\Services\AutoPost\Posters\YoutubePoster;
use App\Services\AutoPost\StockReservation;
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
        // Lista ordenada dos Posters do AutoPostDispatcher. YouTube vem 1º
        // (síncrono — falha aqui é falha do post); TikTok depois (assíncrono).
        // Adicione novos posters aqui pra estendê-lo a outras plataformas.
        $this->app->singleton(AutoPostDispatcher::class, fn (Application $app): AutoPostDispatcher => new AutoPostDispatcher(
            stock: $app->make(StockReservation::class),
            posters: [
                $app->make(YoutubePoster::class),
                $app->make(TiktokPoster::class),
            ],
        ));
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
