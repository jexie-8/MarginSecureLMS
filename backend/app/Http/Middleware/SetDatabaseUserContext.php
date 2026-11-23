<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells PostgreSQL which user is making the request so Row-Level Security
 * policies can filter rows. The values are cleared when the request ends so
 * they can never leak onto the next request that reuses the connection.
 */
class SetDatabaseUserContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || DB::getDriverName() !== 'pgsql') {
            return $next($request);
        }

        self::apply($user->getKey(), $user->role, $user->university_id);

        try {
            return $next($request);
        } finally {
            self::clear();
        }
    }

    public static function apply(int|string $userId, ?string $role, int|string|null $universityId): void
    {
        DB::select(
            "select set_config('app.user_id', ?, false), set_config('app.user_role', ?, false), set_config('app.university_id', ?, false)",
            [(string) $userId, (string) $role, (string) $universityId],
        );
    }

    public static function clear(): void
    {
        DB::select(
            "select set_config('app.user_id', '', false), set_config('app.user_role', '', false), set_config('app.university_id', '', false)",
        );
    }
}
