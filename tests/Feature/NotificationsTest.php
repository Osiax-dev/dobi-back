<?php

namespace Tests\Feature;

use App\Models\ActivityNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Alertes dans l'application : le propriétaire et les administrateurs voient ce que font les autres membres. */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $editor;
    private User $analyst;
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
        $this->owner = User::factory()->create(['name' => 'Chef Projet']);
        $this->admin = User::factory()->create(['name' => 'Adjoint']);
        $this->editor = User::factory()->create(['name' => 'Ed Iteur']);
        $this->analyst = User::factory()->create();
        $this->org = Organization::createFor($this->owner, 'Association Test', null, 'association');
        $this->org->members()->attach($this->admin->id, ['role' => 'admin']);
        $this->org->members()->attach($this->editor->id, ['role' => 'editor']);
        $this->org->members()->attach($this->analyst->id, ['role' => 'analyst']);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::find($user->id));
    }

    private function studyBy(User $user): array
    {
        $this->as($user);

        return $this->postJson("/api/v1/organizations/{$this->org->id}/studies", ['title' => 'Enquête ménages', 'privacy_mode' => 'anonymous'])->assertCreated()->json();
    }

    public function test_editor_actions_alert_owner_and_admin_but_not_the_editor_or_analyst(): void
    {
        $study = $this->studyBy($this->editor);
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'Nom ?'])->assertCreated();

        foreach ([$this->owner, $this->admin] as $recipient) {
            $this->as($recipient);
            $res = $this->getJson('/api/v1/notifications')->assertOk();
            $this->assertGreaterThanOrEqual(2, $res->json('unread'));
            $messages = implode(' | ', array_column($res->json('data'), 'message'));
            $this->assertStringContainsString('Ed Iteur a créé l\'étude « Enquête ménages »', $messages);
            $this->assertStringContainsString('Ed Iteur a ajouté une question', $messages);
        }
        foreach ([$this->editor, $this->analyst] as $other) {
            $this->as($other);
            $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'data');
        }
    }

    public function test_the_author_is_not_alerted_about_their_own_action(): void
    {
        $this->studyBy($this->admin);

        $this->as($this->admin);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 0);
        $this->as($this->owner);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 1);
    }

    public function test_repeated_question_edits_are_grouped_into_one_alert(): void
    {
        $study = $this->studyBy($this->editor);
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'A ?'])->assertCreated();
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'B ?'])->assertCreated();
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'C ?'])->assertCreated();

        $row = ActivityNotification::where('user_id', $this->owner->id)->where('event', 'question.created')->get();
        $this->assertCount(1, $row);
        $this->assertSame(3, $row->first()->count);
        $this->assertStringContainsString('3 questions', $row->first()->message);
    }

    public function test_new_responses_alert_owner_and_admin(): void
    {
        $study = $this->studyBy($this->owner);
        $q = $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'Avis ?', 'required' => true])->assertCreated()->json();
        $this->postJson("/api/v1/studies/{$study['id']}/publish")->assertOk();
        $this->postJson("/api/v1/public/studies/{$study['slug']}/responses", ['answers' => [$q['id'] => 'Bien'], 'consent' => true])->assertCreated();
        $this->postJson("/api/v1/public/studies/{$study['slug']}/responses", ['answers' => [$q['id'] => 'Super'], 'consent' => true])->assertCreated();

        $this->as($this->admin);
        $res = $this->getJson('/api/v1/notifications')->assertOk();
        $alert = collect($res->json('data'))->firstWhere('event', 'response.received');
        $this->assertSame(2, $alert['count']);
        $this->assertStringContainsString('2 nouvelles réponses', $alert['message']);
    }

    public function test_member_events_alert_the_managers(): void
    {
        $this->as($this->owner);
        $this->putJson("/api/v1/organizations/{$this->org->id}/members/{$this->editor->id}", ['role' => 'analyst'])->assertOk();

        $this->as($this->admin);
        $messages = implode(' | ', array_column($this->getJson('/api/v1/notifications')->json('data'), 'message'));
        $this->assertStringContainsString('Chef Projet a changé le rôle de Ed Iteur en analyste', $messages);
    }

    public function test_mark_as_read_one_or_all_and_only_my_own(): void
    {
        $study = $this->studyBy($this->editor);
        $this->postJson("/api/v1/studies/{$study['id']}/questions", ['type' => 'short_text', 'label' => 'Nom ?'])->assertCreated();

        $this->as($this->owner);
        $list = $this->getJson('/api/v1/notifications')->assertOk()->json();
        $this->assertSame(2, $list['unread']);

        $this->postJson('/api/v1/notifications/'.$list['data'][0]['id'].'/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 1);

        // L'administrateur ne peut pas marquer comme lue l'alerte du propriétaire.
        $this->as($this->admin);
        $this->postJson('/api/v1/notifications/'.$list['data'][1]['id'].'/read')->assertOk();
        $this->as($this->owner);
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 1);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
    }

    public function test_requires_a_login(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
