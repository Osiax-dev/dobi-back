<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\Organization;
use App\Models\ParticipantIdentifier;
use App\Models\Study;
use App\Models\Submission;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantController extends Controller
{
    /** Participation de chaque étude de l'espace (chiffres uniquement, aucune donnée personnelle). */
    public function overview(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $studies = $organization->studies()->withCount('responses')->withMax('responses', 'submitted_at')
            ->latest()->get()
            ->map(fn (Study $s) => [
                'id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'privacy_mode' => $s->privacy_mode,
                'responses_count' => $s->responses_count, 'last_response_at' => $s->responses_max_submitted_at,
            ]);

        return response()->json([
            'totals' => [
                'responses' => $studies->sum('responses_count'),
                'identified_studies' => $studies->where('privacy_mode', 'identified')->count(),
                'anonymous_studies' => $studies->where('privacy_mode', 'anonymous')->count(),
            ],
            'studies' => $studies,
        ]);
    }

    /** Liste des participants d'une étude identifiée : propriétaires et administrateurs uniquement. */
    public function index(Request $request, Study $study): JsonResponse
    {
        $this->authorize('viewIdentity', $study);

        if ($study->privacy_mode !== 'identified') {
            return response()->json(['message' => 'Cette étude est anonyme : aucune identité n\'est collectée.'], 422);
        }

        $base = fn () => ParticipantIdentifier::query()
            ->join('responses', 'responses.id', '=', 'participant_identifiers.response_id')
            ->where('responses.study_id', $study->id);

        $query = $base()
            ->leftJoin('consents', 'consents.response_id', '=', 'responses.id')
            ->select(
                'responses.uuid', 'participant_identifiers.full_name', 'participant_identifiers.email', 'participant_identifiers.phone',
                'responses.submitted_at', 'consents.accepted as consent_accepted', 'consents.notice_version', 'consents.accepted_at as consent_at'
            )
            ->orderByDesc('responses.submitted_at');

        if ($request->filled('q')) {
            $like = '%'.addcslashes($request->query('q'), '%_\\').'%';
            $query->where(fn ($w) => $w->where('participant_identifiers.full_name', 'like', $like)->orWhere('participant_identifiers.email', 'like', $like));
        }

        AuditLogger::log($request->user(), 'study.participants_viewed', $study, $study->organization_id);

        return response()->json([
            'stats' => [
                'participants' => $base()->count(),
                'consents' => Consent::join('responses', 'responses.id', '=', 'consents.response_id')
                    ->where('responses.study_id', $study->id)->where('consents.accepted', true)->count(),
                'last_participation' => $base()->max('responses.submitted_at'),
            ],
            'participants' => $query->paginate(20),
        ]);
    }

    /** Anonymise : supprime l'identité du participant, conserve ses réponses. */
    public function destroy(Request $request, Study $study, string $uuid): JsonResponse
    {
        $this->authorize('viewIdentity', $study);

        $submission = Submission::where('study_id', $study->id)->where('uuid', $uuid)->firstOrFail();
        $deleted = ParticipantIdentifier::where('response_id', $submission->id)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Ce participant est déjà anonymisé.'], 422);
        }

        AuditLogger::log($request->user(), 'participant.anonymized', $study, $study->organization_id, ['response' => $uuid]);

        return response()->json(['message' => 'Identité supprimée. Les réponses sont conservées sans lien avec la personne.']);
    }
}
