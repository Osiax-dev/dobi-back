<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportRevision;
use App\Models\Study;
use App\Services\ReportAssistant;
use App\Services\ReportBuilder;
use App\Support\AuditLogger;
use App\Support\DocxWriter;
use App\Support\ReportAiPrompt;
use App\Support\ReportComposer;
use App\Support\ReportLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Rapports & synthèses : génération depuis les résultats, liste, consultation, textes de l'enquêteur, finalisation. */
class ReportController extends Controller
{
    private const MAX_PER_STUDY = 50;

    public function __construct(private readonly ReportBuilder $builder)
    {
    }

    public function index(Study $study): JsonResponse
    {
        $this->authorize('viewResults', $study);

        $rows = Report::where('study_id', $study->id)->with('creator:id,name')->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Report $r) => $this->summary($r))->values()]);
    }

    public function store(Request $request, Study $study): JsonResponse
    {
        $this->authorize('viewResults', $study);
        [$title, $compare, $error] = $this->generationInput($request, $study);
        if ($error) {
            return $error;
        }

        if (Report::where('study_id', $study->id)->count() >= self::MAX_PER_STUDY) {
            return response()->json(['message' => 'Limite atteinte ('.self::MAX_PER_STUDY.' rapports par étude) : supprimez d’anciens rapports avant d’en générer un nouveau.'], 422);
        }

        $report = ReportBuilder::make($study, $request->user()->id, $title, $compare ?: null);
        AuditLogger::log($request->user(), 'report.generated', $report, $study->organization_id, ['study_id' => $study->id]);

        return response()->json($this->detail($report), 201);
    }

    /** Aperçu avant enregistrement : calcule le rapport sans rien sauvegarder. */
    public function preview(Request $request, Study $study): JsonResponse
    {
        $this->authorize('viewResults', $study);
        [$title, $compare, $error] = $this->generationInput($request, $study);
        if ($error) {
            return $error;
        }

        $built = $this->builder->build($study, $compare ?: null);
        $title = $title ?: ReportBuilder::defaultTitle($study);
        $custom = ReportLayout::normalize(null);
        $notes = $built['notes'] + array_fill_keys(Report::NOTE_KEYS, '');

        return response()->json([
            'preview' => true,
            'title' => $title,
            'status' => 'draft',
            'content' => $built['content'],
            'notes' => $notes,
            'custom' => $custom,
            'layout' => ReportLayout::resolve($custom),
            'document' => ReportComposer::compose($built['content'], $notes, $custom, $title, 'draft'),
        ]);
    }

    /** @return array{0: ?string, 1: int[], 2: ?JsonResponse} titre, questions de comparaison (0 à 3), éventuelle erreur */
    private function generationInput(Request $request, Study $study): array
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:200'], 'compare_by' => ['nullable']]);
        $raw = $data['compare_by'] ?? null;
        $ids = $raw === null || $raw === '' ? [] : (is_array($raw) ? $raw : [$raw]);
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                return [null, [], response()->json(['message' => 'Question de comparaison invalide.', 'errors' => ['compare_by' => ['Invalide.']]], 422)];
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) > ReportBuilder::MAX_COMPARISONS) {
            return [null, [], response()->json(['message' => 'Vous pouvez comparer selon '.ReportBuilder::MAX_COMPARISONS.' questions au maximum.', 'errors' => ['compare_by' => ['Trop de questions.']]], 422)];
        }
        $snapshot = collect($study->currentVersion?->snapshot['questions'] ?? []);
        foreach ($ids as $id) {
            $by = $snapshot->firstWhere('id', $id);
            if (! $by || ! in_array($by['type'], ['single_choice', 'dropdown', 'yes_no'], true)) {
                return [null, [], response()->json(['message' => 'Comparez selon des questions à choix unique ou Oui/Non du questionnaire publié.'], 422)];
            }
        }

        return [isset($data['title']) && trim($data['title']) !== '' ? trim($data['title']) : null, $ids, null];
    }

    public function show(Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        return response()->json($this->detail($report));
    }

    /**
     * Enregistre le titre, les textes et le statut. « revision » est obligatoire : si le rapport a été
     * enregistré entre-temps (autre onglet, autre membre), l'enregistrement est refusé au lieu d'écraser.
     */
    public function update(Request $request, Report $report): JsonResponse
    {
        $this->authorize('update', $report);

        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'status' => ['sometimes', Rule::in(Report::STATUSES)],
            'notes' => ['sometimes', 'array'],
            'notes.*' => ['nullable', 'string', 'max:10000'],
            'custom' => ['sometimes', 'array'],
            'custom.order' => ['sometimes', 'array', 'max:30'],
            'custom.order.*' => ['string', 'max:20'],
            'custom.hidden' => ['sometimes', 'array', 'max:30'],
            'custom.hidden.*' => ['string', 'max:20'],
            'custom.hidden_questions' => ['sometimes', 'array', 'max:500'],
            'custom.hidden_questions.*' => ['integer'],
            'custom.explanations' => ['sometimes', 'array', 'max:500'],
            'custom.explanations.*' => ['nullable', 'string', 'max:2000'],
            'custom.extras' => ['sometimes', 'array', 'max:'.ReportLayout::MAX_EXTRAS],
            'custom.extras.*.id' => ['required', 'string', 'regex:/^x\d{1,6}$/', 'distinct'],
            'custom.extras.*.title' => ['nullable', 'string', 'max:200'],
            'custom.extras.*.body' => ['nullable', 'string', 'max:10000'],
        ]);
        $unknown = array_diff(array_keys($data['notes'] ?? []), Report::NOTE_KEYS);
        if ($unknown) {
            return response()->json(['message' => 'Section de texte inconnue.', 'errors' => ['notes' => ['Section inconnue.']]], 422);
        }

        if (array_key_exists('custom', $data)) {
            $error = $this->customError($report, $data['custom']);
            if ($error) {
                return response()->json(['message' => $error, 'errors' => ['custom' => [$error]]], 422);
            }
        }

        $editing = array_key_exists('title', $data) || array_key_exists('notes', $data) || array_key_exists('custom', $data);
        if ($report->status === 'final' && $editing) {
            return response()->json(['message' => 'Ce rapport est finalisé : rouvrez-le en brouillon pour le modifier.'], 422);
        }

        $changes = [];
        if (array_key_exists('title', $data)) {
            $changes['title'] = trim($data['title']);
        }
        if (array_key_exists('notes', $data)) {
            $merged = $report->notes ?? [];
            foreach ($data['notes'] as $key => $text) {
                $merged[$key] = (string) ($text ?? '');
            }
            $changes['notes'] = $merged;
        }
        if (array_key_exists('custom', $data)) {
            $changes['custom'] = ReportLayout::normalize($data['custom']);
        }
        if (isset($data['status']) && $data['status'] !== $report->status) {
            $changes['status'] = $data['status'];
            $changes['finalized_at'] = $data['status'] === 'final' ? now() : null;
        }

        if ($changes) {
            $conflict = response()->json(['message' => 'Ce rapport a été modifié entre-temps. Rechargez-le avant d’enregistrer pour ne rien écraser.', 'code' => 'report_conflict'], 409);
            $content = array_intersect_key($changes, array_flip(['title', 'notes', 'custom']));
            $changesState = $content && $this->stateChanges($report, $content);

            $ok = DB::transaction(function () use ($report, $changes, $data, $request, $changesState) {
                // Enregistrement conditionnel : réussit seulement si personne n'a modifié le rapport depuis.
                $updated = Report::whereKey($report->id)->where('revision', $data['revision'])
                    ->update($this->encode($changes) + ['revision' => $report->revision + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                if ($updated === 0) {
                    return false;
                }
                if ($changesState) {
                    $this->remember($report); // ancien état conservé pour l'historique
                }

                return true;
            });
            if (! $ok) {
                return $conflict;
            }
            if (isset($changes['status'])) {
                AuditLogger::log($request->user(), $changes['status'] === 'final' ? 'report.finalized' : 'report.reopened', $report, $report->organization_id);
            }
        } elseif ((int) $data['revision'] !== $report->revision) {
            return response()->json(['message' => 'Ce rapport a été modifié entre-temps. Rechargez-le avant d’enregistrer pour ne rien écraser.', 'code' => 'report_conflict'], 409);
        }

        return response()->json($this->detail($report->fresh()));
    }

    /** Historique des enregistrements précédents (sans le contenu, pour rester léger). */
    public function revisions(Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        $rows = $report->revisions()->with('author:id,name')->orderByDesc('revision')->orderByDesc('id')->limit(ReportRevision::KEEP)->get();

        return response()->json(['data' => $rows->map(fn (ReportRevision $r) => [
            'id' => $r->id, 'revision' => $r->revision, 'title' => $r->title,
            'author' => $r->author?->name, 'saved_at' => $r->created_at?->toIso8601String(),
            'filled' => count(array_filter($r->notes ?? [], fn ($t) => trim((string) $t) !== '')),
        ])->values()]);
    }

    /** Contenu d'un ancien enregistrement, pour le relire avant de le restaurer. */
    public function revision(Report $report, int $revision): JsonResponse
    {
        $this->authorize('view', $report);
        $r = $report->revisions()->with('author:id,name')->findOrFail($revision);

        return response()->json([
            'id' => $r->id, 'revision' => $r->revision, 'title' => $r->title,
            'notes' => ($r->notes ?? []) + array_fill_keys(Report::NOTE_KEYS, ''),
            'custom' => ReportLayout::normalize($r->custom),
            'author' => $r->author?->name, 'saved_at' => $r->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Restaure un ancien état (titre, textes, mise en page). L'état actuel est d'abord conservé dans l'historique :
     * rien n'est perdu. « revision » protège contre un enregistrement fait entre-temps par quelqu'un d'autre.
     */
    public function restore(Request $request, Report $report, int $revision): JsonResponse
    {
        $this->authorize('update', $report);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $old = $report->revisions()->findOrFail($revision);

        if ($report->status === 'final') {
            return response()->json(['message' => 'Ce rapport est finalisé : rouvrez-le en brouillon pour restaurer une ancienne version.'], 422);
        }

        $state = ['title' => $old->title, 'notes' => ($old->notes ?? []) + ($report->notes ?? []), 'custom' => ReportLayout::normalize($old->custom)];
        if ($error = $this->customError($report, $state['custom'])) {
            return response()->json(['message' => 'Cette version ne correspond plus au contenu du rapport : '.$error], 422);
        }

        $ok = DB::transaction(function () use ($report, $data, $state, $request) {
            $updated = Report::whereKey($report->id)->where('revision', $data['revision'])
                ->update($this->encode($state) + ['revision' => $report->revision + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            if ($updated === 0) {
                return false;
            }
            $this->remember($report);

            return true;
        });
        if (! $ok) {
            return response()->json(['message' => 'Ce rapport a été modifié entre-temps. Rechargez-le avant de restaurer.', 'code' => 'report_conflict'], 409);
        }
        AuditLogger::log($request->user(), 'report.restored', $report, $report->organization_id, ['from_revision' => $old->revision]);

        return response()->json($this->detail($report->fresh()));
    }

    /**
     * Suggestion de texte par l'IA pour une section rédigée (analyse, conclusions, recommandations).
     * Rien n'est enregistré : l'enquêteur relit puis décide d'insérer ou non.
     */
    public function assist(Request $request, Report $report): JsonResponse
    {
        $this->authorize('update', $report);

        $data = $request->validate([
            'section' => ['required', Rule::in(array_keys(ReportAiPrompt::SECTIONS))],
            'instructions' => ['nullable', 'string', 'max:500'],
            'current_text' => ['nullable', 'string', 'max:10000'],
        ]);

        if (! ReportAssistant::enabled()) {
            return response()->json(['message' => 'L’assistance IA n’est pas activée sur ce serveur.', 'code' => 'ai_disabled'], 503);
        }
        if ($report->status === 'final') {
            return response()->json(['message' => 'Ce rapport est finalisé : rouvrez-le pour demander une suggestion.'], 422);
        }

        try {
            $text = app(ReportAssistant::class)->suggest($report, $data['section'], $data['instructions'] ?? null, $data['current_text'] ?? null);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'L’assistance IA est momentanément indisponible. Réessayez dans un instant.', 'code' => 'ai_unavailable'], 502);
        }

        AuditLogger::log($request->user(), 'report.ai_suggested', $report, $report->organization_id, ['section' => $data['section']]);

        return response()->json(['section' => $data['section'], 'text' => $text]);
    }

    /** Document final composé (sections visibles, dans l'ordre choisi) : sert à l'export PDF du front. */
    public function document(Report $report): JsonResponse
    {
        $this->authorize('view', $report);

        return response()->json($this->composed($report));
    }

    /** Télécharge le rapport au format Word (.docx), tel qu'il est enregistré. */
    public function exportDocx(Request $request, Report $report)
    {
        $this->authorize('view', $report);

        $path = tempnam(sys_get_temp_dir(), 'dobi');
        DocxWriter::write($path, $this->composed($report));
        AuditLogger::log($request->user(), 'report.exported', $report, $report->organization_id, ['format' => 'docx']);

        $name = (Str::slug($report->title) ?: 'rapport').'.docx';

        return response()->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend(true);
    }

    public function destroy(Request $request, Report $report): JsonResponse
    {
        $this->authorize('delete', $report);
        AuditLogger::log($request->user(), 'report.deleted', $report, $report->organization_id, ['study_id' => $report->study_id]);
        $report->delete();

        return response()->json(['message' => 'Rapport supprimé.']);
    }

    /** Les colonnes JSON passées à update() sur le builder ne sont pas castées automatiquement. */
    private function encode(array $changes): array
    {
        foreach (['notes', 'content', 'custom'] as $column) {
            if (array_key_exists($column, $changes)) {
                $changes[$column] = json_encode($changes[$column], JSON_UNESCAPED_UNICODE);
            }
        }

        return $changes;
    }

    /** Vrai si le titre, les textes ou la mise en page demandés diffèrent de ce qui est enregistré. */
    private function stateChanges(Report $report, array $new): bool
    {
        foreach ($new as $key => $value) {
            if (json_encode($report->{$key}) !== json_encode($value)) {
                return true;
            }
        }

        return false;
    }

    /** Range l'état actuel (avant modification) dans l'historique et ne garde que les plus récents. */
    private function remember(Report $report): void
    {
        ReportRevision::create([
            'report_id' => $report->id, 'revision' => $report->revision, 'title' => $report->title,
            'notes' => $report->notes, 'custom' => $report->custom,
            'saved_by' => $report->updated_by ?? $report->created_by,
        ]);
        $keep = ReportRevision::where('report_id', $report->id)->orderByDesc('id')->limit(ReportRevision::KEEP)->pluck('id');
        if ($keep->count() >= ReportRevision::KEEP) {
            ReportRevision::where('report_id', $report->id)->whereNotIn('id', $keep)->delete();
        }
    }

    private function composed(Report $report): array
    {
        return ReportComposer::compose($report->content, ($report->notes ?? []) + array_fill_keys(Report::NOTE_KEYS, ''), $report->custom, $report->title, $report->status);
    }

    /** Vérifie que la mise en page ne cite que des sections et des questions qui existent dans ce rapport. */
    private function customError(Report $report, array $custom): ?string
    {
        $extraIds = array_column($custom['extras'] ?? [], 'id');
        $known = array_merge(array_keys(ReportLayout::CORE), $extraIds);
        foreach (array_merge($custom['order'] ?? [], $custom['hidden'] ?? []) as $key) {
            if (! in_array($key, $known, true)) {
                return 'Section inconnue dans la mise en page.';
            }
        }
        $questionIds = array_map('strval', array_column($report->content['results']['questions'] ?? [], 'question_id'));
        foreach (array_keys($custom['explanations'] ?? []) as $qid) {
            if (! in_array((string) $qid, $questionIds, true)) {
                return 'Explication rattachée à une question inconnue.';
            }
        }
        foreach ($custom['hidden_questions'] ?? [] as $qid) {
            if (! in_array((string) $qid, $questionIds, true)) {
                return 'Question inconnue dans la mise en page.';
            }
        }

        return null;
    }

    private function summary(Report $r): array
    {
        return [
            'id' => $r->id, 'study_id' => $r->study_id, 'title' => $r->title, 'status' => $r->status, 'revision' => $r->revision,
            'responses' => (int) ($r->content['meta']['responses'] ?? 0),
            'author' => $r->creator?->name,
            'created_at' => $r->created_at?->toIso8601String(), 'updated_at' => $r->updated_at?->toIso8601String(),
            'finalized_at' => $r->finalized_at?->toIso8601String(),
        ];
    }

    private function detail(Report $r): array
    {
        $r->loadMissing('creator:id,name', 'study');
        $now = $this->builder->currentResponses($r->study);
        $generated = (int) ($r->content['meta']['responses'] ?? 0);

        return $this->summary($r) + [
            'content' => $r->content,
            'notes' => ($r->notes ?? []) + array_fill_keys(Report::NOTE_KEYS, ''),
            'custom' => ReportLayout::normalize($r->custom),
            'layout' => ReportLayout::resolve($r->custom),
            'outdated' => $now !== $generated,
            'responses_now' => $now,
            'can_delete' => request()->user()?->can('delete', $r) ?? false,
            'ai_enabled' => ReportAssistant::enabled(),
            'history_count' => $r->revisions()->count(),
        ];
    }
}
