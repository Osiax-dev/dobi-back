<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Study;
use App\Models\Submission;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectController extends Controller
{
    /** Études publiées que l'utilisateur peut collecter, avec son nombre de réponses collectées. */
    public function studies(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgIds = $user->organizations()
            ->wherePivotIn('role', [Roles::OWNER, Roles::ADMIN, Roles::EDITOR, Roles::SURVEYOR])
            ->pluck('organizations.id');

        $studies = Study::with('organization')
            ->whereIn('organization_id', $orgIds)
            ->where('status', 'published')
            ->whereNotNull('current_version_id')
            ->orderBy('title')
            ->get()
            ->filter(fn (Study $s) => $user->can('collect', $s))
            ->values();

        $mine = Submission::where('collected_by', $user->id)
            ->whereIn('study_id', $studies->pluck('id'))
            ->selectRaw('study_id, count(*) as total')
            ->groupBy('study_id')
            ->pluck('total', 'study_id');

        return response()->json(['data' => $studies->map(fn (Study $s) => [
            'id' => $s->id,
            'title' => $s->title,
            'slug' => $s->slug,
            'organization' => $s->organization->display_name,
            'privacy_mode' => $s->privacy_mode,
            'mine' => (int) ($mine[$s->id] ?? 0),
        ])->values()]);
    }

    /** Suivi par enquêteur : nombre de réponses et dernière collecte (propriétaire, administrateur, analyste). */
    public function collectors(Request $request, Study $study): JsonResponse
    {
        $this->authorize('viewResults', $study);

        $rows = Submission::where('study_id', $study->id)
            ->selectRaw('collected_by, count(*) as total, max(submitted_at) as last_at')
            ->groupBy('collected_by')
            ->get();

        $names = User::whereIn('id', $rows->pluck('collected_by')->filter())->pluck('name', 'id');

        return response()->json([
            'data' => $rows->whereNotNull('collected_by')->map(fn ($r) => [
                'user_id' => (int) $r->collected_by,
                'name' => $names[$r->collected_by] ?? 'Ancien membre',
                'total' => (int) $r->total,
                'last_at' => $r->last_at,
            ])->sortByDesc('total')->values(),
            'public_link' => (int) ($rows->firstWhere('collected_by', null)->total ?? 0),
        ]);
    }
}
