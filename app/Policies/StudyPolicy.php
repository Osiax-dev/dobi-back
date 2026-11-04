<?php

namespace App\Policies;

use App\Models\Study;
use App\Models\User;
use App\Support\Roles;

class StudyPolicy
{
    public function view(User $user, Study $study): bool
    {
        $role = $user->roleIn($study->organization_id);

        // L'enquêteur n'a accès qu'à la collecte, pas au contenu ni à l'historique des études.
        return $role !== null && $role !== Roles::SURVEYOR;
    }

    /** Collecter des réponses (étude publiée) : l'enquêteur et les rôles qui construisent ou gèrent l'étude. */
    public function collect(User $user, Study $study): bool
    {
        $role = $user->roleIn($study->organization_id);

        if ($role === Roles::SURVEYOR) {
            $assigned = $study->assignedSurveyorIds();

            return $assigned === [] || in_array($user->id, $assigned, true);
        }

        return in_array($role, [Roles::OWNER, Roles::ADMIN, Roles::EDITOR], true);
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
