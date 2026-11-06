<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SessionManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->session()->getId();
        $sessions = DB::table($this->table())
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($session) => [
                'id' => $this->fingerprint($session->id),
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                'current' => hash_equals($currentId, $session->id),
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function destroy(Request $request, string $session, AuditLogger $audit): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:web']]);
        $currentId = $request->session()->getId();
        $target = DB::table($this->table())
            ->where('user_id', $request->user()->id)
            ->get(['id'])
            ->first(fn ($candidate) => hash_equals($session, $this->fingerprint($candidate->id)));

        abort_unless($target !== null, 404);
        abort_if(hash_equals($currentId, $target->id), 422, 'Sign out from the current session using the normal sign-out action.');

        DB::table($this->table())->where('id', $target->id)->where('user_id', $request->user()->id)->delete();
        $audit->record($request->user(), 'session.revoked', $request->user(), [
            'session_fingerprint' => $session,
        ], $request);

        return response()->json(['message' => 'Session revoked successfully.']);
    }

    private function fingerprint(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
