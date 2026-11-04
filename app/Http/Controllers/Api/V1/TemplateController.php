<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Study;
use App\Models\StudyTemplate;
use App\Services\QuestionnaireService;
use App\Support\AuditLogger;
use App\Support\BuiltinTemplates;
use App\Support\Roles;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function __construct(private QuestionnaireService $questionnaires)
    {
    }

    /** Modèles DOBI + modèles de l'organisation. */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('createStudy', $organization);

        $user = $request->user();
        $role = $user->roleIn($organization);

        $builtin = collect(BuiltinTemplates::all())->map(fn ($t, $key) => [
            'id' => "dobi:$key", 'name' => $t['name'], 'description' => $t['description'],
            'sections_count' => count($t['content']['sections']), 'questions_count' => count($t['content']['questions']),
        ])->values();

        $own = StudyTemplate::where('organization_id', $organization->id)->latest()->get()->map(fn (StudyTemplate $t) => [
            'id' => $t->id, 'name' => $t->name, 'description' => $t->description,
            'sections_count' => count($t->content['sections'] ?? []), 'questions_count' => count($t->content['questions'] ?? []),
            'created_at' => $t->created_at,
            'can_delete' => $this->canDelete($user->id, $role, $t),
        ]);

        return response()->json(['builtin' => $builtin, 'organization' => $own]);
    }

    /** Enregistre le questionnaire d'une étude comme modèle de son organisation. */
    public function store(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tree = $this->questionnaires->exportTree($study);
        if (empty($tree['questions'])) {
            return response()->json(['message' => 'Ajoutez au moins une question avant d’enregistrer un modèle.'], 422);
        }

        $template = StudyTemplate::create($data + [
            'organization_id' => $study->organization_id,
            'created_by' => $request->user()->id,
            'content' => $tree,
        ]);
        AuditLogger::log($request->user(), 'template.created', $template, $study->organization_id, ['study_id' => $study->id]);

        return response()->json(['id' => $template->id, 'name' => $template->name], 201);
    }

    /** Crée une étude (brouillon) à partir d'un modèle DOBI (« dobi:clé ») ou d'un modèle de l'organisation (identifiant). */
    public function createStudy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('createStudy', $organization);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'template' => ['required'],
            'privacy_mode' => ['sometimes', Rule::in(['anonymous', 'identified'])],
        ]);

        $key = (string) $data['template'];
        if (str_starts_with($key, 'dobi:')) {
            $content = BuiltinTemplates::find(substr($key, 5))['content'] ?? null;
        } else {
            $content = StudyTemplate::where('organization_id', $organization->id)->whereKey((int) $key)->first()?->content;
        }
        if (! $content) {
            return response()->json(['message' => 'Modèle introuvable.'], 404);
        }

        $study = DB::transaction(function () use ($organization, $request, $data, $content) {
            $study = $organization->studies()->create([
                'title' => $data['title'],
                'slug' => Slug::unique($data['title'], 'studies', true),
                'privacy_mode' => $data['privacy_mode'] ?? 'anonymous',
                'created_by' => $request->user()->id,
                'status' => 'draft',
            ]);
            $this->questionnaires->applyTree($study, $content);

            return $study;
        });
        AuditLogger::log($request->user(), 'study.created', $study, $organization->id, ['template' => $key]);

        return response()->json($study->fresh(), 201);
    }

    public function destroy(Request $request, StudyTemplate $template): JsonResponse
    {
        $role = $request->user()->roleIn($template->organization_id);
        abort_unless($this->canDelete($request->user()->id, $role, $template), 403, 'Vous ne pouvez pas supprimer ce modèle.');

        $organizationId = $template->organization_id;
        $template->delete();
        AuditLogger::log($request->user(), 'template.deleted', $request->user(), $organizationId, ['name' => $template->name]);

        return response()->json(['message' => 'Modèle supprimé.']);
    }

    /** Propriétaire et administrateur : tous les modèles. Éditeur : ceux qu'il a créés. */
    private function canDelete(int $userId, ?string $role, StudyTemplate $template): bool
    {
        return in_array($role, [Roles::OWNER, Roles::ADMIN], true)
            || ($role === Roles::EDITOR && $template->created_by === $userId);
    }
}
