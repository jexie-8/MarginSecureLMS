<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'account_status' => $this->when($request->user()?->can('viewStatus', $this->resource), $this->account_status),
            'university_id' => $this->university_id,
            'department_id' => $this->department_id,
            'last_login_at' => $this->when($request->user()?->can('viewStatus', $this->resource), $this->last_login_at),
        ];
    }
}
