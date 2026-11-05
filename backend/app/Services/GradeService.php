<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GradeService
{
    public function __construct(
        private GradeValidationService $validator
    ) {}

    public function saveGrade(
        User $actor,
        Submission $submission,
        float $score,
        ?string $feedback = null
    ): Grade {
        return DB::transaction(function () use (
            $actor, $submission, $score, $feedback
        ) {
            $submission = Submission::query()
                ->lockForUpdate()
                ->findOrFail($submission->id);

            $grade = $submission->grade()
                ->lockForUpdate()
                ->first();

            Gate::forUser($actor)->authorize(
                $grade ? 'update' : 'create',
                $grade ?? [Grade::class, $submission]
            );

            $this->validator->validateScore(
                $submission,
                $score
            );

            if ($grade === null) {
                $grade = new Grade();
                $grade->submission_id = $submission->id;
            }

            $grade->score = $score;
            $grade->feedback = $feedback;
            $grade->graded_by = $actor->id;
            $grade->save();

            return $grade;
        });
    }

    public function publishGrade(
        User $actor,
        Grade $grade
    ): Grade {
        return DB::transaction(function () use ($actor, $grade) {
            $grade = Grade::query()
                ->lockForUpdate()
                ->findOrFail($grade->id);

            Gate::forUser($actor)->authorize(
                'publish',
                $grade
            );

            $grade->published_by = $actor->id;
            $grade->published_at = now();
            $grade->save();

            return $grade;
        });
    }
}
