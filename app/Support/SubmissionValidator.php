<?php

namespace App\Support;

/**
 * Validation d'une soumission face à la version figée (snapshot) d'un questionnaire par étapes.
 * Classe indépendante du framework : mêmes règles pour le formulaire classique, par étapes et conversationnel.
 *
 * Visibilité : une question est posée si son étape est visible ET si ses propres conditions sont remplies.
 * Une question (ou une étape) masquée n'est jamais obligatoire et ne peut pas recevoir de réponse.
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

        $sections = [];
        foreach ($snapshot['sections'] ?? [] as $s) {
            $sections[(string) $s['id']] = $s;
        }

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
            $has = array_key_exists($id, $answers) && ! self::isEmpty($answers[$id]);

            if ($q['type'] === 'note') { // texte informatif : jamais de réponse
                if ($has) {
                    $errors[$id][] = 'Ce texte informatif ne reçoit pas de réponse.';
                }
                continue;
            }

            $visible = self::isSectionVisible($q, $sections, $clean) && self::isVisible($q, $clean);

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

    /** Les conditions de l'étape de la question sont-elles remplies ? (aucune étape ou aucune condition = oui) */
    public static function isSectionVisible(array $question, array $sections, array $clean): bool
    {
        $sid = $question['section_id'] ?? null;
        if ($sid === null || ! isset($sections[(string) $sid])) {
            return true;
        }

        return self::rulesPass($sections[(string) $sid]['rules'] ?? [], $clean);
    }

    /** Une question est visible si toutes ses conditions sont remplies (aucune condition = toujours visible). */
    public static function isVisible(array $question, array $clean): bool
    {
        return self::rulesPass($question['rules'] ?? [], $clean);
    }

    private static function rulesPass(array $rules, array $clean): bool
    {
        foreach ($rules as $rule) {
            $answer = $clean[(string) $rule['source_question_id']] ?? null;
            if (! self::evaluate($answer, $rule['operator'], $rule['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public static function evaluate(mixed $answer, string $operator, mixed $value): bool
    {
        if ($operator === 'answered') {
            return $answer !== null;
        }
        if ($operator === 'not_answered') {
            return $answer === null;
        }
        if ($answer === null) {
            return $operator === 'not_equals';
        }

        $expected = (string) $value;
        $asString = static fn ($v): string => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;

        $matches = static fn ($v): bool => $expected === '__other__'
            ? (is_string($v) && str_starts_with($v, '__dobi_other__:'))
            : $asString($v) === $expected;
        $equals = is_array($answer) ? count(array_filter($answer, $matches)) > 0 : $matches($answer);

        $numbers = is_numeric($answer) && is_numeric($value);

        return match ($operator) {
            'equals' => $equals,
            'not_equals' => ! $equals,
            'contains' => is_array($answer) || $expected === '__other__' ? $equals : stripos($asString($answer), $expected) !== false,
            'gt' => $numbers && $answer + 0 > $value + 0,
            'gte' => $numbers && $answer + 0 >= $value + 0,
            'lt' => $numbers && $answer + 0 < $value + 0,
            'lte' => $numbers && $answer + 0 <= $value + 0,
            default => false,
        };
    }

    private static function isEmpty(mixed $v): bool
    {
        if ($v === null || $v === '') return true;
        if (is_string($v)) return trim($v) === '' || (str_starts_with($v, '__dobi_other__:') && trim(substr($v, 15)) === '');
        if (is_array($v)) return count($v) === 0 || count(array_filter($v, static fn ($item) => !(is_string($item) && str_starts_with($item, '__dobi_other__:') && trim(substr($item, 15)) === ''))) === 0;
        return false;
    }

    /** @return array{0: bool, 1: mixed, 2: string} */
    private static function normalize(array $q, mixed $raw): array
    {
        $s = $q['settings'] ?? [];
        $values = array_map(static fn ($o) => (string) $o['value'], $q['options'] ?? []);
        $fail = static fn (string $m): array => [false, null, $m];

        switch ($q['type']) {
            case 'short_text':
            case 'location':
            case 'long_text':
                if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
                    return $fail('Texte attendu.');
                }
                $text = trim((string) $raw);
                $len = mb_strlen($text);
                $max = (int) ($s['max_length'] ?? ($q['type'] === 'long_text' ? 5000 : 255));
                if ($len > $max) {
                    return $fail("Maximum $max caractères.");
                }
                if (isset($s['min_length']) && $len < (int) $s['min_length']) {
                    return $fail('Minimum '.(int) $s['min_length'].' caractères.');
                }
                $format = $s['format'] ?? null;
                if ($format === 'email' && ! filter_var($text, FILTER_VALIDATE_EMAIL)) {
                    return $fail('Adresse e-mail invalide.');
                }
                if ($format === 'phone') {
                    $digits = preg_replace('/\D/', '', $text);
                    if (! preg_match('/^\+?[0-9 ().\-]{6,20}$/', $text) || strlen($digits) < 6 || strlen($digits) > 15) {
                        return $fail('Numéro de téléphone invalide.');
                    }
                }
                if ($format === 'url') {
                    $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));
                    if (! filter_var($text, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
                        return $fail('Adresse web invalide (http:// ou https://).');
                    }
                }

                return [true, $text, ''];

            case 'single_choice':
            case 'dropdown':
                if (is_string($raw) && str_starts_with($raw, '__dobi_other__:')) {
                    $other = trim(substr($raw, 15));
                    if (empty($s['allow_other']) || ! in_array($q['type'], ['single_choice'], true)) return $fail('Choix invalide.');
                    if ($other === '') return $fail('Précisez votre réponse pour « Autre ».');
                    if (strlen($other) > 2000) return $fail('Maximum 2 000 caractères pour « Autre ».');
                    return [true, '__dobi_other__:'.$other, ''];
                }
                if (! is_string($raw) && ! is_int($raw)) {
                    return $fail('Un seul choix attendu.');
                }
                if (! in_array((string) $raw, $values, true)) {
                    return $fail('Choix invalide.');
                }

                return [true, (string) $raw, ''];

            case 'multiple_choice':
                if (! is_array($raw) || ! array_is_list($raw)) {
                    return $fail('Une liste de choix est attendue.');
                }
                $picked = [];
                foreach ($raw as $item) {
                    if (is_string($item) && str_starts_with($item, '__dobi_other__:')) {
                        $other = trim(substr($item, 15));
                        if (empty($s['allow_other'])) return $fail('Choix invalide.');
                        if ($other === '') return $fail('Précisez votre réponse pour « Autre ».');
                        if (strlen($other) > 2000) return $fail('Maximum 2 000 caractères pour « Autre ».');
                        $picked['__dobi_other__:'.$other] = true;
                        continue;
                    }
                    if ((! is_string($item) && ! is_int($item)) || ! in_array((string) $item, $values, true)) {
                        return $fail('Choix invalide.');
                    }
                    $picked[(string) $item] = true;
                }
                $picked = array_map('strval', array_keys($picked));
                if (isset($s['min_choices']) && count($picked) < (int) $s['min_choices']) {
                    return $fail('Sélectionnez au moins '.(int) $s['min_choices'].' choix.');
                }
                if (isset($s['max_choices']) && count($picked) > (int) $s['max_choices']) {
                    return $fail('Sélectionnez au plus '.(int) $s['max_choices'].' choix.');
                }

                return [true, $picked, ''];

            case 'yes_no':
                $map = ['true' => true, '1' => true, 'yes' => true, 'oui' => true, 'false' => false, '0' => false, 'no' => false, 'non' => false];
                if (is_bool($raw)) {
                    return [true, $raw, ''];
                }
                if ((is_string($raw) || is_int($raw)) && array_key_exists(strtolower((string) $raw), $map)) {
                    return [true, $map[strtolower((string) $raw)], ''];
                }

                return $fail('Répondez par oui ou non.');

            case 'integer':
                if (is_int($raw)) {
                    $n = $raw;
                } elseif (is_float($raw) && floor($raw) === $raw) {
                    $n = (int) $raw;
                } elseif (is_string($raw) && preg_match('/^-?\d{1,15}$/', trim($raw))) {
                    $n = (int) trim($raw);
                } else {
                    return $fail('Nombre entier attendu.');
                }

                return self::inRange($n, $s) ?? [true, $n, ''];

            case 'decimal':
                if (is_bool($raw) || (! is_int($raw) && ! is_float($raw) && ! is_string($raw))) {
                    return $fail('Nombre attendu.');
                }
                $text = is_string($raw) ? str_replace(',', '.', trim($raw)) : rtrim(rtrim(number_format((float) $raw, 10, '.', ''), '0'), '.');
                if (! preg_match('/^-?\d{1,15}(\.\d+)?$/', $text)) {
                    return $fail('Nombre attendu.');
                }
                $decimals = isset($s['decimals']) ? (int) $s['decimals'] : 6;
                if (strlen(explode('.', $text)[1] ?? '') > $decimals) {
                    return $fail($decimals === 0 ? 'Nombre entier attendu.' : "Maximum $decimals décimale(s).");
                }
                $n = (float) $text;

                return self::inRange($n, $s) ?? [true, $n, ''];

            case 'number': // ancien type conservé
                if (is_bool($raw) || ! is_numeric($raw)) {
                    return $fail('Nombre attendu.');
                }
                $n = $raw + 0;

                return self::inRange($n, $s) ?? [true, $n, ''];

            case 'scale':
                $min = (int) ($s['min'] ?? 1);
                $max = (int) ($s['max'] ?? 5);
                if (is_bool($raw) || ! is_numeric($raw) || (float) $raw !== (float) (int) $raw) {
                    return $fail('Note entière attendue.');
                }
                $n = (int) $raw;
                if ($n < $min || $n > $max) {
                    return $fail("La note doit être comprise entre $min et $max.");
                }

                return [true, $n, ''];

            case 'date':
                if (! is_string($raw)) {
                    return $fail('Date attendue (AAAA-MM-JJ).');
                }
                $d = \DateTime::createFromFormat('!Y-m-d', $raw);
                if (! $d || $d->format('Y-m-d') !== $raw) {
                    return $fail('Date invalide (format AAAA-MM-JJ).');
                }
                if (isset($s['min']) && $s['min'] !== '' && $raw < $s['min']) {
                    return $fail('La date doit être le '.$s['min'].' ou après.');
                }
                if (isset($s['max']) && $s['max'] !== '' && $raw > $s['max']) {
                    return $fail('La date doit être le '.$s['max'].' ou avant.');
                }

                return [true, $raw, ''];

            case 'time':
                if (! is_string($raw) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($raw), $m)) {
                    return $fail('Heure invalide (format HH:MM).');
                }
                $time = $m[1].':'.$m[2];
                if (isset($s['min']) && $s['min'] !== '' && $time < $s['min']) {
                    return $fail('L\'heure doit être '.$s['min'].' ou après.');
                }
                if (isset($s['max']) && $s['max'] !== '' && $time > $s['max']) {
                    return $fail('L\'heure doit être '.$s['max'].' ou avant.');
                }

                return [true, $time, ''];

            case 'datetime':
                if (! is_string($raw)) {
                    return $fail('Date et heure attendues.');
                }
                $dt = null;
                foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d H:i:s'] as $format) {
                    $c = \DateTime::createFromFormat($format, trim($raw));
                    if ($c && $c->format(substr($format, 1)) === trim($raw)) {
                        $dt = $c;
                        break;
                    }
                }
                if (! $dt) {
                    return $fail('Date et heure invalides.');
                }
                $value = $dt->format('Y-m-d\TH:i');
                if (isset($s['min']) && $s['min'] !== '' && $value < (strlen($s['min']) === 10 ? $s['min'].'T00:00' : $s['min'])) {
                    return $fail('Date et heure trop anciennes.');
                }
                if (isset($s['max']) && $s['max'] !== '' && $value > (strlen($s['max']) === 10 ? $s['max'].'T23:59' : $s['max'])) {
                    return $fail('Date et heure trop récentes.');
                }

                return [true, $value, ''];

            case 'geolocation':
                if (! is_array($raw) || ! isset($raw['lat'], $raw['lng']) || ! is_numeric($raw['lat']) || ! is_numeric($raw['lng'])) {
                    return $fail('Position invalide.');
                }
                if (abs((float) $raw['lat']) > 90 || abs((float) $raw['lng']) > 180) {
                    return $fail('Position hors limites.');
                }
                // Vie privée : la précision (décimales) est réglable par l'étude ; 5 décimales ≈ 1 mètre.
                $p = max(0, min(6, (int) ($s['precision'] ?? 5)));
                $out = ['lat' => round((float) $raw['lat'], $p), 'lng' => round((float) $raw['lng'], $p)];
                if (isset($raw['accuracy']) && is_numeric($raw['accuracy']) && $raw['accuracy'] >= 0) {
                    $out['accuracy'] = (int) round((float) $raw['accuracy']);
                }

                return [true, $out, ''];
        }

        return $fail('Type de question non pris en charge.');
    }

    /** Vérifie minimum / maximum ; retourne null si tout va bien. @return array{0: bool, 1: null, 2: string}|null */
    private static function inRange(int|float $n, array $s): ?array
    {
        if (isset($s['min']) && $s['min'] !== '' && $n < $s['min']) {
            return [false, null, 'La valeur minimale est '.$s['min'].'.'];
        }
        if (isset($s['max']) && $s['max'] !== '' && $n > $s['max']) {
            return [false, null, 'La valeur maximale est '.$s['max'].'.'];
        }

        return null;
    }
}
