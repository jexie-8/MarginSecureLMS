<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        // Ids in the URL are plain positive integers. Anything else is a 404 at
        // the router instead of a database error.
        foreach (['course', 'user', 'assignment', 'submission', 'grade', 'enrollment', 'department',
            'program', 'university', 'courseStaff', 'material', 'file'] as $parameter) {
            Route::pattern($parameter, '[0-9]{1,18}');
        }

        if (config('margin.force_root_url') && config('app.url')) {
            // Pagination links must not echo whatever Host header the client sent.
            Paginator::currentPathResolver(fn () => URL::to(request()->path()));
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme(parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
        }

        if ($redirect = config('margin.mail_always_to')) {
            Mail::alwaysTo((string) $redirect);
        }

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
