<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a browser session a fixed time after sign-in, however active it is.
 *
 * Laravel's own session lifetime is an idle timeout: it resets on every
 * request, so a stolen cookie that is used regularly would never expire. This
 * adds a hard limit (SESSION_ABSOLUTE_MINUTES, default 12 hours) after which
 * the user must sign in again. Mobile tokens have their own fixed expiry.
 */
class EnforceAbsoluteSessionLifetime
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->user() === null) {
            return $next($request);
        }

        $limit = (int) config('margin.session_absolute_minutes', 720) * 60;
        $started = $request->session()->get('session_started_at');

        if ($started === null) {
            $request->session()->put('session_started_at', now()->timestamp);

            return $next($request);
        }

        if ($limit > 0 && now()->timestamp - (int) $started > $limit) {
            $user = $request->user();
            $this->audit->record($user, 'session.absolute_timeout', $user, [], $request);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
