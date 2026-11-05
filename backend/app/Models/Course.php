<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'university_id',
    'name',
    'code',
    'description',
])]
class Course extends Model
{
    /**
     * The university that owns this course.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(CourseStaff::class);
    }
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(
            Program::class,
            'course_program'
        )->withPivot('university_id')->withTimestamps();
    }
    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
