<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\Upload\MultipartUploadInterface;
use App\Services\Upload\MultipartUploadService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
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
        $this->app->bind(MultipartUploadInterface::class, MultipartUploadService::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();

        // Um envio de 3GB gasta ~10 requisições (assina de 20 em 20 partes), e o
        // teto de sessões abertas é 5 — o limite é folgado pro uso real e fecha
        // o laço de quem chama /uploads em looping.
        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->user()?->id));

        RateLimiter::for('client-logs', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->user()?->id));

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
