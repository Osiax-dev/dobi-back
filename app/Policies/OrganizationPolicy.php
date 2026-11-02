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
