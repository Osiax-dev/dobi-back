<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use App\Support\Roles;

class ReportPolicy
{
    /** Consulter et modifier : mêmes droits que les résultats de l'étude (propriétaire, administrateur, analyste). */
    public function view(User $user, Report $report): bool
    {
        return $user->can('viewResults', $report->study);
    }

    public function update(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }

    /** Supprimer : propriétaire et administrateurs, ou l'auteur du rapport tant qu'il a accès aux résultats. */
    public function delete(User $user, Report $report): bool
    {
        if (! $this->view($user, $report)) {
            return false;
        }
        $role = $user->roleIn($report->organization_id);

        return in_array($role, [Roles::OWNER, Roles::ADMIN], true) || $report->created_by === $user->id;
    }
}
