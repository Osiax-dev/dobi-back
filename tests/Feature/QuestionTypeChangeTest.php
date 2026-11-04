<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\QuestionOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Changer le type d'une question : libre sans réponses, limité aux types compatibles ensuite. */
class QuestionTypeChangeTest extends TestCase
{
    use RefreshDatabase;

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
        $owner = User::factory()->create();
        $this->org = Organization::createFor($owner, 'Association Test', null, 'association');
        Sanctum::actingAs($owner);
    }

    private function study(): array
    {
        return $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Etude', 'privacy_mode' => 'anonymous'])->assertCreated()->json();
    }

    private function question(int $studyId, array $body): array
    {
        return $this->postJson("/api/v1/studies/$studyId/questions", $body + ['label' => 'Question ?'])->assertCreated()->json();
    }

    public function test_type_is_free_to_change_without_answers_and_choices_are_cleaned(): void
    {
        $study = $this->study();
        $q = $this->question($study['id'], ['type' => 'short_text']);

        $this->putJson("/api/v1/questions/{$q['id']}", ['type' => 'single_choice', 'options' => [['label' => 'Oui'], ['label' => 'Non']]])
            ->assertOk()->assertJsonPath('type', 'single_choice');
        $this->assertSame(2, QuestionOption::where('question_id', $q['id'])->count());

        $this->putJson("/api/v1/questions/{$q['id']}", ['type' => 'date'])->assertOk()->assertJsonPath('type', 'date');
        $this->assertSame(0, QuestionOption::where('question_id', $q['id'])->count());
    }

    public function test_with_answers_only_compatible_types_are_accepted(): void
    {
        $study = $this->study();
        $q = $this->question($study['id'], ['type' => 'short_text', 'required' => true]);
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertOk();
        $this->postJson("/api/v1/public/studies/{$study['slug']}/responses", ['answers' => [$q['id'] => 'Bien'], 'consent' => true])->assertCreated();

        $this->putJson("/api/v1/questions/{$q['id']}", ['type' => 'single_choice', 'options' => [['label' => 'A'], ['label' => 'B']]])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $this->putJson("/api/v1/questions/{$q['id']}", ['type' => 'long_text'])->assertOk()->assertJsonPath('type', 'long_text');
    }

    public function test_question_used_by_a_condition_cannot_become_an_info_text(): void
    {
        $study = $this->study();
        $q = $this->question($study['id'], ['type' => 'yes_no']);
        $this->postJson("/api/v1/studies/{$study['id']}/sections", [
            'title' => 'Suite', 'rules' => [['source_question_id' => $q['id'], 'operator' => 'equals', 'value' => 'yes']],
        ])->assertCreated();

        $this->putJson("/api/v1/questions/{$q['id']}", ['type' => 'note'])->assertStatus(422)->assertJsonValidationErrors('type');
    }
}
