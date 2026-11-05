<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseStaffResource extends JsonResource
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
            'user_id' => $this->user_id,
            'course' => new CourseResource($this->whenLoaded('course')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
