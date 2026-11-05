<?php

namespace App\Policies;

use App\Models\University;
use App\Models\User;

class UniversityPolicy
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
    public function view(User $user, University $university): bool
    {
        return $user->isPlatformAdmin() || $user->university_id === $university->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, University $university): bool
    {
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, University $university): bool
    {
        return $user->isPlatformAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, University $university): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, University $university): bool
    {
        return false;
    }
}
