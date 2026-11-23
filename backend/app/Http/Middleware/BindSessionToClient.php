<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ties a session to the browser that signed in. If the same session cookie
 * later arrives from a different browser or tool (a different User-Agent), the
 * session is ended and the event is audited. This blunts a stolen or copied
 * cookie being replayed from another client.
 *
 * The IP address is deliberately not used: phones switch networks constantly
 * and would be logged out. Token (mobile) requests have no session and are
 * skipped.
 */
class BindSessionToClient
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->user() === null) {
            return $next($request);
        }

        $fingerprint = hash_hmac('sha256', (string) $request->userAgent(), (string) config('app.key'));
        $bound = $request->session()->get('client_fingerprint');

        if ($bound === null) {
            $request->session()->put('client_fingerprint', $fingerprint);

            return $next($request);
        }

        if (! hash_equals($bound, $fingerprint)) {
            $user = $request->user();
            $this->audit->record($user, 'session.client_mismatch', $user, [], $request);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
