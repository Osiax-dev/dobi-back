<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Study;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Changement d'adresse e-mail, transfert de propriété et suppression de compte. */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Test annulé pour protéger la base : exécutez « php artisan config:clear » et vérifiez phpunit.xml (DB_CONNECTION=sqlite).');
        }
    }

    /** Lien de confirmation du dernier e-mail envoyé (mailer « array » des tests). */
    private function lastLink(string $needle): string
    {
        $message = app('mailer')->getSymfonyTransport()->messages()->last();
        $html = $message->getOriginalMessage()->getHtmlBody();
        $this->assertSame(1, preg_match('/href="([^"]*'.preg_quote($needle, '/').'[^"]*)"/', $html, $m), 'lien introuvable dans le mail');

        return html_entity_decode($m[1]);
    }

    public function test_email_change_needs_password_and_a_free_new_address(): void
    {
        $user = User::factory()->create(['password' => 'Motdepasse1']);
        $taken = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/email', ['email' => 'nouveau@example.com', 'current_password' => 'faux'])->assertStatus(422);
        $this->putJson('/api/v1/me/email', ['email' => $taken->email, 'current_password' => 'Motdepasse1'])->assertStatus(422);
        $this->putJson('/api/v1/me/email', ['email' => $user->email, 'current_password' => 'Motdepasse1'])->assertStatus(422);

        $this->assertNotSame('nouveau@example.com', $user->fresh()->email);
    }

    public function test_email_only_changes_after_the_link_is_opened(): void
    {
        $user = User::factory()->create(['password' => 'Motdepasse1', 'email' => 'ancien@example.com']);
        $user->createToken('web');
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me/email', ['email' => 'Nouveau@Example.com', 'current_password' => 'Motdepasse1'])->assertOk();
        $this->assertSame('ancien@example.com', $user->fresh()->email, 'rien ne change avant le clic');

        $link = $this->lastLink('email/change');
        $this->app['auth']->forgetGuards();
        $this->get($link)->assertRedirectContains('status=changed');

        $fresh = $user->fresh();
        $this->assertSame('nouveau@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame(0, $fresh->tokens()->count(), 'toutes les sessions sont fermées');

        // Le même lien ne sert qu'une fois (l'adresse d'origine a changé).
        $this->get($link)->assertRedirectContains('status=invalid');
    }

    public function test_tampered_or_expired_email_change_links_do_nothing(): void
    {
        $user = User::factory()->create(['email' => 'ancien@example.com']);

        $expired = \Illuminate\Support\Facades\URL::temporarySignedRoute('email.change.confirm', now()->subMinute(), ['id' => $user->id, 'email' => 'x@example.com', 'from' => sha1($user->email)]);
        $this->get($expired)->assertRedirectContains('status=expired');

        $valid = \Illuminate\Support\Facades\URL::temporarySignedRoute('email.change.confirm', now()->addHour(), ['id' => $user->id, 'email' => 'x@example.com', 'from' => sha1($user->email)]);
        $this->get(str_replace('x%40example.com', 'pirate%40example.com', $valid))->assertRedirectContains('status=invalid');

        $this->assertSame('ancien@example.com', $user->fresh()->email);
    }

    public function test_address_taken_in_the_meantime_is_refused(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'pris@example.com']);

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute('email.change.confirm', now()->addHour(), ['id' => $user->id, 'email' => 'pris@example.com', 'from' => sha1($user->email)]);

        $this->get($link)->assertRedirectContains('status=taken');
    }

    public function test_ownership_transfer(): void
    {
        $owner = User::factory()->create(['password' => 'Motdepasse1']);
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        $admin = User::factory()->create();
        $org->members()->attach($admin->id, ['role' => 'admin']);
        $stranger = User::factory()->create();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/organizations/{$org->id}/transfer", ['user_id' => $admin->id, 'password' => 'faux'])->assertStatus(422);
        $this->postJson("/api/v1/organizations/{$org->id}/transfer", ['user_id' => $stranger->id, 'password' => 'Motdepasse1'])->assertStatus(422);
        $this->postJson("/api/v1/organizations/{$org->id}/transfer", ['user_id' => $owner->id, 'password' => 'Motdepasse1'])->assertStatus(422);

        $this->postJson("/api/v1/organizations/{$org->id}/transfer", ['user_id' => $admin->id, 'password' => 'Motdepasse1'])->assertOk();

        $this->assertSame($admin->id, $org->fresh()->owner_id);
        $this->assertSame('owner', $org->members()->where('users.id', $admin->id)->first()->pivot->role);
        $this->assertSame('admin', $org->members()->where('users.id', $owner->id)->first()->pivot->role);

        // L'ancien propriétaire ne peut plus transférer.
        Sanctum::actingAs(User::find($owner->id)); // le rôle est mis en cache par objet utilisateur : on repart d'un objet neuf
        $this->postJson("/api/v1/organizations/{$org->id}/transfer", ['user_id' => $owner->id, 'password' => 'Motdepasse1'])->assertForbidden();
    }

    public function test_personal_space_cannot_be_transferred(): void
    {
        $owner = User::factory()->create(['password' => 'Motdepasse1']);
        $personal = Organization::createFor($owner, 'Espace personnel', null, 'personal', $owner->name);
        $other = User::factory()->create();
        $personal->members()->attach($other->id, ['role' => 'editor']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$personal->id}/transfer", ['user_id' => $other->id, 'password' => 'Motdepasse1'])->assertStatus(422);
    }

    public function test_account_deletion_is_refused_for_owners_of_work_organizations_until_transfer(): void
    {
        $user = User::factory()->create(['password' => 'Motdepasse1']);
        Organization::createFor($user, 'Espace personnel', null, 'personal', $user->name);
        $org = Organization::createFor($user, 'Association Test', null, 'association');
        $heir = User::factory()->create();
        $org->members()->attach($heir->id, ['role' => 'admin']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/me', ['password' => 'Motdepasse1', 'confirmation' => 'SUPPRIMER'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Association Test'));
        $this->assertNotNull(User::find($user->id));

        $this->postJson("/api/v1/organizations/{$org->id}/transfer", ['user_id' => $heir->id, 'password' => 'Motdepasse1'])->assertOk();
        $this->deleteJson('/api/v1/me', ['password' => 'Motdepasse1', 'confirmation' => 'SUPPRIMER'])->assertOk();

        $this->assertNull(User::find($user->id));
        $this->assertNotNull(Organization::find($org->id), 'l’organisation de travail reste avec son nouveau propriétaire');
        $this->assertSame(0, Organization::where('type', 'personal')->where('owner_id', $user->id)->count());
    }

    public function test_account_deletion_needs_password_and_confirmation_and_keeps_other_peoples_studies(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        $editor = User::factory()->create(['password' => 'Motdepasse1']);
        $org->members()->attach($editor->id, ['role' => 'editor']);
        Sanctum::actingAs($editor);
        $studyId = $this->postJson("/api/v1/organizations/{$org->id}/studies", ['title' => 'Étude de l’éditeur'])->assertCreated()->json('id');

        $this->deleteJson('/api/v1/me', ['password' => 'faux', 'confirmation' => 'SUPPRIMER'])->assertStatus(422);
        $this->deleteJson('/api/v1/me', ['password' => 'Motdepasse1', 'confirmation' => 'supprimer'])->assertStatus(422);
        $this->assertNotNull(User::find($editor->id));

        $this->deleteJson('/api/v1/me', ['password' => 'Motdepasse1', 'confirmation' => 'SUPPRIMER'])->assertOk();

        $this->assertNull(User::find($editor->id));
        $study = Study::find($studyId);
        $this->assertNotNull($study, 'l’étude reste à l’organisation');
        $this->assertNull($study->created_by);
    }
}
