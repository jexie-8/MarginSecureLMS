<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
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
    public function view(User $user, Department $department): bool
    {
        return $user->isPlatformAdmin() || $user->university_id === $department->university_id;
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
    public function update(User $user, Department $department): bool
    {
        return $user->managesUniversity($department->university_id)
            || ($user->isDepartmentAdmin() && $user->department_id === $department->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Department $department): bool
    {
        return $user->managesUniversity($department->university_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Department $department): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Department $department): bool
    {
        return false;
    }
}
