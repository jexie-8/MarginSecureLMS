<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ProgramResource;
use App\Models\Department;
use App\Models\Program;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProgramController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Program::class);
        $query = Program::query()->with('departments')->orderBy('name')->orderBy('id');
        if (! $request->user()->isPlatformAdmin()) {
            $query->where('university_id', $request->user()->university_id);
        }

        return ProgramResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): ProgramResource
    {
        Gate::authorize('create', Program::class);
        $data = $request->validate([
            'university_id' => ['required', 'integer', 'exists:universities,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
        ]);
        $this->ensureUniversityVisible($request->user(), $data['university_id']);
        $this->validateDepartmentScope($data['department_ids'] ?? [], $data['university_id']);
        validator($data, ['code' => [Rule::unique('programs')->where('university_id', $data['university_id'])]])->validate();
        $program = DB::transaction(function () use ($data): Program {
            $program = Program::query()->create(collect($data)->except('department_ids')->all());
            $program->departments()->sync($this->pivotValues($data['department_ids'] ?? [], $program->university_id));

            return $program;
        });
        $audit->record($request->user(), 'program.created', $program, [], $request);

        return new ProgramResource($program->load('departments'));
    }

    public function show(Request $request, Program $program): ProgramResource
    {
        $this->ensureUniversityVisible($request->user(), $program->university_id);
        Gate::authorize('view', $program);

        return new ProgramResource($program->load('departments'));
    }

    public function update(Request $request, Program $program, AuditLogger $audit): ProgramResource
    {
        $this->ensureUniversityVisible($request->user(), $program->university_id);
        Gate::authorize('update', $program);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
        ]);
        $this->validateDepartmentScope($data['department_ids'] ?? [], $program->university_id);
        if (isset($data['code'])) {
            validator($data, ['code' => [Rule::unique('programs')->where('university_id', $program->university_id)->ignore($program)]])->validate();
        }
        DB::transaction(function () use ($program, $data): void {
            $program->update(collect($data)->except('department_ids')->all());
            if (array_key_exists('department_ids', $data)) {
                $program->departments()->sync($this->pivotValues($data['department_ids'], $program->university_id));
            }
        });
        $audit->record($request->user(), 'program.updated', $program, ['changed' => array_keys($data)], $request);

        return new ProgramResource($program->load('departments'));
    }

    /** @param array<int, int> $departmentIds */
    private function validateDepartmentScope(array $departmentIds, int $universityId): void
    {
        abort_if(Department::query()->whereKey($departmentIds)->where('university_id', '!=', $universityId)->exists(), 422, 'Departments must belong to the program university.');
    }

    /** @param array<int, int> $ids @return array<int, array{university_id: int}> */
    private function pivotValues(array $ids, int $universityId): array
    {
        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => ['university_id' => $universityId]])->all();
    }
}
