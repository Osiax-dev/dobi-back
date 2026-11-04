<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Affectation des enquêteurs par étude et colonne « Enquêteur » des exports. */
class AssignmentsAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $anna;
    private User $bruno;
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
        $this->anna = User::factory()->create(['name' => 'Anna Enquêtrice']);
        $this->bruno = User::factory()->create(['name' => 'Bruno Enquêteur']);
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        $this->org->members()->attach($this->anna->id, ['role' => 'surveyor']);
        $this->org->members()->attach($this->bruno->id, ['role' => 'surveyor']);

        Sanctum::actingAs($this->owner);
        $this->study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Enquête terrain'])->assertCreated()->json();
        $this->qid = $this->postJson("/api/v1/studies/{$this->study['id']}/questions", ['type' => 'short_text', 'label' => 'Votre avis ?', 'required' => true])->assertCreated()->json('id');
        $this->postJson("/api/v1/studies/{$this->study['id']}/publish")->assertOk();
    }

    private function collectAs(User $user): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/collect/studies/{$this->study['slug']}/responses", ['answers' => [$this->qid => 'Bien']]);
    }

    private function assign(array $ids): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->owner);

        return $this->putJson("/api/v1/studies/{$this->study['id']}/assignments", ['user_ids' => $ids]);
    }

    public function test_without_assignment_every_surveyor_can_collect(): void
    {
        $this->collectAs($this->anna)->assertCreated();
        $this->collectAs($this->bruno)->assertCreated();
    }

    public function test_assignment_restricts_collection_to_the_chosen_surveyors(): void
    {
        $this->assign([$this->anna->id])->assertOk()->assertJsonPath('restricted', true);

        $this->collectAs($this->anna)->assertCreated();
        $this->collectAs($this->bruno)->assertForbidden();
        $this->getJson('/api/v1/collect/studies')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/collect/studies')->assertJsonCount(1, 'data');

        // Le propriétaire peut toujours collecter.
        $this->collectAs($this->owner)->assertCreated();
    }

    public function test_clearing_the_assignment_reopens_collection_to_everyone(): void
    {
        $this->assign([$this->anna->id])->assertOk();
        $this->assign([])->assertOk()->assertJsonPath('restricted', false);

        $this->collectAs($this->bruno)->assertCreated();
    }

    public function test_assignment_ignores_surveyors_who_left_the_organization(): void
    {
        $this->assign([$this->anna->id])->assertOk();
        $this->org->members()->detach($this->anna->id);

        $this->collectAs($this->bruno)->assertCreated();
    }

    public function test_only_surveyors_of_the_organization_can_be_assigned(): void
    {
        $analyst = User::factory()->create();
        $this->org->members()->attach($analyst->id, ['role' => 'analyst']);
        $stranger = User::factory()->create();

        $this->assign([$analyst->id])->assertStatus(422);
        $this->assign([$stranger->id])->assertStatus(422);
    }

    public function test_only_owner_and_admin_manage_assignments(): void
    {
        $editor = User::factory()->create();
        $this->org->members()->attach($editor->id, ['role' => 'editor']);

        Sanctum::actingAs($editor);
        $this->getJson("/api/v1/studies/{$this->study['id']}/assignments")->assertForbidden();
        $this->putJson("/api/v1/studies/{$this->study['id']}/assignments", ['user_ids' => []])->assertForbidden();
        Sanctum::actingAs($this->anna);
        $this->getJson("/api/v1/studies/{$this->study['id']}/assignments")->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/studies/{$this->study['id']}/assignments")->assertOk()->assertJsonCount(2, 'surveyors');
    }

    public function test_export_has_an_enqueteur_column(): void
    {
        $this->collectAs($this->anna)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/public/studies/{$this->study['slug']}/responses", ['answers' => [$this->qid => 'Public']])->assertCreated();

        Sanctum::actingAs($this->owner);
        $csv = $this->get("/api/v1/studies/{$this->study['id']}/exports")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(3, $lines);
        $this->assertStringEndsWith('enqueteur', trim($lines[0]));
        $this->assertStringContainsString('Anna Enquêtrice', $csv);
        $this->assertStringContainsString('Lien public', $csv);
    }
}
