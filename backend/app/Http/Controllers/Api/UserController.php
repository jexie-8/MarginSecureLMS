<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AuditLogResource;
use App\Http\Resources\Api\UserResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ROLES = [
        'platform_admin',
        'university_admin',
        'department_admin',
        'lecturer',
        'teaching_assistant',
        'student',
    ];

    private const STATUSES = ['active', 'suspended'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);
        $actor = $request->user();
        $query = User::query()->orderBy('name')->orderBy('id');
        if (! $actor->isPlatformAdmin()) {
            $query->where('university_id', $actor->university_id);
        }
        if ($actor->isDepartmentAdmin()) {
            $query->where('department_id', $actor->department_id);
        }
        $request->whenFilled('role', fn (string $role) => $query->where('role', $role));
        $request->whenFilled('account_status', fn (string $status) => $query->where('account_status', $status));
        $request->whenFilled('search', function (string $search) use ($query): void {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"));
        });

        return UserResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): UserResource
    {
        Gate::authorize('create', User::class);
        $data = $request->validate($this->rules());
        $data = $this->constrainAdministrativeFields($request->user(), $data);
        $this->validateDepartmentScope($data['department_id'] ?? null, $data['university_id'] ?? null);
        $user = User::query()->create($data);
        $audit->record($request->user(), 'user.created', $user, ['role' => $user->role], $request);

        return new UserResource($user);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->ensureUserVisible($request->user(), $user);
        Gate::authorize('view', $user);

        return new UserResource($user);
    }

    public function update(Request $request, User $user, AuditLogger $audit): UserResource
    {
        $this->ensureUserVisible($request->user(), $user);
        Gate::authorize('update', $user);
        $data = $request->validate($this->rules($user));
        unset($data['password']);
        $data = $this->constrainAdministrativeFields($request->user(), $data + [
            'role' => $user->role,
            'university_id' => $user->university_id,
            'department_id' => $user->department_id,
        ]);
        $this->validateDepartmentScope($data['department_id'] ?? null, $data['university_id'] ?? null);
        $user->update($data);
        if (($data['account_status'] ?? null) === 'suspended') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $audit->record($request->user(), 'user.updated', $user, ['changed' => array_keys($data)], $request);

        return new UserResource($user->fresh());
    }

    public function auditLogs(Request $request): AnonymousResourceCollection
    {
        abort_unless(in_array($request->user()->role, ['platform_admin', 'university_admin'], true), 403);
        $query = AuditLog::query()->latest('created_at')->orderByDesc('id');
        if (! $request->user()->isPlatformAdmin()) {
            $query->whereHas('actor', fn ($q) => $q->where('university_id', $request->user()->university_id));
        }

        return AuditLogResource::collection($query->paginate($this->perPage($request)));
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?User $user = null): array
    {
        return [
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => $user
                ? ['prohibited']
                : ['required', 'string', 'min:12', 'max:255'],
            'role' => [$user ? 'sometimes' : 'required', Rule::in(self::ROLES)],
            'account_status' => ['sometimes', Rule::in(self::STATUSES)],
            'university_id' => ['nullable', 'integer', 'exists:universities,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function constrainAdministrativeFields(User $actor, array $data): array
    {
        if ($actor->isPlatformAdmin()) {
            return $data;
        }

        $allowedRoles = $actor->isUniversityAdmin()
            ? ['department_admin', 'lecturer', 'teaching_assistant', 'student']
            : ['lecturer', 'teaching_assistant', 'student'];
        abort_unless(in_array($data['role'], $allowedRoles, true), 403);
        $data['university_id'] = $actor->university_id;
        if ($actor->isDepartmentAdmin()) {
            $data['department_id'] = $actor->department_id;
        }

        return $data;
    }

    private function validateDepartmentScope(?int $departmentId, ?int $universityId): void
    {
        if ($departmentId === null) {
            return;
        }
        abort_unless(Department::query()->whereKey($departmentId)->where('university_id', $universityId)->exists(), 422, 'Department must belong to the selected university.');
    }

    private function ensureUserVisible(User $actor, User $target): void
    {
        if ($actor->isPlatformAdmin() || $actor->id === $target->id) {
            return;
        }
        abort_if($actor->university_id !== $target->university_id, 404);
        abort_if($actor->isDepartmentAdmin() && $actor->department_id !== $target->department_id, 404);
    }
}
