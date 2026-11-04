<?php

namespace App\Support;

/** Modèles fournis par DOBI. Le contenu suit le format de QuestionnaireService::tree() (identifiants locaux). */
class BuiltinTemplates
{
    public static function all(): array
    {
        return [
            'satisfaction' => [
                'name' => 'Satisfaction des usagers',
                'description' => 'Mesurer la satisfaction après un service, avec une question de suivi si la note est basse.',
                'content' => [
                    'sections' => [
                        ['id' => 1, 'title' => 'Votre expérience', 'description' => 'Quelques questions sur le service reçu.', 'position' => 1, 'rules' => []],
                        ['id' => 2, 'title' => 'Vos remarques', 'description' => null, 'position' => 2, 'rules' => []],
                    ],
                    'questions' => [
                        self::q(1, 1, 'single_choice', 'À quelle fréquence utilisez-vous ce service ?', true, 1, [], [['premiere', 'C’est la première fois'], ['parfois', 'De temps en temps'], ['souvent', 'Très souvent']]),
                        self::q(2, 1, 'scale', 'Quelle note donnez-vous à ce service ?', true, 2, ['min' => 1, 'max' => 5], [], 'De 1 (très insatisfait) à 5 (très satisfait).'),
                        self::q(3, 2, 'long_text', 'Qu’est-ce qui vous a déplu ?', true, 1, [], [], null, [[2, 'lte', '2']]),
                        self::q(4, 2, 'long_text', 'Que pourrions-nous améliorer ?', false, 2),
                    ],
                ],
            ],
            'menages' => [
                'name' => 'Conditions de vie des ménages',
                'description' => 'Enquête en trois étapes : identification, logement, difficultés et suggestions.',
                'content' => [
                    'sections' => [
                        ['id' => 1, 'title' => 'Identification du répondant', 'description' => 'Informations générales concernant la personne interrogée.', 'position' => 1, 'rules' => []],
                        ['id' => 2, 'title' => 'Conditions de logement', 'description' => 'Informations relatives aux conditions d’habitation.', 'position' => 2, 'rules' => []],
                        ['id' => 3, 'title' => 'Informations complémentaires', 'description' => null, 'position' => 3, 'rules' => []],
                    ],
                    'questions' => [
                        self::q(1, 1, 'short_text', 'Quel est votre nom ?', true, 1, ['min_length' => 2]),
                        self::q(2, 1, 'integer', 'Quel âge avez-vous ?', true, 2, ['min' => 0, 'max' => 120]),
                        self::q(3, 1, 'single_choice', 'Quel est votre sexe ?', false, 3, [], [['femme', 'Femme'], ['homme', 'Homme']]),
                        self::q(4, 1, 'short_text', 'Dans quelle localité habitez-vous ?', true, 4),
                        self::q(5, 2, 'single_choice', 'Quel est votre type de logement ?', true, 1, ['allow_other' => true], [['maison', 'Maison individuelle'], ['cour', 'Maison en cour commune'], ['appartement', 'Appartement']]),
                        self::q(6, 2, 'yes_no', 'Disposez-vous d’un accès à l’eau potable ?', true, 2),
                        self::q(7, 2, 'short_text', 'Quelle est votre principale source d’eau ?', true, 3, [], [], null, [[6, 'equals', 'true']]),
                        self::q(8, 2, 'short_text', 'Quelle est votre principale difficulté d’accès à l’eau ?', true, 4, [], [], null, [[6, 'equals', 'false']]),
                        self::q(9, 2, 'yes_no', 'Disposez-vous de l’électricité ?', true, 5),
                        self::q(10, 3, 'long_text', 'Quelles sont les principales difficultés rencontrées ?', false, 1),
                        self::q(11, 3, 'long_text', 'Avez-vous des suggestions ?', false, 2),
                    ],
                ],
            ],
            'evenement' => [
                'name' => 'Avis sur un événement',
                'description' => 'Recueillir l’avis des participants à un événement, atelier ou formation.',
                'content' => [
                    'sections' => [
                        ['id' => 1, 'title' => 'Votre participation', 'description' => null, 'position' => 1, 'rules' => []],
                        ['id' => 2, 'title' => 'Votre avis', 'description' => 'Ces réponses nous aident à nous améliorer.', 'position' => 2, 'rules' => [['source_question_id' => 1, 'operator' => 'equals', 'value' => 'true']]],
                    ],
                    'questions' => [
                        self::q(1, 1, 'yes_no', 'Avez-vous assisté à l’événement ?', true, 1),
                        self::q(2, 1, 'long_text', 'Pourquoi n’avez-vous pas pu venir ?', false, 2, [], [], null, [[1, 'equals', 'false']]),
                        self::q(3, 2, 'scale', 'Comment évaluez-vous l’organisation ?', true, 1, ['min' => 1, 'max' => 5]),
                        self::q(4, 2, 'multiple_choice', 'Qu’avez-vous le plus apprécié ?', false, 2, [], [['contenu', 'Le contenu'], ['intervenants', 'Les intervenants'], ['lieu', 'Le lieu'], ['rencontres', 'Les rencontres']]),
                        self::q(5, 2, 'long_text', 'Un commentaire ou une suggestion ?', false, 3),
                    ],
                ],
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @param array<int, array{0:string,1:string}> $options  @param array<int, array{0:int,1:string,2:string}> $rules */
    private static function q(int $id, int $section, string $type, string $label, bool $required, int $position, array $settings = [], array $options = [], ?string $description = null, array $rules = []): array
    {
        return [
            'id' => $id, 'section_id' => $section, 'type' => $type, 'label' => $label, 'description' => $description,
            'required' => $required, 'position' => $position, 'settings' => $settings,
            'options' => array_map(fn ($o) => ['value' => $o[0], 'label' => $o[1]], $options),
            'rules' => array_map(fn ($r) => ['source_question_id' => $r[0], 'operator' => $r[1], 'value' => $r[2]], $rules),
        ];
    }
}
