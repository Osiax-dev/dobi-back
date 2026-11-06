<?php

namespace App\Support;

/**
 * Phrases d'explication des résultats. Aucune donnée inventée : chaque phrase décrit
 * uniquement des chiffres déjà calculés (effectifs, pourcentages, moyenne, minimum, maximum).
 */
final class ReportText
{
    public const TYPE_LABELS = [
        'short_text' => 'Réponse courte', 'long_text' => 'Réponse longue', 'integer' => 'Nombre entier', 'decimal' => 'Nombre décimal',
        'number' => 'Nombre', 'single_choice' => 'Choix unique', 'dropdown' => 'Liste déroulante', 'multiple_choice' => 'Choix multiple',
        'yes_no' => 'Oui / Non', 'date' => 'Date', 'time' => 'Heure', 'datetime' => 'Date et heure', 'scale' => 'Échelle de notation',
        'location' => 'Lieu / adresse', 'geolocation' => 'Position GPS', 'note' => 'Texte informatif',
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /** Pourcentage d'un effectif par rapport au nombre de répondants (une décimale), null si aucun répondant. */
    public static function percent(int $count, int $respondents): ?float
    {
        return $respondents > 0 ? round($count / $respondents * 100, 1) : null;
    }

    /** Nombre au format français, sans zéros inutiles (12,5 ; 40). */
    public static function num(float|int|string $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 2, ',', ' '), '0'), ',');

        return $text === '' ? '0' : $text;
    }

    /**
     * Explication d'une question à partir de son résultat calculé.
     * $item : ['n', 'insufficient_data'?, 'distribution'?, 'stats'?, 'type'].
     */
    public static function explain(array $item, int $minGroup): string
    {
        $n = (int) ($item['n'] ?? 0);
        if ($n === 0) {
            return 'Aucune réponse n’a été collectée pour cette question.';
        }
        if (! empty($item['insufficient_data'])) {
            return "Cette question compte {$n} réponse(s), en dessous du seuil de {$minGroup} : le détail n’est pas affiché pour protéger la confidentialité des participants.";
        }

        $parts = [];
        $distribution = $item['distribution'] ?? null;
        if (is_array($distribution) && $distribution) {
            $rows = $distribution;
            usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
            $top = $rows[0];
            if ($top['count'] > 0) {
                $ties = array_values(array_filter($rows, fn ($r) => $r['count'] === $top['count']));
                $p = self::num(self::percent($top['count'], $n));
                if (count($ties) > 1) {
                    $labels = implode(' et ', array_map(fn ($r) => '« '.$r['label'].' »', array_slice($ties, 0, 3)));
                    $parts[] = "Les réponses {$labels} arrivent à égalité en tête, avec {$top['count']} réponse(s) chacune ({$p} % des répondants).";
                } else {
                    $parts[] = "La réponse « {$top['label']} » est la plus fréquente : {$top['count']} réponse(s) sur {$n}, soit {$p} % des répondants.";
                }
            }
            if (($item['type'] ?? '') === 'multiple_choice') {
                $parts[] = 'Plusieurs choix étaient possibles : le total des pourcentages peut dépasser 100 %.';
            }
        }

        if (! empty($item['stats'])) {
            $s = $item['stats'];
            $parts[] = 'La moyenne est de '.self::num($s['average']).' (minimum '.self::num($s['min']).', maximum '.self::num($s['max'])." ; {$n} réponse(s)).";
        }

        if (! $parts) {
            $parts[] = "{$n} réponse(s) libre(s) collectée(s). Elles ne sont pas résumées dans ce rapport ; consultez l’export des données pour les lire.";
        }

        return implode(' ', $parts);
    }
}
