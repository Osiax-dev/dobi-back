<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Models\Report;
use App\Models\Study;
use App\Models\Submission;
use App\Models\User;
use App\Support\ReportLayout;
use App\Support\ReportText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Prépare le contenu d'un rapport à partir des données réelles de l'étude.
 * Les statistiques viennent du moteur existant (AnalyticsController) : mêmes calculs, même seuil de confidentialité.
 * Une information absente n'est jamais inventée : elle est signalée « à compléter ».
 */
class ReportBuilder
{
    private const SMALL_SAMPLE = 30;
    private const LOW_RESPONSE_RATE = 50;
    private const ORG_TYPES = ['personal' => 'Espace personnel', 'company' => 'Entreprise', 'ngo' => 'ONG', 'association' => 'Association', 'institution' => 'Institution'];
    private const STUDY_STATUSES = ['draft' => 'Brouillon', 'published' => 'Publiée', 'suspended' => 'Suspendue', 'closed' => 'Clôturée', 'archived' => 'Archivée'];

    public const MAX_COMPARISONS = 3;

    /**
     * @param int|array|null $compareBy  identifiants de 0 à 3 questions servant à comparer les résultats par groupe
     * @return array{content: array, notes: array}
     */
    public function build(Study $study, int|array|null $compareBy = null): array
    {
        $study->loadMissing(['organization', 'currentVersion']);
        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter((array) $compareBy)))), 0, self::MAX_COMPARISONS);

        $engine = fn (?int $by) => app(AnalyticsController::class)->show(Request::create('/', 'GET', $by ? ['by' => $by] : []), $study)->getData(true);
        $runs = $ids ? array_map($engine, $ids) : [$engine(null)];
        $stats = $runs[0];
        $minGroup = (int) $stats['min_group_size'];
        $responses = (int) $stats['totals']['responses'];

        $questions = [];
        foreach ($stats['questions'] as $i => $q) {
            $breakdowns = [];
            foreach ($runs as $run) {
                $rq = $run['questions'][$i] ?? null;
                if ($ids && $run['compare'] && $rq && ! empty($rq['breakdown'])) {
                    $breakdowns[] = ['by_id' => $run['compare']['question_id'], 'by_label' => $run['compare']['label'], 'groups' => $this->breakdown($rq)];
                }
            }
            $questions[] = $this->question($q, $minGroup) + ['breakdowns' => $breakdowns];
        }

        $content = [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'responses' => $responses,
                'study_version' => $study->currentVersion?->version_number,
                'study_version_id' => $study->current_version_id,
                'min_group_size' => $minGroup,
            ],
            'cover' => [
                'title' => $study->title,
                'organization' => $study->organization->display_name,
                'author' => $study->created_by ? User::whereKey($study->created_by)->value('name') : null,
                'study_status' => $study->status,
            ],
            'general' => ['fields' => $this->generalFields($study)],
            'methodology' => ['facts' => $this->methodology($study, $stats)],
            'sample' => $this->sample($study, $stats),
            'results' => ['questions' => $questions],
            'compare' => $stats['compare'] ?? null, // première comparaison (compatibilité)
            'compares' => $ids ? array_values(array_filter(array_map(fn ($r) => $r['compare'] ?? null, $runs))) : [],
            'analysis' => $this->analysis($questions, $responses),
            'annexes' => ['questions' => $this->annexQuestions($study), 'versions' => $this->versions($study)],
        ];

        $notes = [
            'context' => (string) ($study->description ?? ''),
            'objectives' => (string) ($study->objective ?? ''),
            'analysis' => '', 'conclusions' => '', 'recommendations' => '',
        ];

        return ['content' => $content, 'notes' => $notes];
    }

    /** Nombre de réponses actuellement collectées (pour signaler un rapport devenu ancien). */
    public function currentResponses(Study $study): int
    {
        return Submission::where('study_id', $study->id)->count();
    }

    private function question(array $q, int $minGroup): array
    {
        $n = (int) $q['n'];
        $rows = [];
        foreach (($q['distribution'] ?? null) ?: [] as $d) {
            $rows[] = ['label' => (string) $d['label'], 'count' => (int) $d['count'], 'percent' => ReportText::percent((int) $d['count'], $n)];
        }

        return [
            'question_id' => $q['question_id'],
            'label' => $q['label'],
            'type' => $q['type'],
            'type_label' => ReportText::typeLabel($q['type']),
            'n' => $n,
            'response_rate' => $q['response_rate'],
            'insufficient' => ! empty($q['insufficient_data']),
            'rows' => $rows,
            'stats' => $q['stats'] ?? null,
            'other_answers' => $q['other_answers'] ?? [],
            'explanation' => ReportText::explain($q, $minGroup),
            'breakdown' => $this->breakdown($q),
        ];
    }

    /** Comparaison par groupe : répartition de chaque réponse dans chaque groupe (groupes trop petits masqués par le moteur). */
    public function breakdown(array $q): ?array
    {
        if (empty($q['breakdown'])) {
            return null;
        }
        $multiple = $q['type'] === 'multiple_choice';

        return array_map(function ($g) use ($multiple) {
            $dist = $g['distribution'] ?? null;
            $total = $dist ? array_sum(array_column($dist, 'count')) : 0;

            return [
                'label' => (string) $g['label'],
                'hidden' => (bool) $g['hidden'],
                'rows' => $g['hidden'] || ! $dist ? [] : array_map(fn ($d) => [
                    'label' => (string) $d['label'], 'count' => (int) $d['count'],
                    'percent' => $multiple ? null : ReportText::percent((int) $d['count'], (int) $total),
                ], $dist),
            ];
        }, $q['breakdown']);
    }

    private function generalFields(Study $study): array
    {
        $date = fn ($d) => $d?->locale('fr')->translatedFormat('j F Y');
        $period = ($study->starts_at || $study->ends_at)
            ? trim(($study->starts_at ? 'du '.$date($study->starts_at) : '').' '.($study->ends_at ? 'au '.$date($study->ends_at) : ''))
            : null;

        return [
            ['key' => 'title', 'label' => 'Titre', 'value' => $study->title],
            ['key' => 'organization', 'label' => 'Organisation', 'value' => $study->organization->display_name],
            ['key' => 'organization_type', 'label' => 'Type d’organisation', 'value' => self::ORG_TYPES[$study->organization->type] ?? null],
            ['key' => 'organization_description', 'label' => 'Présentation de l’organisation', 'value' => $study->organization->type === 'personal' ? null : (trim((string) $study->organization->description) ?: null)],
            ['key' => 'study_status', 'label' => 'Statut de l’étude', 'value' => self::STUDY_STATUSES[$study->status] ?? null],
            ['key' => 'study_created', 'label' => 'Étude créée le', 'value' => $date($study->created_at)],
            ['key' => 'study_published', 'label' => 'Première publication', 'value' => $date($study->published_at)],
            ['key' => 'category', 'label' => 'Thématique', 'value' => $study->category ?: null],
            ['key' => 'target_population', 'label' => 'Population cible', 'value' => $study->target_population ?: null],
            ['key' => 'geographic_zone', 'label' => 'Zone géographique', 'value' => $study->geographic_zone ?: null],
            ['key' => 'period', 'label' => 'Période prévue de collecte', 'value' => $period],
            ['key' => 'privacy', 'label' => 'Confidentialité', 'value' => $study->privacy_mode === 'identified' ? 'Étude identifiée (coordonnées demandées)' : 'Étude anonyme'],
        ];
    }

    private function methodology(Study $study, array $stats): array
    {
        $facts = [];
        $facts[] = $study->collection_mode === 'conversational' ? 'Mode de saisie : conversationnel.' : 'Mode de saisie : formulaire classique.';
        $facts[] = $study->privacy_mode === 'identified'
            ? 'Les participants ont fourni leurs coordonnées ; elles ne figurent pas dans ce rapport.'
            : 'La collecte est anonyme : aucune donnée d’identité n’est demandée.';
        $facts[] = $study->one_response_per_person
            ? 'Une seule réponse par personne était autorisée.'
            : 'Une même personne pouvait répondre plusieurs fois.';

        $byCollector = Submission::where('study_id', $study->id)->whereNotNull('collected_by')->count();
        $total = (int) $stats['totals']['responses'];
        if ($total > 0) {
            $facts[] = $byCollector > 0
                ? "{$byCollector} réponse(s) sur {$total} ont été saisies par des enquêteurs ; les autres via le lien public."
                : 'Toutes les réponses ont été reçues via le lien public.';
        }
        $questions = collect($study->currentVersion?->snapshot['questions'] ?? [])->where('type', '!=', 'note');
        $steps = count($study->currentVersion?->snapshot['sections'] ?? []);
        if ($questions->count() > 0) {
            $facts[] = "Le questionnaire publié comporte {$questions->count()} question(s)".($steps > 0 ? " réparties en {$steps} étape(s)." : '.');
        } else {
            $facts[] = 'Aucun questionnaire n’a encore été publié.';
        }
        $facts[] = "Les statistiques ne sont détaillées que lorsqu’une question compte au moins {$stats['min_group_size']} réponses.";

        return $facts;
    }

    private function sample(Study $study, array $stats): array
    {
        $responses = (int) $stats['totals']['responses'];
        $participants = $responses;
        $note = $study->privacy_mode === 'identified'
            ? null
            : 'Étude anonyme : une réponse est comptée comme un participant, sans possibilité de vérifier les doublons.';
        if ($study->privacy_mode === 'identified' && $responses > 0) {
            $participants = (int) DB::table('participant_identifiers')
                ->join('responses', 'responses.id', '=', 'participant_identifiers.response_id')
                ->where('responses.study_id', $study->id)->distinct()->count('participant_identifiers.email');
        }

        $perDay = collect($stats['per_day']);
        $peak = $perDay->sortByDesc('responses')->first();

        $versions = DB::table('responses')->join('study_versions', 'study_versions.id', '=', 'responses.study_version_id')
            ->where('responses.study_id', $study->id)
            ->selectRaw('study_versions.version_number as version, COUNT(*) as total')->groupBy('study_versions.version_number')
            ->orderBy('study_versions.version_number')->get()
            ->map(fn ($r) => ['label' => 'Version '.$r->version, 'count' => (int) $r->total])->all();

        return [
            'responses' => $responses,
            'participants' => $participants,
            'note' => $note,
            'first_response_at' => $stats['totals']['first_response_at'],
            'last_response_at' => $stats['totals']['last_response_at'],
            'peak_day' => ($peak && $peak['responses'] > 0) ? $peak : null,
            'per_day' => $stats['per_day'],
            'by_version' => $versions,
        ];
    }

    private function analysis(array $questions, int $responses): array
    {
        $highlights = [];
        $warnings = [];

        if ($responses === 0) {
            $warnings[] = 'Aucune réponse n’a été collectée : les résultats ci-dessous sont vides.';
        } elseif ($responses < self::SMALL_SAMPLE) {
            $warnings[] = "Échantillon réduit ({$responses} réponse(s), moins de ".self::SMALL_SAMPLE.') : interprétez les pourcentages avec prudence.';
        }

        $withRate = array_values(array_filter($questions, fn ($q) => $q['response_rate'] !== null && $q['n'] > 0));
        if ($withRate) {
            usort($withRate, fn ($a, $b) => $b['response_rate'] <=> $a['response_rate']);
            $best = $withRate[0];
            $highlights[] = "Question la plus renseignée : « {$best['label']} » ({$best['response_rate']} % des participants).";
        }

        $dominant = 0;
        foreach ($questions as $q) {
            if ($q['insufficient'] || ! $q['rows'] || $q['n'] === 0 || $dominant >= 5) {
                continue;
            }
            $rows = $q['rows'];
            usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
            $top = $rows[0];
            $tie = isset($rows[1]) && $rows[1]['count'] === $top['count'];
            if (! $tie && $top['percent'] !== null && $top['percent'] >= 50 && $q['type'] !== 'multiple_choice') {
                $highlights[] = "« {$q['label']} » : « {$top['label']} » est choisie par ".ReportText::num($top['percent']).' % des répondants.';
                $dominant++;
            }
        }

        $low = array_values(array_filter($questions, fn ($q) => $q['response_rate'] !== null && $q['response_rate'] < self::LOW_RESPONSE_RATE && ! $q['insufficient'] && $q['n'] > 0));
        if ($low) {
            $names = implode(', ', array_map(fn ($q) => '« '.$q['label'].' »', array_slice($low, 0, 5)));
            $warnings[] = 'Questions renseignées par moins de '.self::LOW_RESPONSE_RATE." % des participants : {$names}".(count($low) > 5 ? ' et d’autres.' : '.');
        }
        $few = array_values(array_filter($questions, fn ($q) => $q['insufficient'] && $q['n'] > 0));
        if ($few) {
            $names = implode(', ', array_map(fn ($q) => '« '.$q['label'].' »', array_slice($few, 0, 5)));
            $warnings[] = "Questions trop peu renseignées pour afficher le détail : {$names}".(count($few) > 5 ? ' et d’autres.' : '.');
        }

        return ['highlights' => $highlights, 'warnings' => $warnings];
    }

    private function annexQuestions(Study $study): array
    {
        return collect($study->currentVersion?->snapshot['questions'] ?? [])->values()->map(fn ($q, $i) => [
            'position' => $i + 1,
            'label' => $q['label'],
            'type_label' => ReportText::typeLabel($q['type']),
            'required' => (bool) ($q['required'] ?? false),
        ])->all();
    }

    private function versions(Study $study): array
    {
        return $study->versions()->orderBy('version_number')->get(['version_number', 'published_at'])
            ->map(fn ($v) => ['version' => $v->version_number, 'published_at' => $v->published_at?->toIso8601String()])->all();
    }

    /** Titre proposé par défaut. */
    public static function defaultTitle(Study $study): string
    {
        return 'Rapport — '.$study->title.' — '.now()->locale('fr')->translatedFormat('j F Y');
    }

    public static function make(Study $study, int $userId, ?string $title = null, int|array|null $compareBy = null): Report
    {
        $built = (new self())->build($study, $compareBy);

        return Report::create([
            'organization_id' => $study->organization_id,
            'study_id' => $study->id,
            'created_by' => $userId,
            'updated_by' => $userId,
            'title' => $title ?: self::defaultTitle($study),
            'status' => 'draft',
            'revision' => 1,
            'content' => $built['content'],
            'notes' => $built['notes'],
            'custom' => ReportLayout::normalize(null),
        ]);
    }
}
