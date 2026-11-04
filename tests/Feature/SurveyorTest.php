<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** L'enquêteur collecte uniquement ; ses réponses sont comptées à son nom. */
class SurveyorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $surveyor;
    private User $analyst;
    private Organization $org;
    private array $study;
    private int $qid;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Test annulé pour protéger la base : exécutez « php artisan config:clear » et vérifiez phpunit.xml (DB_CONNECTION=sqlite).');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->surveyor = User::factory()->create(['name' => 'Enquêteur Un']);
        $this->analyst = User::factory()->create();
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        $this->org->members()->attach($this->surveyor->id, ['role' => 'surveyor']);
        $this->org->members()->attach($this->analyst->id, ['role' => 'analyst']);

        Sanctum::actingAs($this->owner);
        $this->study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Enquête terrain'])->assertCreated()->json();
        $this->qid = $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'short_text', 'label' => 'Votre avis ?', 'required' => true])->assertCreated()->json('id');
        // Un brouillon qui ne doit pas apparaître dans la collecte.
        $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Brouillon'])->assertCreated();
        $this->postJson("/api/v1/studies/{$this->study['id']}/publish")->assertOk();
    }

    private function collect(string $text = 'Bien'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/collect/studies/{$this->study['slug']}/responses", ['answers' => [$this->qid => $text]]);
    }

    public function test_surveyor_cannot_reach_the_workspace(): void
    {
        Sanctum::actingAs($this->surveyor);

        $this->getJson("/api/v1/organizations/{$this->org->id}/studies")->assertForbidden();
        $this->getJson("/api/v1/studies/{$this->study['id']}")->assertForbidden();
        $this->getJson("/api/v1/studies/{$this->study['id']}/questionnaire")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$this->org->id}/members")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$this->org->id}/dashboard")->assertForbidden();
        $this->getJson("/api/v1/organizations/{$this->org->id}/participation")->assertForbidden();
        $this->getJson("/api/v1/studies/{$this->study['id']}/analytics")->assertForbidden();
        $this->getJson("/api/v1/studies/{$this->study['id']}/collectors")->assertForbidden();
        $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'short_text', 'label' => 'X'])->assertForbidden();
        $this->postJson("/api/v1/studies/{$this->study['id']}/suspend")->assertForbidden();
    }

    public function test_other_roles_still_see_the_workspace(): void
    {
        Sanctum::actingAs($this->analyst);
        $this->getJson("/api/v1/organizations/{$this->org->id}/studies")->assertOk();
        $this->getJson("/api/v1/organizations/{$this->org->id}/dashboard")->assertOk();
    }

    public function test_surveyor_lists_only_published_studies_to_collect(): void
    {
        Sanctum::actingAs($this->surveyor);

        $res = $this->getJson('/api/v1/collect/studies')->assertOk();

        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Enquête terrain')->assertJsonPath('data.0.mine', 0);
    }

    public function test_analyst_cannot_collect_and_sees_no_collect_list(): void
    {
        Sanctum::actingAs($this->analyst);

        $this->collect()->assertForbidden();
        $this->getJson('/api/v1/collect/studies')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_collection_requires_login(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/collect/studies/{$this->study['slug']}/responses", ['answers' => [$this->qid => 'Bien']])->assertUnauthorized();
    }

    public function test_collected_responses_are_attributed_and_validated(): void
    {
        Sanctum::actingAs($this->surveyor);

        $this->collect('Très bien')->assertCreated();
        $this->collect('Bien')->assertCreated();
        // Même validation que le formulaire public : réponse obligatoire manquante.
        $this->postJson("/api/v1/collect/studies/{$this->study['slug']}/responses", ['answers' => []])->assertStatus(422);

        $this->getJson('/api/v1/collect/studies')->assertJsonPath('data.0.mine', 2);
    }

    public function test_owner_follows_collectors_and_public_link_separately(): void
    {
        Sanctum::actingAs($this->surveyor);
        $this->collect('A')->assertCreated();
        $this->collect('B')->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/public/studies/{$this->study['slug']}/responses", ['answers' => [$this->qid => 'C']])->assertCreated();

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/studies/{$this->study['id']}/collectors")->assertOk()
            ->assertJsonPath('data.0.name', 'Enquêteur Un')
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('public_link', 1);
    }

    public function test_collection_is_refused_on_a_suspended_study(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/studies/{$this->study['id']}/suspend")->assertOk();

        Sanctum::actingAs($this->surveyor);
        $this->collect()->assertNotFound();
        $this->getJson('/api/v1/collect/studies')->assertJsonCount(0, 'data');
    }
}
