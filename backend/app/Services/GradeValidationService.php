<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Validation\ValidationException;

class GradeValidationService
{
    public function validateScore(
        Submission $submission,
        float $score
    ): void {
        $submission->loadMissing('assignment');

        $maxScore = (float) $submission->assignment->max_score;

        if (
            !is_finite($score) ||
            $score < 0 ||
            $score > $maxScore
        ) {
            throw ValidationException::withMessages([
                'score' => [
                    "Score must be between 0 and {$maxScore}.",
                ],
            ]);
        }
    }
}
