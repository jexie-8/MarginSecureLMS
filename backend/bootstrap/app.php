<?php

use App\Http\Middleware\AuditAuthorizationFailures;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireBrowserContext;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        // This is an API: an anonymous request gets a 401, never a redirect to a
        // login page that does not exist (which used to surface as a 500).
        $middleware->redirectGuestsTo(fn () => null);

        // Route-model binding runs queries. With Row-Level Security those queries
        // only see the user's rows once the database knows who is asking, so the
        // context must be set BEFORE bindings are resolved.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\SetDatabaseUserContext::class,
        );

        $middleware->statefulApi();
        $middleware->appendToGroup('api', [
            RequireBrowserContext::class,
            SanitizeInput::class,
            SecurityHeaders::class,
            AuditAuthorizationFailures::class,
        ]);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Laravel's default messages name internal classes ("No query results for
        // model [App\Models\Course] 5") and list a route's supported methods.
        // Give attackers nothing to learn from.
        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();
        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => $isApi($request)
            ? response()->json(['message' => 'Not found.'], 404)
            : null);
        // Malformed input that reaches the database as the wrong type or size
        // (SQLSTATE class 22) is a client mistake, not a server fault.
        $exceptions->render(fn (\Illuminate\Database\QueryException $e, Request $request) => $isApi($request) && str_starts_with((string) $e->getCode(), '22')
            ? response()->json(['message' => 'Invalid input.'], 422)
            : null);
        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $isApi($request)
            ? response()->json(['message' => 'Method not allowed.'], 405)
            : null);
    })->create();
