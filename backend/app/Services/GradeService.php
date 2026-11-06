<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\GradeRevision;
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
        ?string $feedback = null,
        ?array $rubricScores = null,
        bool $rubricProvided = false,
        ?string $changeReason = null,
    ): Grade {
        return DB::transaction(function () use (
            $actor, $submission, $score, $feedback, $rubricScores, $rubricProvided, $changeReason
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

            $publishedUpdate = $grade !== null && $grade->published_at !== null;
            if ($publishedUpdate && trim((string) $changeReason) === '') {
                throw ValidationException::withMessages([
                    'change_reason' => ['A reason is required when changing a published grade.'],
                ]);
            }

            if ($publishedUpdate) {
                GradeRevision::query()->create([
                    'grade_id' => $grade->id,
                    'changed_by' => $actor->id,
                    'reason' => trim((string) $changeReason),
                    'previous_score' => $grade->score,
                    'new_score' => $score,
                    'previous_feedback' => $grade->feedback,
                    'new_feedback' => $feedback,
                    'previous_rubric_scores' => $grade->rubric_scores,
                    'new_rubric_scores' => $rubricProvided ? $rubricScores : $grade->rubric_scores,
                ]);
            }

            if ($grade === null) {
                $grade = new Grade;
                $grade->submission_id = $submission->id;
            }

            $grade->score = $score;
            $grade->feedback = $feedback;
            $grade->graded_by = $actor->id;
            if ($rubricProvided) {
                $grade->rubric_scores = $rubricScores;
            }
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
