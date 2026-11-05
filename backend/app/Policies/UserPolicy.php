<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['platform_admin', 'university_admin', 'department_admin'], true);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $user->id === $model->id
            || $user->isPlatformAdmin()
            || ($user->isUniversityAdmin() && $user->university_id === $model->university_id)
            || ($user->isDepartmentAdmin() && $user->department_id === $model->department_id);
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
    public function update(User $user, User $model): bool
    {
        return $user->isPlatformAdmin()
            || ($user->isUniversityAdmin() && $user->university_id === $model->university_id)
            || ($user->isDepartmentAdmin() && $user->department_id === $model->department_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function viewStatus(User $user, User $model): bool
    {
        return $user->id === $model->id || $this->update($user, $model);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
