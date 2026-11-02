<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Study;
use App\Models\StudyVersion;
use App\Models\Submission;
use App\Services\QuestionnaireService;
use App\Support\AuditLogger;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudyController extends Controller
{
    /** Transitions autorisées : action => [statuts de départ, statut d'arrivée]. */
    private const TRANSITIONS = [
        'publish' => [['draft', 'published', 'suspended', 'closed'], 'published'], // crée une nouvelle version
        'resume' => [['suspended'], 'published'],                                  // reprend la version en cours
        'suspend' => [['published'], 'suspended'],
        'close' => [['published', 'suspended'], 'closed'],
        'archive' => [['draft', 'suspended', 'closed'], 'archived'],
        'restore' => [['archived'], 'draft'],                                      // revient au statut d'avant l'archivage
    ];

    public function __construct(private QuestionnaireService $questionnaires)
    {
    }

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        $query = $organization->studies()->withCount('responses')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where('status', '!=', 'archived'); // les archives ont leur propre vue
        }

        return response()->json($query->paginate(15));
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('createStudy', $organization);

        $data = $request->validate($this->rules(true));
        $this->assertDates($data);

        $study = $organization->studies()->create($data + [
            'slug' => Slug::unique($data['title'], 'studies', true),
            'created_by' => $request->user()->id,
            'status' => 'draft',
        ]);
        AuditLogger::log($request->user(), 'study.created', $study, $organization->id);

        return response()->json($study->fresh(), 201);
    }

    public function show(Study $study): JsonResponse
    {
        $this->authorize('view', $study);

        return response()->json($this->payload($study, request()->user()));
    }

    public function update(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);

        if ($study->status === 'archived') {
            return response()->json(['message' => 'Cette étude est archivée : restaurez-la pour la modifier.'], 422);
        }

        $data = $request->validate($this->rules(false));
        $this->assertDates($data + ['starts_at' => $study->starts_at, 'ends_at' => $study->ends_at]);

        if (isset($data['privacy_mode']) && $data['privacy_mode'] !== $study->privacy_mode && $study->responses()->exists()) {
            return response()->json(['message' => 'Le mode anonyme/identifié ne peut plus changer : des réponses existent déjà.'], 422);
        }

        $study->update($data);
        AuditLogger::log($request->user(), 'study.updated', $study, $study->organization_id);

        return response()->json($study->fresh());
    }

    public function duplicate(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);

        $copy = $this->questionnaires->duplicate($study, $request->user(), Slug::unique($study->title, 'studies', true));
        AuditLogger::log($request->user(), 'study.duplicated', $copy, $copy->organization_id, ['from' => $study->id]);

        return response()->json($copy, 201);
    }

    public function transition(Request $request, Study $study, string $action): JsonResponse
    {
        $this->authorize('publish', $study);

        [$from, $to] = self::TRANSITIONS[$action];

        if (! in_array($study->status, $from, true)) {
            return response()->json([
                'message' => "Action impossible : l'étude est « {$study->status} ».",
            ], 422);
        }

        if ($action === 'publish') {
            $this->questionnaires->assertPublishable($study);

            DB::transaction(function () use ($study) {
                $version = StudyVersion::create([
                    'study_id' => $study->id,
                    'version_number' => (int) $study->versions()->max('version_number') + 1,
                    'snapshot' => $this->questionnaires->snapshot($study),
                    'published_at' => now(),
                ]);
                $study->update([
                    'status' => 'published',
                    'current_version_id' => $version->id,
                    'published_at' => $study->published_at ?? now(),
                ]);
            });
        } else {
            $attrs = ['status' => $to];
            if ($action === 'archive') {
                $attrs['status_before_archive'] = $study->status;
            }
            if ($action === 'restore') {
                $attrs['status'] = $study->status_before_archive ?: 'draft';
                $attrs['status_before_archive'] = null;
            }
            $study->update($attrs);
        }

        AuditLogger::log($request->user(), "study.$action", $study, $study->organization_id, ['status' => $study->status]);

        return response()->json($this->payload($study->fresh(), $request->user()));
    }

    /** Versions publiées (avec leurs réponses) et journal d'activité de l'étude. */
    public function history(Study $study): JsonResponse
    {
        $this->authorize('view', $study);

        $counts = Submission::where('study_id', $study->id)
            ->selectRaw('study_version_id, COUNT(*) as total')->groupBy('study_version_id')->pluck('total', 'study_version_id');

        $versions = $study->versions()->orderByDesc('version_number')->get(['id', 'version_number', 'published_at'])
            ->map(fn ($v) => [
                'version_number' => $v->version_number,
                'published_at' => $v->published_at,
                'is_current' => $v->id === $study->current_version_id,
                'responses_count' => (int) ($counts[$v->id] ?? 0),
            ]);

        $activity = AuditLog::where('audit_logs.entity_type', 'Study')->where('audit_logs.entity_id', $study->id)
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.action', 'audit_logs.created_at', 'users.name as user_name')
            ->latest('audit_logs.created_at')->limit(30)->get();

        return response()->json(['versions' => $versions, 'activity' => $activity]);
    }

    /** L'étude + les actions que l'utilisateur peut faire maintenant (le serveur décide, le front affiche). */
    private function payload(Study $study, $user): array
    {
        $study->loadCount('responses');
        $allowed = array_keys(array_filter(self::TRANSITIONS, fn ($t) => in_array($study->status, $t[0], true)));

        return $study->toArray() + [
            'version_number' => $study->currentVersion?->version_number,
            'allowed_actions' => $user->can('publish', $study) ? $allowed : [],
        ];
    }

    private function rules(bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:3000'],
            'category' => ['nullable', 'string', 'max:100'],
            'target_population' => ['nullable', 'string', 'max:255'],
            'geographic_zone' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'collection_mode' => ['sometimes', Rule::in(['classic', 'conversational'])],
            'privacy_mode' => ['sometimes', Rule::in(['anonymous', 'identified'])],
        ];
    }

    private function assertDates(array $data): void
    {
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && strtotime((string) $data['ends_at']) < strtotime((string) $data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => ['La date de fin doit suivre la date de début.']]);
        }
    }
}
