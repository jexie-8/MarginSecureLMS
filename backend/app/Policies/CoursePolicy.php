<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->account_status === 'active';
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Course $course): bool
    {
        if ($user->isPlatformAdmin() || $user->managesUniversity($course->university_id)) {
            return true;
        }

        if ($user->university_id !== $course->university_id) {
            return false;
        }

        if ($user->role === 'student') {
            return $user->enrollments()->where('course_id', $course->id)->where('status', 'active')->exists();
        }

        return $user->staffAssignments()->where('course_id', $course->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['platform_admin', 'university_admin'], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Course $course): bool
    {
        return $user->managesUniversity($course->university_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Course $course): bool
    {
        return $user->managesUniversity($course->university_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Course $course): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Course $course): bool
    {
        return false;
    }
}
