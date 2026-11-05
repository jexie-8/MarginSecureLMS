<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'university_id',
    'name',
    'code',
])]
class Department extends Model
{
    /**
     * The university this department belongs to.
     */
    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }

    /**
     * Users assigned to this department.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Academic programs associated with this department.
     */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(
            Program::class,
            'program_department'
        )->withPivot('university_id')->withTimestamps();
    }
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'program_department'
        )->withPivot('university_id')->withTimestamps();
    }
}
