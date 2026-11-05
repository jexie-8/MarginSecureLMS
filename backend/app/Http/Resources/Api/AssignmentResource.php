<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
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
            'created_by' => $this->created_by,
            'title' => $this->title,
            'description' => $this->description,
            'max_score' => $this->max_score,
            'due_at' => $this->due_at,
            'published_at' => $this->published_at,
            'course' => new CourseResource($this->whenLoaded('course')),
        ];
    }
}
