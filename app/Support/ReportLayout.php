<?php

namespace App\Support;

/**
 * Mise en page d'un rapport : ordre des sections, sections masquées, paragraphes libres,
 * explications modifiées. Aucune dépendance Laravel : utilisable et testable seule.
 */
final class ReportLayout
{
    /** Sections du rapport, dans l'ordre par défaut. « cover » reste toujours en premier et ne peut pas être masquée. */
    public const CORE = [
        'cover' => 'Page de présentation',
        'general' => 'Informations générales sur l’enquête',
        'context' => 'Introduction et contexte',
        'objectives' => 'Objectifs de l’enquête',
        'methodology' => 'Méthodologie de collecte',
        'sample' => 'Échantillon et participants',
        'results' => 'Principaux résultats',
        'analysis' => 'Analyse des résultats',
        'conclusions' => 'Conclusions',
        'recommendations' => 'Recommandations',
        'annexes' => 'Annexes',
    ];
    public const MAX_EXTRAS = 10;

    /** Remet une mise en page quelconque (ou absente) sous sa forme complète et sûre. */
    public static function normalize(?array $custom): array
    {
        $custom ??= [];

        $extras = [];
        foreach (array_slice((array) ($custom['extras'] ?? []), 0, self::MAX_EXTRAS) as $e) {
            if (is_array($e) && isset($e['id']) && is_string($e['id']) && preg_match('/^x\d{1,6}$/', $e['id']) && ! isset($extras[$e['id']])) {
                $extras[$e['id']] = ['id' => $e['id'], 'title' => trim((string) ($e['title'] ?? '')), 'body' => (string) ($e['body'] ?? '')];
            }
        }

        $explanations = [];
        foreach ((array) ($custom['explanations'] ?? []) as $qid => $text) {
            if (is_numeric($qid) && is_string($text) && trim($text) !== '') {
                $explanations[(string) (int) $qid] = trim($text);
            }
        }

        $known = array_merge(array_keys(self::CORE), array_keys($extras));
        $hidden = array_values(array_unique(array_filter(
            array_map('strval', (array) ($custom['hidden'] ?? [])),
            fn ($k) => $k !== 'cover' && in_array($k, $known, true)
        )));

        return [
            'order' => array_values(array_map('strval', (array) ($custom['order'] ?? []))),
            'hidden' => $hidden,
            'hidden_questions' => array_values(array_unique(array_map('intval', (array) ($custom['hidden_questions'] ?? [])))),
            'explanations' => $explanations,
            'extras' => array_values($extras),
        ];
    }

    /**
     * Liste ordonnée de toutes les sections : [key, title, hidden, extra, number].
     * « number » est la numérotation visible (null pour la page de présentation et les sections masquées).
     */
    public static function resolve(?array $custom): array
    {
        $c = self::normalize($custom);
        $extras = [];
        foreach ($c['extras'] as $e) {
            $extras[$e['id']] = $e;
        }

        $movable = array_values(array_filter(array_keys(self::CORE), fn ($k) => $k !== 'cover'));
        $known = array_merge($movable, array_keys($extras));
        $order = array_values(array_unique(array_filter($c['order'], fn ($k) => in_array($k, $known, true))));
        foreach ($known as $k) {
            if (! in_array($k, $order, true)) {
                $order[] = $k;
            }
        }

        $out = [['key' => 'cover', 'title' => self::CORE['cover'], 'hidden' => false, 'extra' => false, 'number' => null]];
        $n = 0;
        foreach ($order as $key) {
            $isHidden = in_array($key, $c['hidden'], true);
            $extra = isset($extras[$key]);
            $title = $extra ? ($extras[$key]['title'] !== '' ? $extras[$key]['title'] : 'Section sans titre') : self::CORE[$key];
            $out[] = ['key' => $key, 'title' => $title, 'hidden' => $isHidden, 'extra' => $extra, 'number' => $isHidden ? null : ++$n];
        }

        return $out;
    }
}
