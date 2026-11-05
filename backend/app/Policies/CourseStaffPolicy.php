<?php

namespace App\Policies;

use App\Models\CourseStaff;
use App\Models\User;

class CourseStaffPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->account_status === 'active'
            && in_array($user->role, [
                'platform_admin',
                'university_admin',
                'department_admin',
                'lecturer',
                'teaching_assistant',
            ], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CourseStaff $courseStaff): bool
    {
        $courseStaff->loadMissing('course');

        return $user->id === $courseStaff->user_id
            || $user->managesUniversity($courseStaff->course->university_id);
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
    public function update(User $user, CourseStaff $courseStaff): bool
    {
        $courseStaff->loadMissing('course');

        return $user->managesUniversity($courseStaff->course->university_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CourseStaff $courseStaff): bool
    {
        return $this->update($user, $courseStaff);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CourseStaff $courseStaff): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CourseStaff $courseStaff): bool
    {
        return false;
    }
}
