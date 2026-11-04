<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** « Une seule réponse par personne » : bloquée par e-mail en mode identifié, désactivée par défaut. */
class OneResponsePerPersonTest extends TestCase
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

    /** Étude publiée avec une seule question. Retourne [slug, id de la question]. */
    private function publishedStudy(string $privacy, bool $once): array
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", [
            'title' => 'Enquête '.$privacy, 'privacy_mode' => $privacy, 'one_response_per_person' => $once,
        ])->assertCreated()->json();
        $q = $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'Votre avis ?', 'required' => true])->assertCreated()->json();
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertOk();

        return [$study['slug'], $q['id']];
    }

    private function answer(string $slug, int $qid, string $email, string $name = 'Ot'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/public/studies/$slug/responses", [
            'answers' => [$qid => 'Bien'],
            'consent' => true,
            'participant' => ['full_name' => $name, 'email' => $email],
        ]);
    }

    public function test_setting_is_off_by_default_and_can_be_changed(): void
    {
        $study = $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Etude'])->assertCreated()->json();
        $this->assertFalse($study['one_response_per_person']);

        $this->putJson("/api/v1/studies/{$study['id']}", ['one_response_per_person' => true])->assertOk()
            ->assertJsonPath('one_response_per_person', true);
    }

    public function test_public_study_exposes_the_setting(): void
    {
        [$slug] = $this->publishedStudy('anonymous', true);

        $this->getJson("/api/v1/public/studies/$slug")->assertOk()->assertJsonPath('study.one_response_per_person', true);
    }

    public function test_identified_study_refuses_second_answer_from_same_email(): void
    {
        [$slug, $qid] = $this->publishedStudy('identified', true);

        $this->answer($slug, $qid, 'ot@example.com')->assertCreated();
        // Même adresse, casse et espaces différents : refusée.
        $this->answer($slug, $qid, ' OT@Example.com ', 'Autre nom')->assertStatus(409)->assertJsonPath('code', 'already_responded');
        // Autre adresse : acceptée.
        $this->answer($slug, $qid, 'autre@example.com')->assertCreated();
    }

    public function test_without_the_setting_the_same_email_can_answer_again(): void
    {
        [$slug, $qid] = $this->publishedStudy('identified', false);

        $this->answer($slug, $qid, 'ot@example.com')->assertCreated();
        $this->answer($slug, $qid, 'ot@example.com')->assertCreated();
    }

    public function test_same_email_can_answer_a_different_study(): void
    {
        [$slugA, $qidA] = $this->publishedStudy('identified', true);
        [$slugB, $qidB] = $this->publishedStudy('identified', true);

        $this->answer($slugA, $qidA, 'ot@example.com')->assertCreated();
        $this->answer($slugB, $qidB, 'ot@example.com')->assertCreated();
    }
}
