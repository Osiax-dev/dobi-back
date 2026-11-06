<?php

namespace App\Support;

/**
 * Assemble le document final d'un rapport (sections visibles, dans l'ordre choisi, textes de l'enquêteur inclus).
 * Sert de source unique aux exports Word et PDF. Aucune dépendance Laravel.
 *
 * Blocs : title, p, meta, bullets, kv, table, bars, series, warn, h3, missing, pagebreak.
 */
final class ReportComposer
{
    public static function compose(array $content, array $notes, ?array $custom, string $title, string $status): array
    {
        $custom = ReportLayout::normalize($custom);
        $extras = [];
        foreach ($custom['extras'] as $e) {
            $extras[$e['id']] = $e;
        }

        $sections = [];
        foreach (ReportLayout::resolve($custom) as $s) {
            if ($s['hidden']) {
                continue;
            }
            $blocks = $s['extra']
                ? self::text($extras[$s['key']]['body'] ?? '')
                : self::core($s['key'], $content, $notes, $custom, $title, $status);
            $sections[] = ['key' => $s['key'], 'title' => $s['title'], 'number' => $s['number'], 'blocks' => $blocks];
        }

        return ['title' => $title, 'status' => $status, 'sections' => $sections];
    }

    private static function core(string $key, array $c, array $notes, array $custom, string $title, string $status): array
    {
        switch ($key) {
            case 'cover':
                $blocks = [['t' => 'title', 'text' => $title], ['t' => 'p', 'text' => (string) ($c['cover']['organization'] ?? '')], ['t' => 'p', 'text' => 'Étude : '.($c['cover']['title'] ?? '')]];
                $by = ! empty($c['cover']['author']) ? ' par '.$c['cover']['author'] : '';
                $blocks[] = ['t' => 'meta', 'text' => 'Généré le '.self::date($c['meta']['generated_at'] ?? null).$by];
                $blocks[] = ['t' => 'meta', 'text' => $status === 'final' ? 'Rapport finalisé' : 'Brouillon en cours de rédaction'];
                $blocks[] = ['t' => 'pagebreak'];

                return $blocks;

            case 'general':
                return [['t' => 'kv', 'rows' => array_map(fn ($f) => [$f['label'], $f['value']], $c['general']['fields'] ?? [])]];

            case 'context':
            case 'objectives':
            case 'conclusions':
            case 'recommendations':
                return self::text($notes[$key] ?? '');

            case 'methodology':
                return [['t' => 'bullets', 'items' => $c['methodology']['facts'] ?? []]];

            case 'sample':
                $s = $c['sample'];
                $blocks = [['t' => 'kv', 'rows' => [
                    ['Participants', (string) $s['participants']],
                    ['Réponses', (string) $s['responses']],
                    ['Période de collecte réelle', ! empty($s['first_response_at']) ? 'du '.self::date($s['first_response_at']).' au '.self::date($s['last_response_at'] ?? null) : '—'],
                ]]];
                if (! empty($s['note'])) {
                    $blocks[] = ['t' => 'p', 'text' => $s['note']];
                }
                if (! empty($s['peak_day'])) {
                    $blocks[] = ['t' => 'p', 'text' => 'Jour le plus actif : '.self::date($s['peak_day']['date']).' ('.$s['peak_day']['responses'].' réponse(s)).'];
                }
                if (count($s['by_version'] ?? []) > 1) {
                    $blocks[] = ['t' => 'p', 'text' => 'Répartition par version : '.implode(', ', array_map(fn ($v) => $v['label'].' ('.$v['count'].')', $s['by_version'])).'.'];
                }
                if ((int) $s['responses'] === 0) {
                    $blocks[] = ['t' => 'missing', 'text' => 'Aucune réponse pour le moment'];
                }
                if (count($s['per_day'] ?? []) > 1) {
                    $blocks[] = ['t' => 'series', 'title' => 'Évolution des réponses par jour', 'rows' => $s['per_day']];
                }

                return $blocks;

            case 'results':
                return self::results($c, $custom);

            case 'analysis':
                $blocks = [];
                if (! empty($c['analysis']['highlights'])) {
                    $blocks[] = ['t' => 'bullets', 'items' => $c['analysis']['highlights']];
                }
                if (! empty($c['analysis']['warnings'])) {
                    $blocks[] = ['t' => 'warn', 'title' => 'Points de vigilance', 'items' => $c['analysis']['warnings']];
                }

                return array_merge($blocks, self::text($notes['analysis'] ?? ''));

            case 'annexes':
                $blocks = [];
                $q = $c['annexes']['questions'] ?? [];
                $blocks[] = ['t' => 'h3', 'text' => 'Questionnaire utilisé'];
                $blocks[] = $q
                    ? ['t' => 'table', 'head' => ['N°', 'Question', 'Type', 'Obligatoire'], 'rows' => array_map(fn ($x) => [(string) $x['position'], $x['label'], $x['type_label'], $x['required'] ? 'Oui' : 'Non'], $q)]
                    : ['t' => 'missing', 'text' => 'Aucune question publiée'];
                if (! empty($c['annexes']['versions'])) {
                    $blocks[] = ['t' => 'p', 'text' => 'Versions publiées : '.implode(', ', array_map(fn ($v) => 'version '.$v['version'].' ('.self::date($v['published_at'] ?? null).')', $c['annexes']['versions'])).'.'];
                }

                return $blocks;
        }

        return [];
    }

    private static function results(array $c, array $custom): array
    {
        $questions = array_values(array_filter($c['results']['questions'] ?? [], fn ($q) => ! in_array((int) $q['question_id'], $custom['hidden_questions'], true)));
        if (! $questions) {
            return [['t' => 'missing', 'text' => 'Aucun résultat à afficher']];
        }

        $blocks = [];
        foreach ($questions as $i => $q) {
            $blocks[] = ['t' => 'h3', 'text' => ($i + 1).'. '.$q['label']];
            $blocks[] = ['t' => 'meta', 'text' => $q['type_label'].' · '.$q['n'].' réponse(s)'.($q['response_rate'] !== null ? ' · '.$q['response_rate'].' % des participants' : '')];
            if (empty($q['insufficient']) && ! empty($q['rows'])) {
                $blocks[] = ['t' => 'bars', 'rows' => $q['rows']];
            }
            foreach (self::comparisons($q, $c) as $cmp) {
                $groups = array_values(array_filter($cmp['groups'], fn ($g) => empty($g['hidden'])));
                if ($groups && ! empty($q['rows'])) {
                    $head = array_merge(['Réponse'], array_map(fn ($g) => $g['label'], $groups));
                    $rows = [];
                    foreach ($q['rows'] as $k => $r) {
                        $row = [$r['label']];
                        foreach ($groups as $g) {
                            $cell = $g['rows'][$k] ?? null;
                            $row[] = $cell === null ? '—' : $cell['count'].($cell['percent'] !== null ? ' ('.ReportText::num($cell['percent']).' %)' : '');
                        }
                        $rows[] = $row;
                    }
                    $blocks[] = ['t' => 'meta', 'text' => 'Comparaison selon « '.$cmp['label'].' »'];
                    $blocks[] = ['t' => 'table', 'head' => $head, 'rows' => $rows];
                }
            }
            if (! empty($q['other_answers'])) {
                $blocks[] = ['t' => 'p', 'text' => 'Précisions « Autre » : '.implode(', ', array_map(fn ($o) => $o['text'].' ('.$o['count'].')', $q['other_answers'])).'.'];
            }
            $blocks[] = ['t' => 'p', 'text' => $custom['explanations'][(string) $q['question_id']] ?? $q['explanation']];
        }

        return $blocks;
    }

    /** Comparaisons d'une question : liste « breakdowns » (rapports récents) ou ancienne comparaison unique. @return list<array{label:string,groups:array}> */
    private static function comparisons(array $q, array $c): array
    {
        if (! empty($q['breakdowns'])) {
            return array_map(fn ($b) => ['label' => (string) $b['by_label'], 'groups' => $b['groups']], $q['breakdowns']);
        }

        return ! empty($q['breakdown']) ? [['label' => (string) ($c['compare']['label'] ?? ''), 'groups' => $q['breakdown']]] : [];
    }

    /** Texte libre de l'enquêteur : un paragraphe par bloc séparé d'une ligne vide ; vide = « à compléter ». */
    private static function text(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [['t' => 'missing', 'text' => 'À compléter']];
        }

        return array_map(fn ($p) => ['t' => 'p', 'text' => trim($p)], array_values(array_filter(preg_split('/\R{2,}/', $text), fn ($p) => trim($p) !== '')));
    }

    private static function date(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $t = strtotime($iso);

        return $t ? (int) date('j', $t).' '.$months[(int) date('n', $t) - 1].' '.date('Y', $t) : '—';
    }
}
