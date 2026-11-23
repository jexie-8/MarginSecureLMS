<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\PasswordPolicy;

class PasswordController extends Controller
{
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);
        $request->user()->forceFill(['password' => $data['password']])->save();
        $otherSessions = DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id);
        if ($request->hasSession()) {
            $otherSessions->where('id', '!=', $request->session()->getId());
        }
        $otherSessions->delete();
        // Mobile devices: revoke every API token except the one making this request.
        $currentToken = $request->user()->currentAccessToken();
        $request->user()->tokens()
            ->when($currentToken instanceof \Laravel\Sanctum\PersonalAccessToken, fn ($q) => $q->where('id', '!=', $currentToken->id))
            ->delete();
        $audit->record($request->user(), 'password.changed', $request->user(), [], $request);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
