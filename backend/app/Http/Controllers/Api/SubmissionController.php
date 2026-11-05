<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SubmissionResource;
use App\Models\Assignment;
use App\Models\Submission;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Models\SubmissionFile;
use App\Services\FileSecurityScanner;

class SubmissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Submission::class);
        $actor = $request->user();
        $query = Submission::query()->with(['assignment.course', 'student', 'grade', 'files'])->latest('submitted_at')->orderByDesc('id');
        $request->whenFilled('assignment_id', fn (int $assignmentId) => $query->where('assignment_id', $assignmentId));
        if ($actor->role === 'student') {
            $query->where('student_id', $actor->id);
        } elseif (in_array($actor->role, ['lecturer', 'teaching_assistant'], true)) {
            $query->whereHas('assignment.course.staffAssignments', fn ($q) => $q->where('user_id', $actor->id));
        } else {
            abort(403, 'Administrators cannot access private submissions.');
        }

        return SubmissionResource::collection($query->paginate($this->perPage($request)));
    }

    public function store(Request $request, Assignment $assignment, AuditLogger $audit, FileSecurityScanner $scanner): SubmissionResource
    {
        $assignment->loadMissing('course');
        $this->ensureUniversityVisible($request->user(), $assignment->course->university_id);
        Gate::authorize('create', [Submission::class, $assignment]);
        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:100000', 'required_without:files'],
            'files' => ['nullable', 'array', 'max:5', 'required_without:content'],
            'files.*' => ['file', 'max:25600'],
        ]);
        foreach ($request->file('files', []) as $file) {
            $scanner->validate($file);
        }
        $submission = DB::transaction(function () use ($request, $assignment, $data): Submission {
            Assignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $attempt = Submission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_id', $request->user()->id)
                ->max('attempt_number') + 1;

            return Submission::query()->create([
                'assignment_id' => $assignment->id,
                'student_id' => $request->user()->id,
                'attempt_number' => $attempt,
                'content' => $data['content'] ?? '',
                'submitted_at' => now(),
            ]);
        });
        foreach ($request->file('files', []) as $file) {
            $path = $file->store('submissions/'.$submission->id, 'local');
            SubmissionFile::query()->create([
                'submission_id' => $submission->id, 'path' => $path,
                'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            ]);
        }
        $audit->record($request->user(), 'submission.created', $submission, ['attempt_number' => $submission->attempt_number], $request);

        return new SubmissionResource($submission->load(['assignment.course', 'student', 'files']));
    }

    public function show(Request $request, Submission $submission): SubmissionResource
    {
        $submission->loadMissing(['assignment.course', 'student', 'grade', 'files']);
        $this->ensureUniversityVisible($request->user(), $submission->assignment->course->university_id);
        Gate::authorize('view', $submission);

        return new SubmissionResource($submission);
    }
}
