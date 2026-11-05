<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollmentResource extends JsonResource
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
            'course_id' => $this->course_id,
            'student_id' => $this->student_id,
            'status' => $this->status,
            'enrolled_at' => $this->enrolled_at,
            'course' => new CourseResource($this->whenLoaded('course')),
            'student' => new UserResource($this->whenLoaded('student')),
        ];
    }
}
