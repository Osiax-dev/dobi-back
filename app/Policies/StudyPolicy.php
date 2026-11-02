<?php

namespace App\Policies;

use App\Models\Study;
use App\Models\User;
use App\Support\Roles;

class StudyPolicy
{
    public function view(User $user, Study $study): bool
    {
        return $user->roleIn($study->organization_id) !== null;
    }

    /** Modifier l'étude et son questionnaire. */
    public function update(User $user, Study $study): bool
    {
        return in_array($user->roleIn($study->organization_id), [Roles::OWNER, Roles::ADMIN, Roles::EDITOR], true);
    }

    /** Publier, suspendre, clôturer, archiver. */
    public function publish(User $user, Study $study): bool
    {
        return in_array($user->roleIn($study->organization_id), [Roles::OWNER, Roles::ADMIN], true);
    }

    /** Statistiques et exports. */
    public function viewResults(User $user, Study $study): bool
    {
        return in_array($user->roleIn($study->organization_id), [Roles::OWNER, Roles::ADMIN, Roles::ANALYST], true);
    }

    /** Voir les données d'identité des participants (études identifiées). */
    public function viewIdentity(User $user, Study $study): bool
    {
        return in_array($user->roleIn($study->organization_id), [Roles::OWNER, Roles::ADMIN], true);
    }
}
