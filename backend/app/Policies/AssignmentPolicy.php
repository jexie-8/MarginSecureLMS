<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

class AssignmentPolicy
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
    public function view(User $user, Assignment $assignment): bool
    {
        $assignment->loadMissing('course');

        if ($user->managesUniversity($assignment->course->university_id)) {
            return true;
        }

        if ($user->university_id !== $assignment->course->university_id) {
            return false;
        }

        if ($user->role === 'student') {
            return $assignment->published_at !== null
                && $user->enrollments()->where('course_id', $assignment->course_id)->where('status', 'active')->exists();
        }

        return $user->staffAssignments()->where('course_id', $assignment->course_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === 'lecturer';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Assignment $assignment): bool
    {
        return $user->role === 'lecturer'
            && $user->university_id === $assignment->course()->value('university_id')
            && $user->staffAssignments()->where('course_id', $assignment->course_id)->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Assignment $assignment): bool
    {
        return $this->update($user, $assignment) && ! $assignment->submissions()->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Assignment $assignment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Assignment $assignment): bool
    {
        return false;
    }
}
