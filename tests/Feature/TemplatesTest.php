<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Question;
use App\Models\Section;
use App\Models\Study;
use App\Models\User;
use App\Support\BuiltinTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Modèles de formulaires : modèles DOBI, modèles de l'organisation, création d'une étude depuis un modèle. */
class TemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Organization $org;

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
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        Sanctum::actingAs($this->owner);
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        $this->org->members()->attach($u->id, ['role' => $role]);

        return $u;
    }

    public function test_every_builtin_template_creates_a_study_that_can_be_published(): void
    {
        foreach (array_keys(BuiltinTemplates::all()) as $key) {
            $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => "Étude $key", 'template' => "dobi:$key"])
                ->assertCreated()->json();

            $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertOk();
        }
    }

    public function test_list_contains_builtin_and_organization_templates(): void
    {
        $this->getJson("/api/v1/organizations/{$this->org->id}/templates")->assertOk()
            ->assertJsonCount(count(BuiltinTemplates::all()), 'builtin')
            ->assertJsonCount(0, 'organization');
    }

    public function test_household_template_keeps_steps_questions_and_conditions(): void
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'Mon enquête', 'template' => 'dobi:menages', 'privacy_mode' => 'identified'])
            ->assertCreated()->assertJsonPath('privacy_mode', 'identified')->assertJsonPath('status', 'draft')->json();

        $this->assertSame(3, Section::where('study_id', $study['id'])->count());
        $this->assertSame(11, Question::where('study_id', $study['id'])->count());

        $water = Question::where('study_id', $study['id'])->where('label', 'like', '%accès à l’eau%')->firstOrFail();
        $source = Question::where('study_id', $study['id'])->where('label', 'like', '%source d’eau%')->with('rules')->firstOrFail();
        $this->assertSame($water->id, $source->rules->first()->source_question_id, 'la condition doit pointer vers la nouvelle question');
        $this->assertSame(['allow_other' => true], Question::where('study_id', $study['id'])->where('type', 'single_choice')->where('label', 'like', '%logement%')->firstOrFail()->settings);
    }

    public function test_save_a_study_as_template_then_reuse_it_with_new_ids(): void
    {
        $source = $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'Source', 'template' => 'dobi:evenement'])->assertCreated()->json();

        $this->postJson("/api/v1/studies/{$source['id']}/template", ['name' => 'Mon modèle événement', 'description' => 'Pour nos ateliers'])
            ->assertCreated();

        $list = $this->getJson("/api/v1/organizations/{$this->org->id}/templates")->assertOk()
            ->assertJsonCount(1, 'organization')->assertJsonPath('organization.0.name', 'Mon modèle événement')
            ->assertJsonPath('organization.0.questions_count', 5)->assertJsonPath('organization.0.sections_count', 2);
        $templateId = $list->json('organization.0.id');

        $copy = $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'Atelier 2026', 'template' => $templateId])->assertCreated()->json();

        $this->assertNotSame($source['id'], $copy['id']);
        $this->assertSame(5, Question::where('study_id', $copy['id'])->count());
        $this->assertEmpty(Question::where('study_id', $copy['id'])->whereIn('id', Question::where('study_id', $source['id'])->pluck('id'))->get());
        // Les conditions d'étape sont recopiées vers les nouvelles questions.
        $rule = Section::where('study_id', $copy['id'])->has('rules')->with('rules')->firstOrFail()->rules->first();
        $this->assertSame($copy['id'], Question::findOrFail($rule->source_question_id)->study_id);
        $this->postJson("/api/v1/studies/{$copy['id']}/publish")->assertOk();
    }

    public function test_empty_study_cannot_become_a_template(): void
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Vide'])->assertCreated()->json();

        $this->postJson("/api/v1/studies/{$study['id']}/template", ['name' => 'Vide'])->assertStatus(422);
    }

    public function test_unknown_template_and_missing_title_are_refused(): void
    {
        $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'X', 'template' => 'dobi:nexistepas'])->assertNotFound();
        $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['template' => 'dobi:menages'])->assertStatus(422);
    }

    public function test_roles(): void
    {
        foreach (['analyst', 'surveyor'] as $role) {
            Sanctum::actingAs($this->member($role));
            $this->getJson("/api/v1/organizations/{$this->org->id}/templates")->assertForbidden();
            $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'X', 'template' => 'dobi:menages'])->assertForbidden();
        }

        Sanctum::actingAs($this->member('editor'));
        $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'Par éditeur', 'template' => 'dobi:menages'])->assertCreated();
    }

    public function test_templates_belong_to_their_organization(): void
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'Source', 'template' => 'dobi:menages'])->json();
        $templateId = $this->postJson("/api/v1/studies/{$study['id']}/template", ['name' => 'Privé'])->json('id');

        $stranger = User::factory()->create();
        $other = Organization::createFor($stranger, 'Autre organisation', null, 'association');
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/organizations/{$this->org->id}/templates")->assertForbidden();
        $this->postJson("/api/v1/organizations/{$other->id}/studies/from-template", ['title' => 'Vol', 'template' => $templateId])->assertNotFound();
        $this->deleteJson("/api/v1/templates/$templateId")->assertForbidden();
        $this->assertSame(0, Study::where('organization_id', $other->id)->count());
    }

    public function test_delete_rules(): void
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies/from-template", ['title' => 'Source', 'template' => 'dobi:menages'])->json();
        $ownerTemplate = $this->postJson("/api/v1/studies/{$study['id']}/template", ['name' => 'Du propriétaire'])->json('id');

        $editor = $this->member('editor');
        Sanctum::actingAs($editor);
        $mine = $this->postJson("/api/v1/studies/{$study['id']}/template", ['name' => 'De l’éditeur'])->json('id');

        $this->deleteJson("/api/v1/templates/$ownerTemplate")->assertForbidden();
        $this->deleteJson("/api/v1/templates/$mine")->assertOk();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/templates/$ownerTemplate")->assertOk();
        $this->assertSame(0, \App\Models\StudyTemplate::count());
    }
}
