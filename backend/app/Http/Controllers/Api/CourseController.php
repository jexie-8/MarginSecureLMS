<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CourseResource;
use App\Models\Course;
use App\Models\Program;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Course::class);
        $actor = $request->user();
        $query = Course::query()->with('programs')->orderBy('name')->orderBy('id');
        if (! $actor->isPlatformAdmin()) {
            $query->where('university_id', $actor->university_id);
        }
        if ($actor->role === 'student') {
            $query->whereHas('enrollments', fn ($q) => $q->where('student_id', $actor->id)->where('status', 'active'));
        } elseif (in_array($actor->role, ['lecturer', 'teaching_assistant'], true)) {
            $query->whereHas('staffAssignments', fn ($q) => $q->where('user_id', $actor->id));
        }

        return CourseResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): CourseResource
    {
        Gate::authorize('create', Course::class);
        $data = $this->validatedData($request);
        $this->ensureUniversityVisible($request->user(), $data['university_id']);
        $this->validateProgramScope($data['program_ids'] ?? [], $data['university_id']);
        validator($data, ['code' => [Rule::unique('courses')->where('university_id', $data['university_id'])]])->validate();
        $course = DB::transaction(function () use ($data): Course {
            $course = Course::query()->create(collect($data)->except('program_ids')->all());
            $course->programs()->sync($this->pivotValues($data['program_ids'] ?? [], $course->university_id));

            return $course;
        });
        $audit->record($request->user(), 'course.created', $course, [], $request);

        return new CourseResource($course->load('programs'));
    }

    public function show(Request $request, Course $course): CourseResource
    {
        $this->ensureUniversityVisible($request->user(), $course->university_id);
        Gate::authorize('view', $course);

        return new CourseResource($course->load('programs'));
    }

    public function update(Request $request, Course $course, AuditLogger $audit): CourseResource
    {
        $this->ensureUniversityVisible($request->user(), $course->university_id);
        Gate::authorize('update', $course);
        $data = $this->validatedData($request, true);
        $this->validateProgramScope($data['program_ids'] ?? [], $course->university_id);
        if (isset($data['code'])) {
            validator($data, ['code' => [Rule::unique('courses')->where('university_id', $course->university_id)->ignore($course)]])->validate();
        }
        DB::transaction(function () use ($course, $data): void {
            $course->update(collect($data)->except(['program_ids', 'university_id'])->all());
            if (array_key_exists('program_ids', $data)) {
                $course->programs()->sync($this->pivotValues($data['program_ids'], $course->university_id));
            }
        });
        $audit->record($request->user(), 'course.updated', $course, ['changed' => array_keys($data)], $request);

        return new CourseResource($course->load('programs'));
    }

    /** @return array<string, mixed> */
    private function validatedData(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'university_id' => $updating ? ['prohibited'] : ['required', 'integer', 'exists:universities,id'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'code' => [$updating ? 'sometimes' : 'required', 'string', 'max:50', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:10000'],
            'program_ids' => ['sometimes', 'array'],
            'program_ids.*' => ['integer', 'distinct', 'exists:programs,id'],
        ]);
    }

    /** @param array<int, int> $programIds */
    private function validateProgramScope(array $programIds, int $universityId): void
    {
        abort_if(Program::query()->whereKey($programIds)->where('university_id', '!=', $universityId)->exists(), 422, 'Programs must belong to the course university.');
    }

    /** @param array<int, int> $ids @return array<int, array{university_id: int}> */
    private function pivotValues(array $ids, int $universityId): array
    {
        return collect($ids)->mapWithKeys(fn (int $id): array => [$id => ['university_id' => $universityId]])->all();
    }
}
