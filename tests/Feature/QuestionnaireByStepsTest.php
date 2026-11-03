<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Study;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Parcours complet : construire un formulaire par étapes, le publier, y répondre. */
class QuestionnaireByStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;

    /** Sécurité : ces tests vident la base. Ils refusent de tourner ailleurs que sur SQLite (jamais ta vraie base). */
    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException("Test annulé pour protéger la base « ".config('database.default')." » : exécutez d'abord « php artisan config:clear » (cache de configuration périmé) et vérifiez phpunit.xml (DB_CONNECTION=sqlite).");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        Sanctum::actingAs($this->owner);
    }

    private function study(array $extra = []): array
    {
        return $this->postJson("/api/v1/organizations/{$this->org->id}/studies", $extra + ['title' => 'Enquête ménages'])->assertCreated()->json();
    }

    private function step(int $studyId, string $title, ?string $description = null, array $rules = []): array
    {
        return $this->postJson("/api/v1/studies/$studyId/sections", array_filter(['title' => $title, 'description' => $description, 'rules' => $rules ?: null]))
            ->assertCreated()->json();
    }

    private function question(int $studyId, array $body): array
    {
        return $this->postJson("/api/v1/studies/$studyId/questions", $body)->assertCreated()->json();
    }

    /** Construit l'exemple « conditions de vie des ménages » : 3 étapes, conditions sur questions et sur une étape. */
    private function buildHouseholdForm(): array
    {
        $study = $this->study(['privacy_mode' => 'anonymous']);
        $id = $study['id'];

        $s1 = $this->step($id, 'Identification du répondant', 'Informations générales concernant la personne interrogée.');
        $s2 = $this->step($id, 'Conditions de logement', 'Informations relatives aux conditions d\'habitation.');

        $name = $this->question($id, ['type' => 'short_text', 'label' => 'Quel est votre nom ?', 'required' => true, 'section_id' => $s1['id'], 'settings' => ['min_length' => 2]]);
        $age = $this->question($id, ['type' => 'integer', 'label' => 'Quel âge avez-vous ?', 'required' => true, 'section_id' => $s1['id'], 'settings' => ['min' => 0, 'max' => 120]]);
        $sex = $this->question($id, ['type' => 'single_choice', 'label' => 'Quel est votre sexe ?', 'section_id' => $s1['id'], 'options' => [['label' => 'Femme'], ['label' => 'Homme']]]);
        $town = $this->question($id, ['type' => 'dropdown', 'label' => 'Dans quelle localité habitez-vous ?', 'section_id' => $s1['id'], 'options' => [['label' => 'Parakou'], ['label' => 'Cotonou']]]);

        $water = $this->question($id, ['type' => 'yes_no', 'label' => 'Disposez-vous d\'un accès à l\'eau potable ?', 'required' => true, 'section_id' => $s2['id']]);
        $source = $this->question($id, ['type' => 'short_text', 'label' => 'Quelle est votre principale source d\'eau ?', 'required' => true, 'section_id' => $s2['id'],
            'rules' => [['source_question_id' => $water['id'], 'operator' => 'equals', 'value' => 'true']]]);
        $difficulty = $this->question($id, ['type' => 'short_text', 'label' => 'Quelle est votre principale difficulté d\'accès à l\'eau ?', 'required' => true, 'section_id' => $s2['id'],
            'rules' => [['source_question_id' => $water['id'], 'operator' => 'equals', 'value' => 'false']]]);
        $note = $this->question($id, ['type' => 'note', 'label' => 'Merci de répondre avec précision.', 'required' => true, 'section_id' => $s2['id']]);

        // Étape 3 : seulement pour les adultes (condition sur l'étape entière).
        $s3 = $this->step($id, 'Informations complémentaires', null, [['source_question_id' => $age['id'], 'operator' => 'gte', 'value' => '18']]);
        $suggest = $this->question($id, ['type' => 'long_text', 'label' => 'Avez-vous des suggestions ?', 'required' => true, 'section_id' => $s3['id']]);
        $geo = $this->question($id, ['type' => 'geolocation', 'label' => 'Position du logement', 'section_id' => $s3['id'], 'settings' => ['precision' => 3]]);

        return compact('study', 's1', 's2', 's3', 'name', 'age', 'sex', 'town', 'water', 'source', 'difficulty', 'note', 'suggest', 'geo');
    }

    private function publish(array $f): void
    {
        $this->postJson("/api/v1/studies/{$f['study']['id']}/publish")->assertOk();
    }

    private function submit(array $f, array $answers, int $status = 201)
    {
        return $this->postJson("/api/v1/public/studies/{$f['study']['slug']}/responses", ['answers' => $answers])->assertStatus($status);
    }

    public function test_les_etapes_gardent_titre_description_et_ordre(): void
    {
        $f = $this->buildHouseholdForm();
        $tree = $this->getJson("/api/v1/studies/{$f['study']['id']}/questionnaire")->assertOk()->json();

        $this->assertSame(['Identification du répondant', 'Conditions de logement', 'Informations complémentaires'], array_column($tree['sections'], 'title'));
        $this->assertSame('Informations générales concernant la personne interrogée.', $tree['sections'][0]['description']);
        $this->assertSame('gte', $tree['sections'][2]['rules'][0]['operator']);
        $this->assertCount(4, array_filter($tree['questions'], fn ($q) => $q['section_id'] === $f['s1']['id']));
    }

    public function test_le_formulaire_publie_contient_les_etapes_et_leurs_conditions(): void
    {
        $f = $this->buildHouseholdForm();
        $this->publish($f);

        $public = $this->getJson("/api/v1/public/studies/{$f['study']['slug']}")->assertOk()->json('questionnaire');
        $this->assertCount(3, $public['sections']);
        $this->assertSame($f['age']['id'], $public['sections'][2]['rules'][0]['source_question_id']);
        $this->assertCount(10, $public['questions']);
    }

    public function test_reponse_complete_d_un_adulte_et_conditions_oui(): void
    {
        $f = $this->buildHouseholdForm();
        $this->publish($f);

        $this->submit($f, [
            $f['name']['id'] => 'Aminata', $f['age']['id'] => '34', $f['sex']['id'] => 'opt_1', $f['town']['id'] => 'opt_1',
            $f['water']['id'] => true, $f['source']['id'] => 'Puits',
            $f['suggest']['id'] => 'Plus de forages',
            $f['geo']['id'] => ['lat' => 9.337512, 'lng' => 2.630112, 'accuracy' => 12.4],
        ]);

        $this->assertDatabaseCount('responses', 1);
        $stored = json_decode(\DB::table('answers')->where('question_id', $f['geo']['id'])->value('value'), true);
        $this->assertSame(['lat' => 9.338, 'lng' => 2.63, 'accuracy' => 12], $stored); // arrondi à 3 décimales
        $this->assertSame(34, json_decode(\DB::table('answers')->where('question_id', $f['age']['id'])->value('value')));
    }

    public function test_question_masquee_obligatoire_ne_bloque_pas_la_soumission(): void
    {
        $f = $this->buildHouseholdForm();
        $this->publish($f);

        // Eau = non : « source d'eau » (obligatoire) est masquée ; « difficulté » devient obligatoire.
        $this->submit($f, [$f['name']['id'] => 'Koffi', $f['age']['id'] => 30, $f['water']['id'] => false, $f['difficulty']['id'] => 'Distance', $f['suggest']['id'] => 'RAS']);
        $errors = $this->submit($f, [$f['name']['id'] => 'Koffi', $f['age']['id'] => 30, $f['water']['id'] => false, $f['suggest']['id'] => 'RAS'], 422)->json('errors');
        $this->assertSame('Cette question est obligatoire.', $errors['answers.'.$f['difficulty']['id']][0]);
        $this->submit($f, [$f['name']['id'] => 'Koffi', $f['age']['id'] => 30, $f['water']['id'] => false, $f['difficulty']['id'] => 'x', $f['source']['id'] => 'Puits', $f['suggest']['id'] => 'RAS'], 422)
            ->assertJsonStructure(['errors' => ['answers.'.$f['source']['id']]]); // réponse à une question masquée
    }

    public function test_etape_masquee_pour_un_mineur(): void
    {
        $f = $this->buildHouseholdForm();
        $this->publish($f);

        // Mineur : l'étape 3 (suggestions obligatoires) est masquée, donc non exigée.
        $this->submit($f, [$f['name']['id'] => 'Junior', $f['age']['id'] => 15, $f['water']['id'] => true, $f['source']['id'] => 'Rivière']);
        // ... et on ne peut pas y répondre quand même.
        $this->submit($f, [$f['name']['id'] => 'Junior', $f['age']['id'] => 15, $f['water']['id'] => true, $f['source']['id'] => 'Rivière', $f['suggest']['id'] => 'Non'], 422);
        // Adulte : l'étape 3 est exigée.
        $this->submit($f, [$f['name']['id'] => 'Adulte', $f['age']['id'] => 18, $f['water']['id'] => true, $f['source']['id'] => 'Rivière'], 422)
            ->assertJsonStructure(['errors' => ['answers.'.$f['suggest']['id']]]);
    }

    public function test_validations_par_type(): void
    {
        $f = $this->buildHouseholdForm();
        $this->publish($f);
        $base = [$f['name']['id'] => 'Aminata', $f['age']['id'] => 30, $f['water']['id'] => true, $f['source']['id'] => 'Puits', $f['suggest']['id'] => 'ok'];

        $this->submit($f, [$f['age']['id'] => 200] + $base, 422)->assertJsonStructure(['errors' => ['answers.'.$f['age']['id']]]);
        $this->submit($f, [$f['age']['id'] => '3.5'] + $base, 422);
        $this->submit($f, [$f['name']['id'] => 'A'] + $base, 422); // min_length 2
        $this->submit($f, [$f['town']['id'] => 'inconnu'] + $base, 422);
        $this->submit($f, [$f['geo']['id'] => ['lat' => 120, 'lng' => 2]] + $base, 422);
        $this->submit($f, [$f['note']['id'] => 'x'] + $base, 422); // texte informatif
        $this->submit($f, $base);
    }

    public function test_agencement_en_une_fois_deplace_les_questions_entre_etapes(): void
    {
        $f = $this->buildHouseholdForm();
        $id = $f['study']['id'];

        // Étape 2 en premier, étape 3 ensuite, étape 1 en dernier ; la question « sexe » passe dans l'étape 2.
        $this->putJson("/api/v1/studies/$id/layout", ['steps' => [
            ['id' => $f['s2']['id'], 'questions' => [$f['sex']['id'], $f['water']['id'], $f['source']['id'], $f['difficulty']['id'], $f['note']['id']]],
            ['id' => $f['s3']['id'], 'questions' => [$f['geo']['id'], $f['suggest']['id']]],
            ['id' => $f['s1']['id'], 'questions' => [$f['name']['id'], $f['age']['id'], $f['town']['id']]],
        ]])->assertOk();

        $tree = $this->getJson("/api/v1/studies/$id/questionnaire")->json();
        $this->assertSame([$f['s2']['id'], $f['s3']['id'], $f['s1']['id']], array_column($tree['sections'], 'id'));
        $this->assertSame($f['sex']['id'], $tree['questions'][0]['id']);
        $this->assertSame($f['s2']['id'], $tree['questions'][0]['section_id']);
        $this->assertSame($f['geo']['id'], $tree['questions'][5]['id']); // ordre voulu dans l'étape 3
    }

    public function test_agencement_refuse_une_question_d_une_autre_etude(): void
    {
        $f = $this->buildHouseholdForm();
        $other = $this->study(['title' => 'Autre']);
        $foreign = $this->question($other['id'], ['type' => 'short_text', 'label' => 'Étrangère']);

        $this->putJson("/api/v1/studies/{$f['study']['id']}/layout", ['steps' => [['id' => $f['s1']['id'], 'questions' => [$foreign['id']]]]])->assertStatus(422);
        $this->putJson("/api/v1/studies/{$f['study']['id']}/layout", ['steps' => [['id' => $f['s1']['id'], 'questions' => []], ['id' => $f['s1']['id'], 'questions' => []]]])->assertStatus(422);
    }

    public function test_publication_refusee_si_etape_conditionnee_par_une_question_placee_apres(): void
    {
        $f = $this->buildHouseholdForm();
        // On place l'étape 3 (qui dépend de l'âge) AVANT l'étape 1 (qui contient l'âge).
        $this->putJson("/api/v1/studies/{$f['study']['id']}/layout", ['steps' => [
            ['id' => $f['s3']['id'], 'questions' => [$f['suggest']['id'], $f['geo']['id']]],
            ['id' => $f['s1']['id'], 'questions' => [$f['name']['id'], $f['age']['id'], $f['sex']['id'], $f['town']['id']]],
        ]])->assertOk();

        $this->postJson("/api/v1/studies/{$f['study']['id']}/publish")->assertStatus(422)->assertJsonValidationErrors('sections');
    }

    public function test_publication_refusee_formulaire_sans_question_repondable_ou_liste_sans_choix(): void
    {
        $study = $this->study();
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertStatus(422);

        $this->question($study['id'], ['type' => 'note', 'label' => 'Information seule']);
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertStatus(422);

        $this->question($study['id'], ['type' => 'dropdown', 'label' => 'Liste', 'options' => [['label' => 'Un seul']]]);
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertStatus(422);
    }

    public function test_reglages_incoherents_et_conditions_interdites(): void
    {
        $study = $this->study();
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'integer', 'label' => 'Âge', 'settings' => ['min' => 50, 'max' => 10]])->assertStatus(422);
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'date', 'label' => 'Date', 'settings' => ['min' => 'hier']])->assertStatus(422);
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'Mail', 'settings' => ['format' => 'regex']])->assertStatus(422);

        $note = $this->question($study['id'], ['type' => 'note', 'label' => 'Info']);
        $this->postJson("/api/v1/studies/{$study['id']}/questions", [
            'type' => 'short_text', 'label' => 'Dépend d\'une note', 'rules' => [['source_question_id' => $note['id'], 'operator' => 'answered']],
        ])->assertStatus(422);
        $this->postJson("/api/v1/studies/{$study['id']}/sections", [
            'title' => 'Étape', 'rules' => [['source_question_id' => $note['id'], 'operator' => 'answered']],
        ])->assertStatus(422);
    }

    public function test_une_note_n_est_jamais_obligatoire(): void
    {
        $study = $this->study();
        $note = $this->question($study['id'], ['type' => 'note', 'label' => 'Consigne', 'required' => true]);
        $this->assertFalse($note['required']);
    }

    public function test_dupliquer_une_etude_copie_etapes_et_conditions_d_etape(): void
    {
        $f = $this->buildHouseholdForm();
        $copy = $this->postJson("/api/v1/studies/{$f['study']['id']}/duplicate")->assertCreated()->json();
        $tree = $this->getJson("/api/v1/studies/{$copy['id']}/questionnaire")->json();

        $this->assertCount(3, $tree['sections']);
        $this->assertCount(10, $tree['questions']);
        $rule = $tree['sections'][2]['rules'][0];
        $ageCopy = collect($tree['questions'])->firstWhere('label', 'Quel âge avez-vous ?');
        $this->assertSame($ageCopy['id'], $rule['source_question_id']); // la condition pointe vers la copie, pas l'original
        $this->assertNotSame($f['age']['id'], $ageCopy['id']);
    }

    public function test_ancienne_etude_sans_etapes_reste_utilisable(): void
    {
        $study = $this->study();
        $q = $this->question($study['id'], ['type' => 'number', 'label' => 'Ancien type nombre', 'required' => true, 'settings' => ['min' => 1]]);
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertOk();

        $this->postJson("/api/v1/public/studies/{$study['slug']}/responses", ['answers' => [$q['id'] => 0]])->assertStatus(422);
        $this->postJson("/api/v1/public/studies/{$study['slug']}/responses", ['answers' => [$q['id'] => 5]])->assertCreated();
    }

    public function test_un_editeur_construit_mais_ne_publie_pas(): void
    {
        $editor = User::factory()->create();
        $this->org->members()->attach($editor->id, ['role' => 'editor']);
        $study = $this->study();

        Sanctum::actingAs($editor);
        $step = $this->step($study['id'], 'Étape éditeur');
        $this->question($study['id'], ['type' => 'short_text', 'label' => 'Q', 'section_id' => $step['id']]);
        $this->putJson("/api/v1/studies/{$study['id']}/layout", ['steps' => [['id' => $step['id'], 'questions' => []]]])->assertOk();
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertForbidden();
    }

    public function test_un_analyste_ou_un_etranger_ne_peut_pas_modifier_le_formulaire(): void
    {
        $study = $this->study();
        $analyst = User::factory()->create();
        $this->org->members()->attach($analyst->id, ['role' => 'analyst']);
        $stranger = User::factory()->create();

        foreach ([$analyst, $stranger] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/studies/{$study['id']}/sections", ['title' => 'X'])->assertForbidden();
            $this->putJson("/api/v1/studies/{$study['id']}/layout", ['steps' => []])->assertForbidden();
        }
        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/studies/{$study['id']}/questionnaire")->assertForbidden();
    }

    public function test_une_etude_archivee_est_en_lecture_seule(): void
    {
        $f = $this->buildHouseholdForm();
        $this->postJson("/api/v1/studies/{$f['study']['id']}/archive")->assertOk();
        $this->putJson("/api/v1/studies/{$f['study']['id']}/layout", ['steps' => [['id' => $f['s1']['id'], 'questions' => []]]])->assertStatus(422);
        $this->postJson("/api/v1/studies/{$f['study']['id']}/sections", ['title' => 'X'])->assertStatus(422);
    }

    public function test_statistiques_et_export_avec_les_nouveaux_types(): void
    {
        config(['dobi.min_group_size' => 1]); // une seule réponse suffit pour ce test
        $f = $this->buildHouseholdForm();
        $this->publish($f);
        $this->submit($f, [$f['name']['id'] => 'Aminata', $f['age']['id'] => 34, $f['town']['id'] => 'opt_2', $f['water']['id'] => true, $f['source']['id'] => 'Puits',
            $f['suggest']['id'] => 'ok', $f['geo']['id'] => ['lat' => 9.3375, 'lng' => 2.6301]]);

        $a = $this->getJson("/api/v1/studies/{$f['study']['id']}/analytics")->assertOk()->json();
        $this->assertNotContains('note', array_column($a['questions'], 'type')); // pas de statistique pour un texte informatif
        $town = collect($a['questions'])->firstWhere('type', 'dropdown');
        $this->assertSame(1, collect($town['distribution'])->firstWhere('label', 'Cotonou')['count']);
        $age = collect($a['questions'])->firstWhere('type', 'integer');
        $this->assertEquals(34, $age['stats']['average']);

        $csv = $this->get("/api/v1/studies/{$f['study']['id']}/exports")->assertOk()->streamedContent();
        $this->assertStringContainsString('9.338, 2.63', $csv);      // position arrondie à 3 décimales
        $this->assertStringNotContainsString('Merci de répondre', $csv); // pas de colonne pour le texte informatif
        $this->get("/api/v1/studies/{$f['study']['id']}/exports?format=xlsx")->assertOk();
    }
}
