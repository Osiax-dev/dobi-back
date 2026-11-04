<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Paramètres du compte (nom, mot de passe, appareils) et de l'organisation (modifier, quitter). */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Test annulé pour protéger la base : exécutez « php artisan config:clear » et vérifiez phpunit.xml (DB_CONNECTION=sqlite).');
        }
    }

    public function test_name_can_be_changed(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Ancien']));

        $this->putJson('/api/v1/me', ['name' => 'Nouveau nom'])->assertOk()->assertJsonPath('user.name', 'Nouveau nom');
    }

    public function test_password_change_needs_current_password(): void
    {
        $user = User::factory()->create(['password' => 'Ancien1234']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/me', ['current_password' => 'faux', 'password' => 'Nouveau1234', 'password_confirmation' => 'Nouveau1234'])
            ->assertStatus(422);
        $this->putJson('/api/v1/me', ['current_password' => 'Ancien1234', 'password' => 'Nouveau1234', 'password_confirmation' => 'Nouveau1234'])
            ->assertOk();
    }

    public function test_password_change_logs_out_other_devices_only(): void
    {
        $user = User::factory()->create(['password' => 'Ancien1234']);
        $current = $user->createToken('web')->plainTextToken;
        $user->createToken('telephone');
        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($current)->putJson('/api/v1/me', ['current_password' => 'Ancien1234', 'password' => 'Nouveau1234', 'password_confirmation' => 'Nouveau1234'])
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->withToken($current)->getJson('/api/v1/me')->assertOk();
    }

    public function test_logout_other_devices_keeps_the_current_one(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('web')->plainTextToken;
        $user->createToken('autre1');
        $user->createToken('autre2');

        $this->withToken($current)->postJson('/api/v1/me/logout-others')->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->withToken($current)->getJson('/api/v1/me')->assertOk();
    }

    public function test_member_can_leave_but_not_owner_or_personal_space(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        $member = User::factory()->create();
        $org->members()->attach($member->id, ['role' => 'editor']);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/organizations/{$org->id}/leave")->assertStatus(422);

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/organizations/{$org->id}/leave")->assertOk();
        $this->assertFalse($org->members()->wherePivot('user_id', $member->id)->exists());

        $personal = Organization::createFor($member, 'Espace personnel', null, 'personal', $member->name);
        $this->postJson("/api/v1/organizations/{$personal->id}/leave")->assertStatus(422);
    }

    public function test_a_stranger_cannot_leave_an_organization_they_do_not_belong_to(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/organizations/{$org->id}/leave")->assertForbidden();
    }

    public function test_only_owner_and_admin_edit_the_organization(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        $editor = User::factory()->create();
        $org->members()->attach($editor->id, ['role' => 'editor']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/v1/organizations/{$org->id}", ['name' => 'Piraté'])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/organizations/{$org->id}", ['name' => 'Nouveau nom', 'description' => 'Texte'])->assertOk()
            ->assertJsonPath('name', 'Nouveau nom');
    }
}
