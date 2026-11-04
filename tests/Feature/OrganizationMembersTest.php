<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Les membres arrivent uniquement par invitation : plus d'ajout direct d'un compte par son e-mail. */
class OrganizationMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Test annulé pour protéger la base : exécutez « php artisan config:clear » et vérifiez phpunit.xml (DB_CONNECTION=sqlite).');
        }
    }

    public function test_direct_add_of_a_member_no_longer_exists(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        $other = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$org->id}/members", ['email' => $other->email, 'role' => 'editor'])->assertStatus(405);

        $this->assertFalse($org->members()->wherePivot('user_id', $other->id)->exists());
    }

    public function test_listing_and_role_changes_still_work(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        $member = User::factory()->create();
        $org->members()->attach($member->id, ['role' => 'editor']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/organizations/{$org->id}/members")->assertOk();
        $this->putJson("/api/v1/organizations/{$org->id}/members/{$member->id}", ['role' => 'analyst'])->assertOk();
    }

    public function test_invitation_to_an_organization_still_works(): void
    {
        $owner = User::factory()->create();
        $org = Organization::createFor($owner, 'Association Test', null, 'association');
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/organizations/{$org->id}/invitations", ['email' => 'nouveau@example.com', 'role' => 'editor'])->assertCreated();
    }
}
