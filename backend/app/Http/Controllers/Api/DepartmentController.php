<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DepartmentResource;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Department::class);
        $query = Department::query()->orderBy('name')->orderBy('id');
        if (! $request->user()->isPlatformAdmin()) {
            $query->where('university_id', $request->user()->university_id);
        }

        return DepartmentResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): DepartmentResource
    {
        Gate::authorize('create', Department::class);
        $data = $request->validate([
            'university_id' => ['required', 'integer', 'exists:universities,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash'],
        ]);
        $this->ensureUniversityVisible($request->user(), $data['university_id']);
        validator($data, ['code' => [Rule::unique('departments')->where('university_id', $data['university_id'])]])->validate();
        $department = Department::query()->create($data);
        $audit->record($request->user(), 'department.created', $department, [], $request);

        return new DepartmentResource($department);
    }

    public function show(Request $request, Department $department): DepartmentResource
    {
        $this->ensureUniversityVisible($request->user(), $department->university_id);
        Gate::authorize('view', $department);

        return new DepartmentResource($department);
    }

    public function update(Request $request, Department $department, AuditLogger $audit): DepartmentResource
    {
        $this->ensureUniversityVisible($request->user(), $department->university_id);
        Gate::authorize('update', $department);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash'],
        ]);
        if (isset($data['code'])) {
            validator($data, ['code' => [Rule::unique('departments')->where('university_id', $department->university_id)->ignore($department)]])->validate();
        }
        $department->update($data);
        $audit->record($request->user(), 'department.updated', $department, ['changed' => array_keys($data)], $request);

        return new DepartmentResource($department);
    }
}
