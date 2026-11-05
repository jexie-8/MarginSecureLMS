<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        RateLimiter::for('margin-api', function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) ($request->user()?->id ?? $request->ip()));
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('margin.frontend_url'), '/')
                .'/reset-password?token='.urlencode($token)
                .'&email='.urlencode($user->email);
        });
    }
}
