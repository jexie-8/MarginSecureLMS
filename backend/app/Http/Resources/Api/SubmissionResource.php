<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubmissionResource extends JsonResource
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
            'assignment_id' => $this->assignment_id,
            'student_id' => $this->student_id,
            'attempt_number' => $this->attempt_number,
            'content' => $this->content,
            'submitted_at' => $this->submitted_at,
            'student' => new UserResource($this->whenLoaded('student')),
            'assignment' => new AssignmentResource($this->whenLoaded('assignment')),
            'grade' => $this->when(
                $this->relationLoaded('grade')
                    && $this->grade !== null
                    && $request->user()?->can('view', $this->grade),
                fn () => new GradeResource($this->grade),
            ),
        ];
    }
}
