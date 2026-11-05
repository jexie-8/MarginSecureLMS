<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
    }

    protected function ensureUniversityVisible(User $actor, ?int $universityId): void
    {
        abort_if(
            $actor->role !== 'platform_admin' && $actor->university_id !== $universityId,
            404,
        );
    }
}
