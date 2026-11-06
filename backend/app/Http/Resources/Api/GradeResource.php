<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_id' => $this->submission_id,
            'graded_by' => $this->graded_by,
            'score' => $this->score,
            'feedback' => $this->feedback,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at,
            'rubric_scores' => $this->rubric_scores,
            'revision_history' => $this->when(
                $request->user()?->role !== 'student' && $this->relationLoaded('revisions'),
                fn () => $this->revisions->map(fn ($revision) => [
                    'id' => $revision->id,
                    'changed_by' => $revision->changed_by,
                    'changed_by_name' => $revision->changer?->name,
                    'reason' => $revision->reason,
                    'previous_score' => $revision->previous_score,
                    'new_score' => $revision->new_score,
                    'previous_feedback' => $revision->previous_feedback,
                    'new_feedback' => $revision->new_feedback,
                    'previous_rubric_scores' => $revision->previous_rubric_scores,
                    'new_rubric_scores' => $revision->new_rubric_scores,
                    'created_at' => $revision->created_at,
                ]),
            ),
        ];
    }
}
