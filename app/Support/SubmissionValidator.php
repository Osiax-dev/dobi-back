<?php

namespace App\Support;

/**
 * Validation d'une soumission face à la version figée (snapshot) d'un questionnaire.
 * Classe volontairement indépendante du framework : mêmes règles pour les modes classique et conversationnel.
 *
 * @return array{errors: array<string, string[]>, clean: array<string, mixed>}
 */
final class SubmissionValidator
{
    public static function validate(array $snapshot, array $answers): array
    {
        $errors = [];
        $clean = [];
        $questions = $snapshot['questions'] ?? [];

        $known = [];
        foreach ($questions as $q) {
            $known[(string) $q['id']] = true;
        }
        foreach (array_keys($answers) as $key) {
            if (! isset($known[(string) $key])) {
                $errors[(string) $key][] = 'Question inconnue.';
            }
        }

        foreach ($questions as $q) {
            $id = (string) $q['id'];
            $visible = self::isVisible($q, $clean);
            $has = array_key_exists($id, $answers) && ! self::isEmpty($answers[$id]);

            if (! $visible) {
                if ($has) {
                    $errors[$id][] = 'Cette question ne devait pas être affichée selon les réponses précédentes.';
                }
                continue;
            }

            if (! $has) {
                if (! empty($q['required'])) {
                    $errors[$id][] = 'Cette question est obligatoire.';
                }
                continue;
            }

            [$ok, $value, $message] = self::normalize($q, $answers[$id]);
            if ($ok) {
                $clean[$id] = $value;
            } else {
                $errors[$id][] = $message;
            }
        }

        return ['errors' => $errors, 'clean' => $clean];
    }

    /** Une question est visible si toutes ses règles sont satisfaites (aucune règle = toujours visible). */
    public static function isVisible(array $question, array $clean): bool
    {
        foreach ($question['rules'] ?? [] as $rule) {
            $answer = $clean[(string) $rule['source_question_id']] ?? null;
            if (! self::evaluate($answer, $rule['operator'], $rule['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public static function evaluate(mixed $answer, string $operator, mixed $value): bool
    {
        if ($answer === null) {
            return $operator === 'not_equals';
        }

        $expected = (string) $value;
        $asString = static fn ($v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;

        $equals = is_array($answer)
            ? in_array($expected, array_map($asString, $answer), true)
            : $asString($answer) === $expected;

        return match ($operator) {
            'equals' => $equals,
            'not_equals' => ! $equals,
            'contains' => is_array($answer) ? $equals : stripos($asString($answer), $expected) !== false,
            'gt' => is_numeric($answer) && is_numeric($value) && $answer + 0 > $value + 0,
            'lt' => is_numeric($answer) && is_numeric($value) && $answer + 0 < $value + 0,
            default => false,
        };
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '') || (is_array($v) && count($v) === 0);
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private static function normalize(array $q, mixed $raw): array
    {
        $settings = $q['settings'] ?? [];
        $values = array_map(static fn ($o) => (string) $o['value'], $q['options'] ?? []);

        switch ($q['type']) {
            case 'short_text':
            case 'location':
            case 'long_text':
                if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
                    return [false, null, 'Texte attendu.'];
                }
                $text = trim((string) $raw);
                $max = (int) ($settings['max_length'] ?? ($q['type'] === 'long_text' ? 5000 : 255));
                if (mb_strlen($text) > $max) {
                    return [false, null, "Maximum $max caractères."];
                }
                return [true, $text, ''];

            case 'single_choice':
                if (! is_string($raw) && ! is_int($raw)) {
                    return [false, null, 'Un seul choix attendu.'];
                }
                if (! in_array((string) $raw, $values, true)) {
                    return [false, null, 'Choix invalide.'];
                }
                return [true, (string) $raw, ''];

            case 'multiple_choice':
                if (! is_array($raw) || ! array_is_list($raw)) {
                    return [false, null, 'Une liste de choix est attendue.'];
                }
                $picked = [];
                foreach ($raw as $item) {
                    if (! is_string($item) && ! is_int($item)) {
                        return [false, null, 'Choix invalide.'];
                    }
                    if (! in_array((string) $item, $values, true)) {
                        return [false, null, 'Choix invalide.'];
                    }
                    $picked[(string) $item] = true;
                }
                $picked = array_keys($picked);
                if (isset($settings['min_choices']) && count($picked) < (int) $settings['min_choices']) {
                    return [false, null, 'Sélectionnez au moins '.(int) $settings['min_choices'].' choix.'];
                }
                if (isset($settings['max_choices']) && count($picked) > (int) $settings['max_choices']) {
                    return [false, null, 'Sélectionnez au plus '.(int) $settings['max_choices'].' choix.'];
                }
                return [true, array_map('strval', $picked), ''];

            case 'yes_no':
                $map = ['true' => true, '1' => true, 'yes' => true, 'oui' => true, 'false' => false, '0' => false, 'no' => false, 'non' => false];
                if (is_bool($raw)) {
                    return [true, $raw, ''];
                }
                if ((is_string($raw) || is_int($raw)) && array_key_exists(strtolower((string) $raw), $map)) {
                    return [true, $map[strtolower((string) $raw)], ''];
                }
                return [false, null, 'Répondez par oui ou non.'];

            case 'number':
                if (is_bool($raw) || ! is_numeric($raw)) {
                    return [false, null, 'Nombre attendu.'];
                }
                $n = $raw + 0;
                if (isset($settings['min']) && $n < $settings['min']) {
                    return [false, null, 'La valeur minimale est '.$settings['min'].'.'];
                }
                if (isset($settings['max']) && $n > $settings['max']) {
                    return [false, null, 'La valeur maximale est '.$settings['max'].'.'];
                }
                return [true, $n, ''];

            case 'scale':
                $min = (int) ($settings['min'] ?? 1);
                $max = (int) ($settings['max'] ?? 5);
                if (is_bool($raw) || ! is_numeric($raw) || (float) $raw !== (float) (int) $raw) {
                    return [false, null, 'Note entière attendue.'];
                }
                $n = (int) $raw;
                if ($n < $min || $n > $max) {
                    return [false, null, "La note doit être comprise entre $min et $max."];
                }
                return [true, $n, ''];

            case 'date':
                if (! is_string($raw)) {
                    return [false, null, 'Date attendue (AAAA-MM-JJ).'];
                }
                $d = \DateTime::createFromFormat('Y-m-d', $raw);
                if (! $d || $d->format('Y-m-d') !== $raw) {
                    return [false, null, 'Date invalide (format AAAA-MM-JJ).'];
                }
                return [true, $raw, ''];
        }

        return [false, null, 'Type de question non pris en charge.'];
    }
}
