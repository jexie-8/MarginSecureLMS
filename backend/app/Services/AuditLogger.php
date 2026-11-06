<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /** @param array<string, mixed> $metadata */
    public function record(
        ?User $actor,
        string $event,
        ?Model $auditable,
        array $metadata,
        Request $request,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
