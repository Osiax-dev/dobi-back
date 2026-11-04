<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Study;
use App\Support\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Affectation d'enquêteurs à une étude. Aucune affectation = tous les enquêteurs de l'espace peuvent collecter. */
class StudyAssignmentController extends Controller
{
    public function index(Study $study): JsonResponse
    {
        $this->authorize('publish', $study);

        return response()->json($this->payload($study));
    }

    public function update(Request $request, Study $study): JsonResponse
    {
        $this->authorize('publish', $study);

        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer'],
        ]);

        $surveyorIds = $study->organization->members()->wherePivot('role', Roles::SURVEYOR)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        if (array_diff($ids, $surveyorIds)) {
            return response()->json(['message' => 'Seuls les enquêteurs de cet espace peuvent être affectés.'], 422);
        }

        $study->collectors()->sync($ids);
        AuditLogger::log($request->user(), 'study.collectors_updated', $study, $study->organization_id, ['count' => count($ids)]);

        return response()->json($this->payload($study));
    }

    private function payload(Study $study): array
    {
        $assigned = $study->assignedSurveyorIds();

        return [
            'restricted' => $assigned !== [],
            'surveyors' => $study->organization->members()->wherePivot('role', Roles::SURVEYOR)->orderBy('users.name')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'assigned' => in_array($u->id, $assigned, true)])->values(),
        ];
    }
}
