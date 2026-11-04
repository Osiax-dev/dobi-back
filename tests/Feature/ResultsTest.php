<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Résultats & analyses : statistiques, filtres, comparaison, exports et droits par rôle, avec de vraies réponses. */
class ResultsTest extends TestCase
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
        $this->owner = User::factory()->create();
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        Sanctum::actingAs($this->owner);
    }

    private function newStudy(string $privacy = 'anonymous'): array
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => "Enquête $privacy", 'privacy_mode' => $privacy])->assertCreated()->json();
        $q = [
            'use' => $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'yes_no', 'label' => 'Utilisez-vous la bibliothèque ?', 'required' => true])->json(),
            'level' => $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'single_choice', 'label' => 'Niveau', 'options' => [['label' => 'Licence'], ['label' => 'Master']]])->json(),
            'age' => $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'integer', 'label' => 'Âge'])->json(),
        ];
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertOk();

        return [$study, $q];
    }

    private function respond(array $study, array $q, bool $use, string $level, int $age, array $extra = []): void
    {
        $this->postJson("/api/v1/public/studies/{$study['slug']}/responses", $extra + ['answers' => [$q['use']['id'] => $use, $q['level']['id'] => $level, $q['age']['id'] => $age]])->assertCreated();
    }

    /** 3 réponses en version 1 (1er octobre), puis nouvelle version, 2 réponses en version 2 (20 octobre). */
    private function seedResponses(): void
    {
        [$this->study, $this->q] = $this->newStudy();
        $this->respond($this->study, $this->q, true, 'opt_1', 20);
        $this->respond($this->study, $this->q, true, 'opt_1', 22);
        $this->respond($this->study, $this->q, false, 'opt_2', 30);
        DB::table('responses')->update(['submitted_at' => '2026-10-01 10:00:00']);

        $this->postJson("/api/v1/studies/{$this->study['id']}/publish")->assertOk(); // version 2
        $this->respond($this->study, $this->q, true, 'opt_2', 24);
        $this->respond($this->study, $this->q, false, 'opt_1', 26);
        DB::table('responses')->where('study_version_id', '>', 1)->update(['submitted_at' => '2026-10-20 09:00:00']);
    }

    private function analytics(string $query = ''): array
    {
        return $this->getJson("/api/v1/studies/{$this->study['id']}/analytics".($query ? "?$query" : ''))->assertOk()->json();
    }

    public function test_statistiques_generales(): void
    {
        $this->seedResponses();
        $a = $this->analytics();

        $this->assertSame(5, $a['totals']['responses']);
        $this->assertCount(20, $a['per_day']);                         // du 1er au 20 octobre, jours vides inclus
        $this->assertSame(3, $a['per_day'][0]['responses']);
        $this->assertSame(0, $a['per_day'][5]['responses']);
        $this->assertSame(2, collect($a['versions'])->count());

        $use = collect($a['questions'])->firstWhere('question_id', $this->q['use']['id']);
        $this->assertSame(100, $use['response_rate']);
        $this->assertSame([3, 2], array_column($use['distribution'], 'count'));  // Oui 3, Non 2

        $age = collect($a['questions'])->firstWhere('question_id', $this->q['age']['id']);
        $this->assertEquals(24.4, $age['stats']['average']);
    }

    public function test_filtres_par_dates_et_par_version(): void
    {
        $this->seedResponses();
        $v2 = collect($this->analytics()['versions'])->firstWhere('version_number', 2)['id'];

        $this->assertSame(2, $this->analytics('from=2026-10-10')['totals']['responses']);
        $this->assertSame(3, $this->analytics('to=2026-10-10')['totals']['responses']);
        $this->assertSame(2, $this->analytics('from=2026-10-20&to=2026-10-20&version='.$v2)['totals']['responses']);
        $this->assertSame(0, $this->analytics('from=2026-10-01&to=2026-10-01&version='.$v2)['totals']['responses']);
        $this->assertSame(2, $this->analytics("version=$v2")['totals']['responses']);
        $this->assertSame(0, $this->analytics('from=2000-01-01&to=2000-01-02')['totals']['responses']);
        $this->getJson("/api/v1/studies/{$this->study['id']}/analytics?from=pas-une-date")->assertStatus(422);
    }

    public function test_comparaison_par_groupe_et_protection_des_petits_groupes(): void
    {
        $this->seedResponses();
        $a = $this->analytics('by='.$this->q['level']['id']);

        $this->assertSame('Niveau', $a['compare']['label']);
        $this->assertSame([3, 2], array_column($a['compare']['groups'], 'size')); // Licence 3, Master 2

        $use = collect($a['questions'])->firstWhere('question_id', $this->q['use']['id']);
        $licence = collect($use['breakdown'])->firstWhere('label', 'Licence');
        $this->assertSame([2, 1], array_column($licence['distribution'], 'count')); // chez les Licence : 2 Oui, 1 Non

        // Avec un seuil de 3, le groupe Master (2 personnes) est masqué : pas de détail qui permettrait d'identifier quelqu'un.
        config(['dobi.min_group_size' => 3]);
        $b = $this->analytics('by='.$this->q['level']['id']);
        $master = collect(collect($b['questions'])->firstWhere('question_id', $this->q['use']['id'])['breakdown'])->firstWhere('label', 'Master');
        $this->assertTrue($master['hidden']);
        $this->assertNull($master['distribution']);

        $this->getJson("/api/v1/studies/{$this->study['id']}/analytics?by={$this->q['age']['id']}")->assertStatus(422); // question numérique
    }

    public function test_les_exports_suivent_les_filtres(): void
    {
        $this->seedResponses();
        $csv = $this->get("/api/v1/studies/{$this->study['id']}/exports?from=2026-10-10")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines); // en-tête + 2 réponses
        $this->assertStringContainsString('Utilisez-vous la bibliothèque ?', $lines[0]);

        $response = $this->get("/api/v1/studies/{$this->study['id']}/exports?format=xlsx")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertSame(6, substr_count($sheet, '<row ')); // en-tête + 5 réponses
        $this->assertStringContainsString('Licence', $sheet); // les libellés, pas les codes opt_1

        $this->get("/api/v1/studies/{$this->study['id']}/exports?format=doc")->assertStatus(422);
    }

    public function test_droits_par_role_sur_les_resultats(): void
    {
        $this->seedResponses();
        $roles = ['analyst' => 200, 'admin' => 200, 'editor' => 403, 'surveyor' => 403];

        foreach ($roles as $role => $expected) {
            $user = User::factory()->create();
            $this->org->members()->attach($user->id, ['role' => $role]);
            Sanctum::actingAs($user);
            $this->getJson("/api/v1/studies/{$this->study['id']}/analytics")->assertStatus($expected);
            $this->get("/api/v1/studies/{$this->study['id']}/exports")->assertStatus($expected);
        }

        Sanctum::actingAs(User::factory()->create()); // étranger à l'organisation
        $this->getJson("/api/v1/studies/{$this->study['id']}/analytics")->assertForbidden();
        $this->get("/api/v1/studies/{$this->study['id']}/exports")->assertForbidden();
    }

    public function test_sans_connexion_les_resultats_sont_refuses(): void
    {
        $this->seedResponses();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson("/api/v1/studies/{$this->study['id']}/analytics")->assertUnauthorized();
    }

    public function test_l_identite_n_apparait_dans_l_export_que_pour_proprietaire_et_admin(): void
    {
        [$study, $q] = $this->newStudy('identified');
        $this->respond($study, $q, true, 'opt_1', 25, ['participant' => ['full_name' => 'Aminata Test', 'email' => 'aminata@example.com'], 'consent' => true]);

        $owner = $this->get("/api/v1/studies/{$study['id']}/exports")->assertOk()->streamedContent();
        $this->assertStringContainsString('Aminata Test', $owner);

        $analyst = User::factory()->create();
        $this->org->members()->attach($analyst->id, ['role' => 'analyst']);
        Sanctum::actingAs($analyst);
        $csv = $this->get("/api/v1/studies/{$study['id']}/exports")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Aminata Test', $csv);
        $this->assertStringNotContainsString('aminata@example.com', $csv);
    }

    public function test_vue_d_ensemble_de_l_espace_est_coherente_avec_les_etudes(): void
    {
        $this->seedResponses();

        $dash = $this->getJson("/api/v1/organizations/{$this->org->id}/dashboard")->assertOk()->json();
        $this->assertSame(5, $dash['kpis']['responses']);
        $this->assertSame(1, $dash['kpis']['studies']);

        $part = $this->getJson("/api/v1/organizations/{$this->org->id}/participation")->assertOk()->json();
        $this->assertSame(5, $part['totals']['responses']);
        $this->assertSame(5, $part['studies'][0]['responses_count']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/organizations/{$this->org->id}/dashboard")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$this->org->id}/participation")->assertForbidden();
    }
}
