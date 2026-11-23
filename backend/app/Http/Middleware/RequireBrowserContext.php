<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie-session requests must genuinely come from a web page of our own app.
 *
 * Browsers attach a "Sec-Fetch-Site" header to every request and scripts cannot
 * change it. This middleware refuses cookie-session requests where that header
 * is missing or says the request came from another site, which stops
 * cross-site request forgery and casual use of tools such as Postman or curl
 * against the session API.
 *
 * This is deliberate friction, not a guarantee: a determined person can send
 * the header by hand. Real protection comes from the server checking identity
 * and permissions on every request, whatever client sent it. Mobile apps use
 * bearer tokens and are not affected.
 */
class RequireBrowserContext
{
    private const ALLOWED_SITES = ['same-origin', 'same-site'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('margin.require_browser_context', true)) {
            return $next($request);
        }

        // Only requests that claim to come from our web app use cookie sessions.
        // Token (mobile) and anonymous requests are handled elsewhere.
        if ($request->bearerToken() === null && EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            $site = strtolower((string) $request->headers->get('Sec-Fetch-Site', ''));

            if (! in_array($site, self::ALLOWED_SITES, true)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        return $next($request);
    }
}
