<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Vérification d'adresse e-mail : aucun accès tant que le lien signé n'a pas été ouvert. */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Test annulé pour protéger la base : exécutez « php artisan config:clear » et vérifiez phpunit.xml (DB_CONNECTION=sqlite).');
        }
    }

    private function payload(string $email = 'ot@example.com'): array
    {
        return ['name' => 'Ot', 'email' => $email, 'password' => 'Motdepasse1', 'password_confirmation' => 'Motdepasse1'];
    }

    private function link(User $user, $expires = null): string
    {
        return URL::temporarySignedRoute('verification.verify', $expires ?? now()->addHours(24), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
    }

    public function test_register_sends_mail_and_gives_no_token(): void
    {
        Notification::fake();

        $res = $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $res->assertJsonPath('verification_required', true)->assertJsonMissingPath('token');
        $user = User::where('email', 'ot@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_login_is_refused_until_verified(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', $this->payload());

        $this->postJson('/api/v1/auth/login', ['email' => 'ot@example.com', 'password' => 'Motdepasse1'])
            ->assertForbidden()->assertJsonPath('code', 'email_not_verified')->assertJsonMissingPath('token');

        // Mauvais mot de passe : réponse 401 habituelle, rien ne révèle que le compte existe.
        $this->postJson('/api/v1/auth/login', ['email' => 'ot@example.com', 'password' => 'faux'])->assertUnauthorized();
    }

    public function test_valid_link_verifies_then_login_works(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', $this->payload());
        $user = User::where('email', 'ot@example.com')->firstOrFail();

        $this->get($this->link($user))->assertRedirectContains('/email-verifie?status=ok');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/login', ['email' => 'ot@example.com', 'password' => 'Motdepasse1'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_expired_link_is_reported_and_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->link($user, now()->subMinute()))->assertRedirectContains('status=expired');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_links_are_invalid(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        // Signature modifiée.
        $this->get($this->link($user).'x')->assertRedirectContains('status=invalid');
        // Hash d'une autre adresse, même signée.
        $bad = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($other->email)]);
        $this->get($bad)->assertRedirectContains('status=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_resend_is_generic_and_only_mails_unverified_accounts(): void
    {
        Notification::fake();
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        foreach ([$unverified->email, $verified->email, 'inconnu@example.com'] as $email) {
            $this->postJson('/api/v1/auth/email/resend', ['email' => $email])->assertOk()
                ->assertJsonPath('message', fn ($m) => str_contains($m, 'Si un compte'));
        }

        Notification::assertSentTo($unverified, VerifyEmail::class);
        Notification::assertNotSentTo($verified, VerifyEmail::class);
    }

    public function test_resend_is_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/email/resend', ['email' => $user->email])->assertOk();
        }
        $this->postJson('/api/v1/auth/email/resend', ['email' => $user->email])->assertStatus(429);
    }

    public function test_existing_unverified_token_cannot_use_protected_routes(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/organizations')->assertForbidden();

        $user->markEmailAsVerified();
        $this->app['auth']->forgetGuards(); // chaque vraie requête repart de zéro
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_password_reset_confirms_the_address(): void
    {
        $user = User::factory()->unverified()->create();
        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'Nouveau1234', 'password_confirmation' => 'Nouveau1234',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_mail_is_branded_dobi_not_laravel(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Ot']);

        $html = (string) (new VerifyEmail)->toMail($user)->render();

        $this->assertStringContainsString('DOBI', $html);
        $this->assertStringContainsString('Confirmer mon adresse e-mail', $html);
        $this->assertStringContainsString('/api/v1/auth/email/verify/'.$user->id.'/', $html);
        $this->assertStringNotContainsString('Laravel', $html);
    }
}
