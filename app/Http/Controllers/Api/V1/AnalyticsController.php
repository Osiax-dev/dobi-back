<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Study;
use App\Models\Submission;
use App\Support\ResponseFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Statistiques d'une étude. Filtres : from, to, version. Comparaison : by = id d'une question
     * à choix unique ou Oui/Non ; les réponses sont alors ventilées par groupe.
     */
    public function show(Request $request, Study $study): JsonResponse
    {
        $this->authorize('viewResults', $study);
        $request->validate(ResponseFilters::rules() + ['by' => ['nullable', 'integer']]);

        $questions = $study->currentVersion?->snapshot['questions'] ?? [];
        $minGroup = config('dobi.min_group_size');

        $by = null;
        if ($request->filled('by')) {
            $by = collect($questions)->firstWhere('id', (int) $request->query('by'));
            if (! $by || ! in_array($by['type'], ['single_choice', 'dropdown', 'yes_no'], true)) {
                return response()->json(['message' => 'Comparez selon une question à choix unique ou Oui/Non.'], 422);
            }
        }

        $responses = fn () => ResponseFilters::apply(Submission::where('study_id', $study->id), $request);
        $total = $responses()->count();
        $range = $responses()->selectRaw('MIN(submitted_at) as first_at, MAX(submitted_at) as last_at')->first();

        // Réponses par jour, jours vides inclus (365 jours maximum).
        $perDay = [];
        if ($total > 0) {
            $counts = $responses()->selectRaw('DATE(submitted_at) as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day');
            $start = Carbon::parse($request->query('from', $range->first_at))->startOfDay();
            $end = Carbon::parse($request->query('to', $range->last_at))->startOfDay();
            if ($start->diffInDays($end) > 365) {
                $start = $end->copy()->subDays(365);
            }
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $perDay[] = ['date' => $d->toDateString(), 'responses' => (int) ($counts[$d->toDateString()] ?? 0)];
            }
        }

        // Valeurs collectées, par question.
        $byQuestion = [];
        $rows = ResponseFilters::apply(
            DB::table('answers')->join('responses', 'responses.id', '=', 'answers.response_id')->where('responses.study_id', $study->id),
            $request
        )->select('answers.response_id', 'answers.question_id', 'answers.value')->cursor();
        foreach ($rows as $row) {
            $byQuestion[$row->question_id][] = [$row->response_id, json_decode($row->value, true)];
        }

        // Groupes de comparaison.
        $groupOf = [];
        $groupSize = [];
        $groups = [];
        if ($by) {
            $labels = $by['type'] === 'yes_no'
                ? ['true' => 'Oui', 'false' => 'Non']
                : collect($by['options'])->pluck('label', 'value')->all();
            if (! empty($by['settings']['allow_other'])) $labels['__other__'] = 'Autre';
            foreach ($byQuestion[$by['id']] ?? [] as [$rid, $v]) {
                $key = is_bool($v) ? ($v ? 'true' : 'false') : (is_string($v) && str_starts_with($v, '__dobi_other__:') ? '__other__' : (string) $v);
                $groupOf[$rid] = $key;
                $groupSize[$key] = ($groupSize[$key] ?? 0) + 1;
            }
            foreach ($labels as $key => $label) {
                $size = $groupSize[(string) $key] ?? 0;
                $groups[] = ['key' => (string) $key, 'label' => $label, 'size' => $size, 'hidden' => $size < $minGroup];
            }
        }

        $out = [];
        foreach ($questions as $q) {
            if ($q['type'] === 'note') {
                continue; // texte informatif : pas de réponse à analyser
            }
            $entries = $byQuestion[$q['id']] ?? [];
            $n = count($entries);
            $item = [
                'question_id' => $q['id'], 'label' => $q['label'], 'type' => $q['type'], 'n' => $n,
                'response_rate' => $total > 0 ? (int) round($n / $total * 100) : null,
            ];

            if ($n < $minGroup) {
                $item['insufficient_data'] = true; // trop peu de réponses : pas de détail
                $out[] = $item;
                continue;
            }

            $values = array_column($entries, 1);
            $item['distribution'] = $this->distribution($q, $values);

            // Les précisions « Autre » sont consultables dans les résultats,
            // mais uniquement lorsque le groupe atteint le seuil de confidentialité.
            if (! empty($q['settings']['allow_other'])) {
                $otherTexts = [];
                foreach ($values as $answer) {
                    foreach ((array) $answer as $picked) {
                        if (is_string($picked) && str_starts_with($picked, '__dobi_other__:')) {
                            $text = trim(substr($picked, 15));
                            if ($text !== '') $otherTexts[] = $text;
                        }
                    }
                }
                if (count($otherTexts) >= $minGroup) {
                    $counts = array_count_values($otherTexts);
                    arsort($counts);
                    $item['other_answers'] = array_map(
                        fn ($text, $count) => ['text' => $text, 'count' => $count],
                        array_keys($counts), array_values($counts)
                    );
                }
            }

            if (in_array($q['type'], ['number', 'integer', 'decimal', 'scale'], true)) {
                $nums = array_values(array_filter($values, 'is_numeric'));
                if ($nums) {
                    $item['stats'] = ['average' => round(array_sum($nums) / count($nums), 2), 'min' => min($nums), 'max' => max($nums)];
                }
            }

            if ($by && $q['id'] !== $by['id'] && $item['distribution'] !== null) {
                $item['breakdown'] = array_map(function ($g) use ($entries, $groupOf, $q) {
                    $in = array_values(array_filter($entries, fn ($e) => ($groupOf[$e[0]] ?? null) === $g['key']));

                    return ['key' => $g['key'], 'label' => $g['label'], 'hidden' => $g['hidden'],
                        'distribution' => $g['hidden'] ? null : $this->distribution($q, array_column($in, 1))];
                }, $groups);
            }

            $out[] = $item;
        }

        return response()->json([
            'study' => ['id' => $study->id, 'title' => $study->title, 'status' => $study->status],
            'filters' => $request->only(['from', 'to', 'version', 'by']),
            'totals' => ['responses' => $total, 'first_response_at' => $range->first_at, 'last_response_at' => $range->last_at],
            'min_group_size' => $minGroup,
            'versions' => $study->versions()->orderByDesc('version_number')->get(['id', 'version_number']),
            'per_day' => $perDay,
            'compare' => $by ? ['question_id' => $by['id'], 'label' => $by['label'], 'groups' => $groups] : null,
            'questions' => $out,
        ]);
    }

    /** Répartition [{label, count}] pour les questions à choix, Oui/Non et échelles ; null sinon. */
    private function distribution(array $q, array $values): ?array
    {
        switch ($q['type']) {
            case 'single_choice':
            case 'dropdown':
            case 'multiple_choice':
                $counts = [];
                foreach ($q['options'] as $o) {
                    $counts[$o['value']] = ['label' => $o['label'], 'count' => 0];
                }
                if (! empty($q['settings']['allow_other'])) $counts['__other__'] = ['label' => 'Autre', 'count' => 0];
                foreach ($values as $v) {
                    foreach ((array) $v as $picked) {
                        if (is_string($picked) && str_starts_with($picked, '__dobi_other__:')) {
                            if (isset($counts['__other__'])) $counts['__other__']['count']++;
                        } elseif (isset($counts[$picked])) {
                            $counts[$picked]['count']++;
                        }
                    }
                }

                return array_values($counts);

            case 'yes_no':
                $yes = count(array_filter($values, fn ($v) => $v === true));

                return [['label' => 'Oui', 'count' => $yes], ['label' => 'Non', 'count' => count($values) - $yes]];

            case 'scale':
                $min = (int) ($q['settings']['min'] ?? 1);
                $max = (int) ($q['settings']['max'] ?? 5);
                $dist = array_count_values(array_map('intval', array_filter($values, 'is_numeric')));

                return array_map(fn ($k) => ['label' => (string) $k, 'count' => $dist[$k] ?? 0], range($min, $max));
        }

        return null;
    }
}
