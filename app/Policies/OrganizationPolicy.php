<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Support\Roles;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) !== null;
    }

    /** Tableau de bord, liste des études, des membres et des participants : tous les rôles sauf l'enquêteur. */
    public function viewWorkspace(User $user, Organization $organization): bool
    {
        $role = $user->roleIn($organization);

        return $role !== null && $role !== Roles::SURVEYOR;
    }

    public function update(User $user, Organization $organization): bool
    {
        return in_array($user->roleIn($organization), [Roles::OWNER, Roles::ADMIN], true);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->update($user, $organization);
    }

    public function createStudy(User $user, Organization $organization): bool
    {
        return in_array($user->roleIn($organization), [Roles::OWNER, Roles::ADMIN, Roles::EDITOR], true);
    }
}
