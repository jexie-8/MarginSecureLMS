<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
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
    public function view(User $user, Enrollment $enrollment): bool
    {
        $enrollment->loadMissing('course', 'student');

        return $user->id === $enrollment->student_id
            || $user->managesUniversity($enrollment->course->university_id)
            || ($user->isDepartmentAdmin() && $user->department_id === $enrollment->student->department_id)
            || $user->staffAssignments()->where('course_id', $enrollment->course_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['platform_admin', 'university_admin', 'department_admin'], true);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Enrollment $enrollment): bool
    {
        $enrollment->loadMissing('course', 'student');

        return $user->managesUniversity($enrollment->course->university_id)
            || ($user->isDepartmentAdmin() && $user->department_id === $enrollment->student->department_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Enrollment $enrollment): bool
    {
        return $this->update($user, $enrollment);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Enrollment $enrollment): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Enrollment $enrollment): bool
    {
        return false;
    }
}
