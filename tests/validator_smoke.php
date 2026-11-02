<?php
// Test rapide sans framework : php tests/validator_smoke.php
require __DIR__.'/../app/Support/SubmissionValidator.php';

use App\Support\SubmissionValidator as V;

$snap = ['questions' => [
    ['id' => 1, 'type' => 'yes_no', 'required' => true, 'options' => [], 'rules' => []],
    ['id' => 2, 'type' => 'short_text', 'required' => true, 'options' => [], 'rules' => [
        ['source_question_id' => 1, 'operator' => 'equals', 'value' => 'true'],
    ]],
    ['id' => 3, 'type' => 'single_choice', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']], 'rules' => []],
    ['id' => 4, 'type' => 'multiple_choice', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']], 'rules' => []],
    ['id' => 5, 'type' => 'scale', 'required' => false, 'settings' => ['min' => 1, 'max' => 5], 'options' => [], 'rules' => []],
    ['id' => 6, 'type' => 'number', 'required' => false, 'settings' => ['min' => 0, 'max' => 120], 'options' => [], 'rules' => []],
    ['id' => 7, 'type' => 'date', 'required' => false, 'options' => [], 'rules' => []],
]];

$fails = 0;
function check(string $name, bool $cond): void { global $fails; echo ($cond ? 'OK   ' : 'FAIL ').$name.PHP_EOL; if (! $cond) $fails++; }

$r = V::validate($snap, ['1' => true, '2' => 'Bonjour', '3' => 'a', '4' => ['a', 'b', 'a'], '5' => 4, '6' => '35', '7' => '2026-11-01']);
check('soumission valide', $r['errors'] === []);
check('multi-choix dédoublonné', $r['clean']['4'] === ['a', 'b']);
check('nombre normalisé', $r['clean']['6'] === 35);

$r = V::validate($snap, ['1' => false]);
check('Q2 masquée si Q1=non, aucune erreur', $r['errors'] === []);

$r = V::validate($snap, ['1' => false, '2' => 'x']);
check('réponse à une question masquée rejetée', isset($r['errors']['2']));

$r = V::validate($snap, ['1' => true]);
check('Q2 obligatoire quand visible', isset($r['errors']['2']));

$r = V::validate($snap, []);
check('Q1 obligatoire', isset($r['errors']['1']));

$r = V::validate($snap, ['1' => true, '2' => 'ok', '3' => 'z']);
check('choix invalide', isset($r['errors']['3']));

$r = V::validate($snap, ['1' => true, '2' => 'ok', '5' => 9]);
check('échelle hors bornes', isset($r['errors']['5']));

$r = V::validate($snap, ['1' => true, '2' => 'ok', '6' => 500]);
check('nombre hors bornes', isset($r['errors']['6']));

$r = V::validate($snap, ['1' => true, '2' => 'ok', '7' => '2026-02-31']);
check('date impossible', isset($r['errors']['7']));

$r = V::validate($snap, ['1' => true, '2' => 'ok', '99' => 'x']);
check('question inconnue', isset($r['errors']['99']));

$r = V::validate($snap, ['1' => 'oui', '2' => 'ok']);
check('oui/non en texte accepté', $r['errors'] === [] && $r['clean']['1'] === true);

echo $fails === 0 ? "\nTous les tests passent.\n" : "\n$fails test(s) en échec.\n";
exit($fails === 0 ? 0 : 1);
