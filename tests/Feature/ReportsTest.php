<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Rapports & synthèses : génération à partir de vraies réponses, textes, finalisation, conflits, droits et isolation. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;
    private array $study;
    private array $q;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Test annulé pour protéger la base : lancez « php artisan config:clear » (DB_CONNECTION doit être sqlite).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['dobi.min_group_size' => 2]);
        // Les tests ne doivent jamais dépendre du .env local : IA par défaut = Anthropic simulé, sans aucune clé Groq.
        config(['dobi.ai.provider' => 'anthropic', 'dobi.ai.groq_key' => null, 'dobi.ai.key' => null]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        Sanctum::actingAs($this->owner);
    }

    private function newStudy(array $extra = []): void
    {
        $this->study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Enquête lecture'] + $extra)->assertCreated()->json();
        $this->q = [
            'use' => $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'yes_no', 'label' => 'Utilisez-vous la bibliothèque ?'])->json(),
            'level' => $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'single_choice', 'label' => 'Niveau', 'options' => [['label' => 'Licence'], ['label' => 'Master']]])->json(),
            'age' => $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'integer', 'label' => 'Âge'])->json(),
        ];
        $this->postJson("/api/v1/studies/{$this->study['id']}/publish")->assertOk();
    }

    private function respond(bool $use, string $level, int $age): void
    {
        $this->postJson("/api/v1/public/studies/{$this->study['slug']}/responses", ['answers' => [$this->q['use']['id'] => $use, $this->q['level']['id'] => $level, $this->q['age']['id'] => $age]])->assertCreated();
    }

    private function generate(array $body = []): array
    {
        return $this->postJson("/api/v1/studies/{$this->study['id']}/reports", $body)->assertCreated()->json();
    }

    private function actAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_generation_depuis_les_vraies_donnees(): void
    {
        $this->newStudy(['objective' => 'Mesurer l’usage', 'description' => 'Enquête auprès des étudiants']);
        $this->respond(true, 'opt_1', 20);
        $this->respond(true, 'opt_1', 22);
        $this->respond(false, 'opt_2', 30);

        $r = $this->generate();
        $c = $r['content'];

        $this->assertSame('draft', $r['status']);
        $this->assertSame(1, $r['revision']);
        $this->assertSame(3, $c['meta']['responses']);
        $this->assertSame(3, $c['sample']['responses']);
        $this->assertSame('Association Test', $c['cover']['organization']);
        $this->assertSame('Mesurer l’usage', $r['notes']['objectives']);
        $this->assertSame('Enquête auprès des étudiants', $r['notes']['context']);
        $this->assertSame('', $r['notes']['conclusions']);
        $this->assertFalse($r['outdated']);

        $use = collect($c['results']['questions'])->firstWhere('question_id', $this->q['use']['id']);
        $this->assertSame(3, $use['n']);
        $this->assertSame([2, 1], array_column($use['rows'], 'count'));
        $this->assertEquals(66.7, $use['rows'][0]['percent']);
        $this->assertStringContainsString('« Oui »', $use['explanation']);

        $age = collect($c['results']['questions'])->firstWhere('question_id', $this->q['age']['id']);
        $this->assertEquals(24, $age['stats']['average']);

        // Rien n'est inventé : les champs non renseignés sont marqués null (« à compléter » côté écran).
        $zone = collect($c['general']['fields'])->firstWhere('key', 'geographic_zone');
        $this->assertNull($zone['value']);
        $this->assertNotEmpty($c['analysis']['warnings']); // échantillon réduit
    }

    public function test_etude_sans_reponse(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $this->assertSame(0, $r['content']['sample']['responses']);
        $this->assertNull($r['content']['sample']['peak_day']);
        $q = $r['content']['results']['questions'][0];
        $this->assertSame(0, $q['n']);
        $this->assertSame('Aucune réponse n’a été collectée pour cette question.', $q['explanation']);
        $this->assertStringContainsString('Aucune réponse', $r['content']['analysis']['warnings'][0]);
    }

    public function test_etude_jamais_publiee(): void
    {
        $this->study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Brouillon'])->assertCreated()->json();
        $r = $this->generate();

        $this->assertSame(0, $r['content']['meta']['responses']);
        $this->assertSame([], $r['content']['results']['questions']);
    }

    public function test_petit_groupe_protege_dans_le_rapport(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20); // 1 réponse < seuil de 2

        $q = collect($this->generate()['content']['results']['questions'])->firstWhere('question_id', $this->q['level']['id']);
        $this->assertTrue($q['insufficient']);
        $this->assertSame([], $q['rows']);
        $this->assertStringContainsString('confidentialité', $q['explanation']);
    }

    public function test_liste_consultation_et_rapport_devenu_ancien(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $this->respond(false, 'opt_2', 21);
        $r = $this->generate(['title' => 'Mon rapport']);
        $this->generate();

        $list = $this->getJson("/api/v1/studies/{$this->study['id']}/reports")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertArrayNotHasKey('content', $list[0]);

        $this->respond(true, 'opt_1', 25);
        $shown = $this->getJson("/api/v1/reports/{$r['id']}")->assertOk()->json();
        $this->assertSame('Mon rapport', $shown['title']);
        $this->assertTrue($shown['outdated']);
        $this->assertSame(3, $shown['responses_now']);
        $this->assertSame(2, $shown['content']['meta']['responses']); // le rapport reste figé
    }

    public function test_les_reponses_originales_ne_changent_pas(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $before = DB::table('answers')->orderBy('id')->get()->toArray();
        $r = $this->generate();
        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 1, 'notes' => ['conclusions' => 'Texte'], 'status' => 'final'])->assertOk();
        $this->assertEquals($before, DB::table('answers')->orderBy('id')->get()->toArray());
    }

    public function test_enregistrement_des_textes_et_versions(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $a = $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 1, 'title' => 'Titre 2', 'notes' => ['conclusions' => 'Nos conclusions']])->assertOk()->json();
        $this->assertSame(2, $a['revision']);
        $this->assertSame('Titre 2', $a['title']);
        $this->assertSame('Nos conclusions', $a['notes']['conclusions']);

        // Deuxième enregistrement à partir de l'ancienne révision : refusé, rien n'est écrasé.
        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 1, 'notes' => ['conclusions' => 'Écrasement']])->assertStatus(409)->assertJsonPath('code', 'report_conflict');
        $this->assertSame('Nos conclusions', $this->getJson("/api/v1/reports/{$r['id']}")->json('notes.conclusions'));

        // Un enregistrement partiel garde les autres textes.
        $b = $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 2, 'notes' => ['recommendations' => 'À faire']])->assertOk()->json();
        $this->assertSame('Nos conclusions', $b['notes']['conclusions']);
        $this->assertSame('À faire', $b['notes']['recommendations']);

        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 3, 'notes' => ['inconnue' => 'x']])->assertStatus(422);
        $this->putJson("/api/v1/reports/{$r['id']}", ['notes' => ['conclusions' => 'x']])->assertStatus(422); // revision obligatoire
    }

    public function test_finalisation_et_reouverture(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $f = $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 1, 'status' => 'final'])->assertOk()->json();
        $this->assertSame('final', $f['status']);
        $this->assertNotNull($f['finalized_at']);

        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 2, 'notes' => ['conclusions' => 'Modif']])->assertStatus(422);
        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 2, 'title' => 'Autre'])->assertStatus(422);

        $d = $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 2, 'status' => 'draft'])->assertOk()->json();
        $this->assertSame('draft', $d['status']);
        $this->assertNull($d['finalized_at']);
        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 3, 'notes' => ['conclusions' => 'Modif']])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.finalized']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.reopened']);
    }

    public function test_droits_par_role(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $analyst = $this->member('analyst');
        $editor = $this->member('editor');
        $surveyor = $this->member('surveyor');
        $admin = $this->member('admin');

        $this->actAs($analyst);
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports")->assertCreated();
        $this->getJson("/api/v1/reports/{$r['id']}")->assertOk();
        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 1, 'title' => 'Par analyste'])->assertOk();
        $this->deleteJson("/api/v1/reports/{$r['id']}")->assertForbidden(); // pas l'auteur

        foreach ([$editor, $surveyor] as $user) {
            $this->actAs($user);
            $this->getJson("/api/v1/studies/{$this->study['id']}/reports")->assertForbidden();
            $this->postJson("/api/v1/studies/{$this->study['id']}/reports")->assertForbidden();
            $this->getJson("/api/v1/reports/{$r['id']}")->assertForbidden();
            $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 2, 'title' => 'X'])->assertForbidden();
            $this->deleteJson("/api/v1/reports/{$r['id']}")->assertForbidden();
        }

        $this->actAs($admin);
        $this->deleteJson("/api/v1/reports/{$r['id']}")->assertOk();
        $this->assertDatabaseMissing('reports', ['id' => $r['id']]);
    }

    public function test_un_auteur_peut_supprimer_son_rapport(): void
    {
        $this->newStudy();
        $analyst = $this->member('analyst');
        $this->actAs($analyst);
        $mine = $this->generate();
        $this->assertTrue($this->getJson("/api/v1/reports/{$mine['id']}")->json('can_delete'));
        $this->deleteJson("/api/v1/reports/{$mine['id']}")->assertOk();
    }

    public function test_isolation_entre_organisations(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $stranger = User::factory()->create();
        Organization::createFor($stranger, 'Autre organisation');
        $this->actAs($stranger);

        $this->getJson("/api/v1/studies/{$this->study['id']}/reports")->assertForbidden();
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports")->assertForbidden();
        $this->getJson("/api/v1/reports/{$r['id']}")->assertForbidden();
        $this->putJson("/api/v1/reports/{$r['id']}", ['revision' => 1, 'title' => 'Piraté'])->assertForbidden();
        $this->deleteJson("/api/v1/reports/{$r['id']}")->assertForbidden();
        $this->assertDatabaseHas('reports', ['id' => $r['id'], 'title' => $r['title']]);
    }

    public function test_rapport_d_etude_identifiee_sans_donnees_personnelles(): void
    {
        $this->newStudy(['privacy_mode' => 'identified']);
        $this->postJson("/api/v1/public/studies/{$this->study['slug']}/responses", [
            'answers' => [$this->q['use']['id'] => true, $this->q['level']['id'] => 'opt_1', $this->q['age']['id'] => 20],
            'participant' => ['full_name' => 'Jean Secret', 'email' => 'jean.secret@example.com'],
            'consent' => true,
        ])->assertCreated();

        $r = $this->generate();
        $this->assertSame(1, $r['content']['sample']['participants']);
        $json = json_encode($r);
        $this->assertStringNotContainsString('Jean Secret', $json);
        $this->assertStringNotContainsString('jean.secret@example.com', $json);
    }

    private function save(int $id, int $revision, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/v1/reports/$id", ['revision' => $revision] + $body);
    }

    public function test_mise_en_page_ordre_sections_masquees_et_textes_libres(): void
    {
        $this->newStudy();
        $r = $this->generate();
        $this->assertSame('cover', $r['layout'][0]['key']);
        $this->assertCount(11, $r['layout']);

        $custom = [
            'order' => ['conclusions', 'x1', 'results'],
            'hidden' => ['methodology'],
            'hidden_questions' => [$this->q['age']['id']],
            'explanations' => [(string) $this->q['use']['id'] => 'Mon explication'],
            'extras' => [['id' => 'x1', 'title' => 'Remerciements', 'body' => "Merci.\n\nÀ tous."]],
        ];
        $saved = $this->save($r['id'], 1, ['custom' => $custom])->assertOk()->json();

        $this->assertSame(2, $saved['revision']);
        $keys = array_column($saved['layout'], 'key');
        $this->assertSame(['cover', 'conclusions', 'x1', 'results'], array_slice($keys, 0, 4));
        $this->assertCount(12, $saved['layout']); // 11 sections + 1 paragraphe libre : rien n'est perdu
        $methodology = collect($saved['layout'])->firstWhere('key', 'methodology');
        $this->assertTrue($methodology['hidden']);
        $this->assertNull($methodology['number']);

        $doc = $this->getJson("/api/v1/reports/{$r['id']}/document")->assertOk()->json();
        $titles = array_column($doc['sections'], 'title');
        $this->assertNotContains('Méthodologie de collecte', $titles);
        $this->assertContains('Remerciements', $titles);
        $results = collect($doc['sections'])->firstWhere('key', 'results');
        $text = json_encode($results['blocks'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Mon explication', $text);
        $this->assertStringNotContainsString('« Âge »', $text);
        $this->assertStringNotContainsString('1. Âge', $text);
    }

    public function test_mise_en_page_invalide_refusee(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $this->save($r['id'], 1, ['custom' => ['order' => ['nimporte']]])->assertStatus(422);
        $this->save($r['id'], 1, ['custom' => ['hidden' => ['x9']]])->assertStatus(422);
        $this->save($r['id'], 1, ['custom' => ['explanations' => ['999999' => 'x']]])->assertStatus(422);
        $this->save($r['id'], 1, ['custom' => ['hidden_questions' => [999999]]])->assertStatus(422);
        $this->save($r['id'], 1, ['custom' => ['extras' => [['id' => 'abc', 'title' => 'x']]]])->assertStatus(422);
        $this->save($r['id'], 1, ['custom' => ['extras' => [['id' => 'x1', 'title' => 'a'], ['id' => 'x1', 'title' => 'b']]]])->assertStatus(422);
        $this->assertSame(1, $this->getJson("/api/v1/reports/{$r['id']}")->json('revision')); // rien n'a été enregistré
    }

    public function test_mise_en_page_verrouillee_quand_le_rapport_est_finalise(): void
    {
        $this->newStudy();
        $r = $this->generate();
        $this->save($r['id'], 1, ['status' => 'final'])->assertOk();
        $this->save($r['id'], 2, ['custom' => ['hidden' => ['methodology']]])->assertStatus(422);
        $this->save($r['id'], 2, ['status' => 'draft'])->assertOk();
        $this->save($r['id'], 3, ['custom' => ['hidden' => ['methodology']]])->assertOk();
    }

    public function test_export_word(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $this->respond(false, 'opt_2', 21);
        $r = $this->generate(['title' => 'Mon rapport Word']);

        $res = $this->get("/api/v1/reports/{$r['id']}/export/docx", ['Accept' => '*/*'])->assertOk();
        $this->assertStringContainsString('wordprocessingml.document', $res->headers->get('content-type'));
        $this->assertStringContainsString('mon-rapport-word.docx', $res->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'dobi');
        file_put_contents($path, $res->streamedContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('Mon rapport Word', $xml);
        $this->assertStringContainsString('Principaux résultats', $xml);
        $zip->close();
        unlink($path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_export_et_document_reserves_aux_roles_autorises(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $this->actAs($this->member('editor'));
        $this->getJson("/api/v1/reports/{$r['id']}/document")->assertForbidden();
        $this->get("/api/v1/reports/{$r['id']}/export/docx", ['Accept' => '*/*'])->assertForbidden();

        $stranger = User::factory()->create();
        Organization::createFor($stranger, 'Autre organisation');
        $this->actAs($stranger);
        $this->getJson("/api/v1/reports/{$r['id']}/document")->assertForbidden();
        $this->get("/api/v1/reports/{$r['id']}/export/docx", ['Accept' => '*/*'])->assertForbidden();

        $this->actAs($this->member('analyst'));
        $this->get("/api/v1/reports/{$r['id']}/export/docx", ['Accept' => '*/*'])->assertOk();
    }

    public function test_comparaison_par_groupe_dans_le_rapport(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $this->respond(false, 'opt_1', 21);
        $this->respond(true, 'opt_2', 22);
        $this->respond(true, 'opt_2', 23);

        $r = $this->generate(['compare_by' => $this->q['level']['id']]);
        $this->assertSame('Niveau', $r['content']['compare']['label']);
        $use = collect($r['content']['results']['questions'])->firstWhere('question_id', $this->q['use']['id']);
        $this->assertSame(['Licence', 'Master'], array_column($use['breakdown'], 'label'));
        $licence = $use['breakdown'][0];
        $this->assertFalse($licence['hidden']);
        $this->assertSame([1, 1], array_column($licence['rows'], 'count'));
        $this->assertEquals(50, $licence['rows'][0]['percent']);
        $this->assertNull(collect($r['content']['results']['questions'])->firstWhere('question_id', $this->q['level']['id'])['breakdown']);

        $this->assertNull($this->generate()['content']['compare']);

        // Comparer selon une question numérique n'a pas de sens : refusé.
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports", ['compare_by' => $this->q['age']['id']])->assertStatus(422);
    }

    public function test_evolution_dans_le_temps_conservee(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $this->respond(false, 'opt_2', 21);
        DB::table('responses')->orderBy('id')->limit(1)->update(['submitted_at' => '2026-10-01 10:00:00']);
        DB::table('responses')->orderByDesc('id')->limit(1)->update(['submitted_at' => '2026-10-03 10:00:00']);

        $perDay = $this->generate()['content']['sample']['per_day'];
        $this->assertSame([1, 0, 1], array_column($perDay, 'responses'));
    }

    private function fakeAi(string $text = 'Texte suggéré.', int $status = 200): void
    {
        config(['dobi.ai.key' => 'test-key']);
        // Repart d'un client HTTP neuf : sinon le premier faux enregistré dans le test reste prioritaire.
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(['api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response($status === 200 ? ['content' => [['type' => 'text', 'text' => $text]]] : ['error' => 'x'], $status)]);
    }

    public function test_ia_desactivee_sans_cle(): void
    {
        config(['dobi.ai.key' => null]);
        $this->newStudy();
        $r = $this->generate();
        $this->assertFalse($r['ai_enabled']);
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertStatus(503)->assertJsonPath('code', 'ai_disabled');
    }

    public function test_ia_suggere_un_texte_sans_rien_enregistrer(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $this->respond(false, 'opt_2', 21);
        $r = $this->generate();
        $this->fakeAi('Voici une analyse.');

        $res = $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis', 'instructions' => 'Ton formel', 'current_text' => 'Mon brouillon'])->assertOk();
        $this->assertSame('Voici une analyse.', $res->json('text'));
        $this->assertSame('analysis', $res->json('section'));

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $content = $request['messages'][0]['content'];

            return $request->hasHeader('x-api-key', 'test-key')
                && $request['model'] === 'claude-sonnet-5-5'
                && str_contains($content, 'Utilisez-vous la bibliothèque')
                && str_contains($content, 'Mon brouillon')
                && str_contains($content, 'Ton formel')
                && str_contains($request['system'], 'N\'invente aucun chiffre');
        });

        $after = $this->getJson("/api/v1/reports/{$r['id']}")->json();
        $this->assertTrue($after['ai_enabled']);
        $this->assertSame(1, $after['revision']);          // rien n'est enregistré
        $this->assertSame('', $after['notes']['analysis']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.ai_suggested']);
    }

    public function test_ia_n_envoie_aucun_texte_libre_de_participant(): void
    {
        $this->study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Enquête autre'])->assertCreated()->json();
        $q = $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'single_choice', 'label' => 'Moyen ?', 'settings' => ['allow_other' => true], 'options' => [['label' => 'Bus'], ['label' => 'Moto']]])->assertCreated()->json();
        $this->postJson("/api/v1/studies/{$this->study['id']}/publish")->assertOk();
        foreach (['Trottinette SECRETE', 'Trottinette SECRETE', 'Bus'] as $answer) {
            $value = $answer === 'Bus' ? 'opt_1' : '__dobi_other__:'.$answer;
            $this->postJson("/api/v1/public/studies/{$this->study['slug']}/responses", ['answers' => [$q['id'] => $value]])->assertCreated();
        }
        $r = $this->generate();
        $this->fakeAi();

        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'conclusions'])->assertOk();
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), 'SECRETE') && str_contains($request['messages'][0]['content'], 'Moyen ?'));
    }

    public function test_ia_erreurs_et_limites(): void
    {
        $this->newStudy();
        $r = $this->generate();

        $this->fakeAi();
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'cover'])->assertStatus(422);
        $this->postJson("/api/v1/reports/{$r['id']}/assist", [])->assertStatus(422);
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis', 'instructions' => str_repeat('x', 501)])->assertStatus(422);

        $this->fakeAi('', 500);
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertStatus(502)->assertJsonPath('code', 'ai_unavailable');

        $this->fakeAi('   ');
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertStatus(502);

        $this->fakeAi();
        $this->save($r['id'], 1, ['status' => 'final'])->assertOk();
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertStatus(422); // finalisé
    }

    public function test_ia_reservee_aux_roles_autorises(): void
    {
        $this->newStudy();
        $r = $this->generate();
        $this->fakeAi();

        foreach ([$this->member('editor'), $this->member('surveyor')] as $user) {
            $this->actAs($user);
            $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertForbidden();
        }
        $stranger = User::factory()->create();
        Organization::createFor($stranger, 'Autre organisation');
        $this->actAs($stranger);
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertForbidden();
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $this->actAs($this->member('analyst'));
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertOk();
    }

    public function test_historique_et_restauration(): void
    {
        $this->newStudy();
        $r = $this->generate();
        $id = $r['id'];
        $this->assertSame(0, $r['history_count']);

        $a = $this->putJson("/api/v1/reports/$id", ['revision' => 1, 'title' => 'Version A', 'notes' => ['conclusions' => 'Texte A']])->assertOk()->json();
        $this->assertSame(1, $a['history_count']);
        $this->putJson("/api/v1/reports/$id", ['revision' => 2, 'notes' => ['conclusions' => 'Texte B']])->assertOk();

        // Enregistrer sans rien changer ne crée pas de doublon dans l'historique.
        $this->putJson("/api/v1/reports/$id", ['revision' => 3, 'notes' => ['conclusions' => 'Texte B']])->assertOk();
        $list = $this->getJson("/api/v1/reports/$id/revisions")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertSame([2, 1], array_column($list, 'revision'));
        $this->assertSame($this->owner->name, $list[0]['author']);

        // Relire l'ancien état avant de restaurer.
        $old = $this->getJson("/api/v1/reports/$id/revisions/{$list[1]['id']}")->assertOk()->json();
        $this->assertSame('', $old['notes']['conclusions']);
        $mid = $this->getJson("/api/v1/reports/$id/revisions/{$list[0]['id']}")->assertOk()->json();
        $this->assertSame('Texte A', $mid['notes']['conclusions']);
        $this->assertSame('Version A', $mid['title']);

        // Restauration : mauvaise révision = conflit, bonne révision = succès, et l'état actuel est gardé.
        $this->postJson("/api/v1/reports/$id/revisions/{$list[0]['id']}/restore", ['revision' => 1])->assertStatus(409)->assertJsonPath('code', 'report_conflict');
        $now = $this->getJson("/api/v1/reports/$id")->json();
        $restored = $this->postJson("/api/v1/reports/$id/revisions/{$list[0]['id']}/restore", ['revision' => $now['revision']])->assertOk()->json();
        $this->assertSame('Texte A', $restored['notes']['conclusions']);
        $this->assertSame($now['revision'] + 1, $restored['revision']);
        $latest = $this->getJson("/api/v1/reports/$id/revisions")->json('data.0.id');
        $this->assertSame('Texte B', $this->getJson("/api/v1/reports/$id/revisions/$latest")->json('notes.conclusions')); // rien n'est perdu

        // Rapport finalisé : pas de restauration.
        $this->putJson("/api/v1/reports/$id", ['revision' => $restored['revision'], 'status' => 'final'])->assertOk();
        $this->postJson("/api/v1/reports/$id/revisions/{$list[0]['id']}/restore", ['revision' => $restored['revision'] + 1])->assertStatus(422);
    }

    public function test_historique_limite_et_droits(): void
    {
        $this->newStudy();
        $r = $this->generate();
        $id = $r['id'];
        for ($i = 1; $i <= 35; $i++) {
            $this->putJson("/api/v1/reports/$id", ['revision' => $i, 'notes' => ['analysis' => "Texte $i"]])->assertOk();
        }
        $this->assertCount(30, $this->getJson("/api/v1/reports/$id/revisions")->json('data'));
        $first = $this->getJson("/api/v1/reports/$id/revisions")->json('data.0.id');

        $this->actAs($this->member('editor'));
        $this->getJson("/api/v1/reports/$id/revisions")->assertForbidden();
        $this->getJson("/api/v1/reports/$id/revisions/$first")->assertForbidden();
        $this->postJson("/api/v1/reports/$id/revisions/$first/restore", ['revision' => 36])->assertForbidden();

        // Autre organisation : aucun accès, et une version d'un autre rapport est introuvable.
        $other = User::factory()->create();
        Organization::createFor($other, 'Autre', null, 'company');
        $this->actAs($other);
        $this->getJson("/api/v1/reports/$id/revisions")->assertForbidden();
    }

    public function test_apercu_sans_enregistrement(): void
    {
        $this->newStudy();
        $this->respond(true, 'opt_1', 20);
        $this->respond(false, 'opt_2', 22);

        $p = $this->postJson("/api/v1/studies/{$this->study['id']}/reports/preview", ['title' => 'Mon aperçu'])->assertOk()->json();
        $this->assertTrue($p['preview']);
        $this->assertSame('Mon aperçu', $p['title']);
        $this->assertSame(2, $p['content']['meta']['responses']);
        $this->assertNotEmpty($p['document']['sections']);
        $this->assertSame(0, \App\Models\Report::count());

        // Mêmes règles que la génération : comparaison invalide refusée, droits respectés.
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports/preview", ['compare_by' => $this->q['age']['id']])->assertStatus(422);
        $this->actAs($this->member('surveyor'));
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports/preview")->assertForbidden();
    }

    public function test_informations_de_l_organisation_dans_le_rapport(): void
    {
        $this->org->update(['description' => 'Nous promouvons la lecture.']);
        $this->newStudy();
        $fields = collect($this->generate()['content']['general']['fields'])->pluck('value', 'key');
        $this->assertSame('Association', $fields['organization_type']);
        $this->assertSame('Nous promouvons la lecture.', $fields['organization_description']);
        $this->assertSame('Publiée', $fields['study_status']);
        $this->assertNotEmpty($fields['study_created']);

        // Sans présentation saisie : le champ reste vide (« à compléter »), rien n'est inventé.
        $this->org->update(['description' => null]);
        $this->assertNull(collect($this->generate()['content']['general']['fields'])->pluck('value', 'key')['organization_description']);
    }

    public function test_comparaisons_multiples(): void
    {
        $this->newStudy();
        $extra = $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'yes_no', 'label' => 'Êtes-vous étudiant ?'])->json();
        $this->postJson("/api/v1/studies/{$this->study['id']}/publish")->assertOk();
        foreach ([[true, 'opt_1'], [false, 'opt_1'], [true, 'opt_2'], [true, 'opt_2']] as [$use, $level]) {
            $this->postJson("/api/v1/public/studies/{$this->study['slug']}/responses", ['answers' => [$this->q['use']['id'] => $use, $this->q['level']['id'] => $level, $extra['id'] => $use]])->assertCreated();
        }

        $r = $this->generate(['compare_by' => [$this->q['level']['id'], $this->q['use']['id']]]);
        $this->assertSame(['Niveau', 'Utilisez-vous la bibliothèque ?'], array_column($r['content']['compares'], 'label'));
        $this->assertSame('Niveau', $r['content']['compare']['label']); // compatibilité

        $age = collect($r['content']['results']['questions'])->firstWhere('question_id', $extra['id']);
        $this->assertCount(2, $age['breakdowns']);
        $this->assertSame([$this->q['level']['id'], $this->q['use']['id']], array_column($age['breakdowns'], 'by_id'));
        // Une question n'est jamais comparée à elle-même.
        $use = collect($r['content']['results']['questions'])->firstWhere('question_id', $this->q['use']['id']);
        $this->assertCount(1, $use['breakdowns']);

        // Le document composé contient une comparaison par question choisie.
        $doc = json_encode($this->getJson("/api/v1/reports/{$r['id']}/document")->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('Comparaison selon « Niveau »', $doc);
        $this->assertStringContainsString('Comparaison selon « Utilisez-vous la bibliothèque ? »', $doc);

        // Un entier seul reste accepté (ancien format) ; plus de 3 questions ou une question invalide : refusé.
        $this->assertCount(1, $this->generate(['compare_by' => $this->q['level']['id']])['content']['compares']);
        $many = array_fill(0, 4, $this->q['level']['id']);
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports", ['compare_by' => [$this->q['level']['id'], $this->q['use']['id'], $extra['id'], 999999]])->assertStatus(422);
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports", ['compare_by' => [$this->q['level']['id'], $this->q['age']['id']]])->assertStatus(422);
        $this->postJson("/api/v1/studies/{$this->study['id']}/reports", ['compare_by' => ['abc']])->assertStatus(422);
        $this->assertCount(1, $this->generate(['compare_by' => $many])['content']['compares']); // doublons fusionnés
    }

    public function test_ia_avec_groq(): void
    {
        config(['dobi.ai.provider' => 'groq', 'dobi.ai.groq_key' => 'gsk-test', 'dobi.ai.key' => null]);
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(['api.groq.com/*' => \Illuminate\Support\Facades\Http::response(['choices' => [['message' => ['content' => 'Analyse Groq.']]]])]);

        $this->newStudy();
        $r = $this->generate();
        $this->assertTrue($r['ai_enabled']);
        $this->postJson("/api/v1/reports/{$r['id']}/assist", ['section' => 'analysis'])->assertOk()->assertJsonPath('text', 'Analyse Groq.');
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer gsk-test') && str_contains($req->url(), 'api.groq.com/openai/v1/chat/completions'));

        config(['dobi.ai.groq_key' => null]);
        $this->assertFalse($this->getJson("/api/v1/reports/{$r['id']}")->json('ai_enabled'));
    }
}
