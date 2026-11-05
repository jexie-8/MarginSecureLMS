<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'name',
    'code',
])]
class University extends Model
{
    /**
     * Get all users belonging to this university.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get all departments belonging to this university.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
    /**
     * Get all programs belonging to this university.
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
