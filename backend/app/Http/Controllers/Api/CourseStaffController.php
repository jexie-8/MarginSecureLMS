<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CourseStaffResource;
use App\Models\Course;
use App\Models\CourseStaff;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CourseStaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CourseStaff::class);
        $actor = $request->user();
        $query = CourseStaff::query()->with(['course', 'user'])->orderBy('course_id')->orderBy('id');
        if (! $actor->isPlatformAdmin()) {
            $query->whereHas('course', fn ($q) => $q->where('university_id', $actor->university_id));
        }
        if (in_array($actor->role, ['lecturer', 'teaching_assistant'], true)) {
            $query->where('user_id', $actor->id);
        }

        return CourseStaffResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, AuditLogger $audit): CourseStaffResource
    {
        Gate::authorize('create', CourseStaff::class);
        $data = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $course = Course::query()->findOrFail($data['course_id']);
        $staff = User::query()->findOrFail($data['user_id']);
        $this->ensureUniversityVisible($request->user(), $course->university_id);
        abort_unless(in_array($staff->role, ['lecturer', 'teaching_assistant'], true) && $staff->university_id === $course->university_id, 422, 'Staff and course must belong to the same university.');
        $assignment = CourseStaff::query()->firstOrCreate($data);
        $audit->record($request->user(), 'course_staff.assigned', $assignment, [], $request);

        return new CourseStaffResource($assignment->load(['course', 'user']));
    }

    public function destroy(Request $request, CourseStaff $courseStaff, AuditLogger $audit): JsonResponse
    {
        $courseStaff->loadMissing('course');
        $this->ensureUniversityVisible($request->user(), $courseStaff->course->university_id);
        Gate::authorize('delete', $courseStaff);
        $audit->record($request->user(), 'course_staff.removed', $courseStaff, [], $request);
        $courseStaff->delete();

        return response()->json(status: 204);
    }
}
