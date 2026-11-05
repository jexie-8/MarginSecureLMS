<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'university_id',
    'name',
    'code',
])]
class Program extends Model
{
    /**
     * The university this program belongs to.
     */
    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'course_program'
        )->withPivot('university_id')->withTimestamps();
    }
    /**
     * Departments associated with this program.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'program_department'
        )->withTimestamps();
    }
}
