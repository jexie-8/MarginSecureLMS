<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditAuthorizationFailures
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $response = $next($request);

            if ($response->getStatusCode() === 403) {
                $this->recordDenial($request);
            }

            return $response;
        } catch (Throwable $exception) {
            if ($exception instanceof AuthorizationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403)) {
                $this->recordDenial($request);
            }

            throw $exception;
        }
    }

    private function recordDenial(Request $request): void
    {
        $this->audit->record($request->user(), 'authorization.denied', null, [
            'method' => $request->method(),
            'path' => $request->path(),
            'route_name' => $request->route()?->getName(),
        ], $request);
    }
}
