<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramResource extends JsonResource
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
            'university_id' => $this->university_id,
            'name' => $this->name,
            'code' => $this->code,
            'department_ids' => $this->whenLoaded('departments', fn () => $this->departments->modelKeys()),
        ];
    }
}
