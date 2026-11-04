<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use App\Support\Roles;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        [$user, $organization] = DB::transaction(function () use ($data) {
            $user = User::create($data);
            // Espace personnel créé automatiquement.
            $organization = Organization::createFor($user, 'Espace personnel', null, 'personal', $user->name);

            return [$user, $organization];
        });

        AuditLogger::log($user, 'auth.registered', $user, $organization->id);

        $this->sendVerification($user);

        // Aucun token avant la vérification de l'adresse e-mail.
        return response()->json([
            'verification_required' => true,
            'email' => $user->email,
            'message' => 'Compte créé. Un e-mail de confirmation vous a été envoyé : cliquez sur le lien pour activer votre compte.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants incorrects.'], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Confirmez votre adresse e-mail avant de vous connecter. Vous pouvez demander un nouveau lien.',
                'code' => 'email_not_verified',
                'email' => $user->email,
            ], 403);
        }

        AuditLogger::log($user, 'auth.login', $user);

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user),
        ]);
    }

    /** Lien signé reçu par e-mail : confirme l'adresse puis renvoie vers le front. */
    public function verifyEmail(Request $request, int $id, string $hash): RedirectResponse
    {
        $front = rtrim(config('dobi.frontend_url'), '/').'/email-verifie?status=';

        if (! $request->hasValidSignature()) {
            $expires = (int) $request->query('expires', 0);

            return redirect()->away($front.($expires > 0 && $expires < now()->getTimestamp() ? 'expired' : 'invalid'));
        }

        $user = User::find($id);
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($front.'invalid');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            AuditLogger::log($user, 'auth.email_verified', $user);
        }

        return redirect()->away($front.'ok');
    }

    /** Renvoi du lien. Réponse identique que le compte existe ou non (pas d'énumération). */
    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $this->sendVerification($user);
        }

        return response()->json(['message' => 'Si un compte non confirmé existe pour cette adresse, un nouvel e-mail vient d\'être envoyé.']);
    }

    private function sendVerification(User $user): void
    {
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::error('Envoi e-mail de vérification impossible : '.$e->getMessage(), ['user_id' => $user->id]);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Même réponse que l'e-mail existe ou non (évite l'énumération de comptes).
        return response()->json(['message' => 'Si ce compte existe, un lien de réinitialisation a été envoyé.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset($data + ['password_confirmation' => $request->input('password_confirmation')], function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete(); // déconnecte toutes les sessions
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified(); // le lien reçu par e-mail prouve la propriété de l'adresse
            }
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        }

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'organizations' => $user->organizations()->get()->map(fn ($o) => [
                'id' => $o->id, 'name' => $o->name, 'slug' => $o->slug, 'type' => $o->type, 'role' => $o->pivot->role,
            ]),
        ]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        if (isset($data['password']) && ! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }

        $user->fill(array_filter([
            'name' => $data['name'] ?? null,
            'password' => $data['password'] ?? null,
        ]))->save();

        // Mot de passe changé : les autres appareils doivent se reconnecter.
        if (isset($data['password'])) {
            $this->revokeOtherTokens($user);
            AuditLogger::log($user, 'auth.password_changed', $user);
        }

        return response()->json(['user' => $user]);
    }

    /** Étape 1 du changement d'adresse : un lien signé (1 h) est envoyé à la NOUVELLE adresse. Rien ne change avant le clic. */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'current_password' => ['required', 'string'],
        ], [
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'email.email' => 'Adresse e-mail invalide.',
            'email.required' => 'Indiquez la nouvelle adresse e-mail.',
            'current_password.required' => 'Indiquez votre mot de passe actuel.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }
        $new = Str::lower(trim($data['email']));
        if ($new === Str::lower($user->email)) {
            return response()->json(['message' => 'Cette adresse est déjà la vôtre.'], 422);
        }

        $url = URL::temporarySignedRoute('email.change.confirm', now()->addHour(), [
            'id' => $user->id, 'email' => $new, 'from' => sha1($user->email),
        ]);

        try {
            Mail::send(['emails.change-email', 'emails.change-email-text'], ['name' => $user->name, 'url' => $url, 'email' => $new],
                fn ($message) => $message->to($new)->subject('Confirmez votre nouvelle adresse e-mail - DOBI'));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'L’e-mail n’a pas pu être envoyé. Réessayez dans quelques minutes.'], 503);
        }

        return response()->json(['message' => "Un e-mail de confirmation a été envoyé à $new. Cliquez sur le lien (valable 1 heure) pour valider le changement."]);
    }

    /** Étape 2 : lien reçu à la nouvelle adresse. Applique le changement puis redirige vers le front. */
    public function confirmEmailChange(Request $request, int $id): RedirectResponse
    {
        $front = rtrim(config('dobi.frontend_url'), '/').'/email-verifie?status=';

        if (! $request->hasValidSignature()) {
            $expires = (int) $request->query('expires', 0);

            return redirect()->away($front.($expires > 0 && $expires < now()->getTimestamp() ? 'expired' : 'invalid'));
        }

        $user = User::find($id);
        $new = Str::lower((string) $request->query('email'));
        // Le lien n'est valable que tant que l'adresse d'origine n'a pas changé entre-temps.
        if (! $user || $new === '' || ! hash_equals(sha1($user->email), (string) $request->query('from'))) {
            return redirect()->away($front.'invalid');
        }
        if (User::where('email', $new)->where('id', '!=', $user->id)->exists()) {
            return redirect()->away($front.'taken');
        }

        $old = $user->email;
        $user->forceFill(['email' => $new, 'email_verified_at' => now()])->save();
        $user->tokens()->delete(); // reconnexion nécessaire sur tous les appareils
        AuditLogger::log($user, 'auth.email_changed', $user);

        try {
            Mail::raw("Bonjour {$user->name},\n\nL'adresse e-mail de votre compte DOBI a été modifiée. Si vous n'êtes pas à l'origine de ce changement, contactez immédiatement l'administrateur de la plateforme.\n\nDOBI", fn ($m) => $m->to($old)->subject('Votre adresse e-mail DOBI a été modifiée'));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->away($front.'changed');
    }

    /** Suppression définitive du compte. Refusée tant que l'utilisateur est propriétaire d'une organisation de travail. */
    public function destroyMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'in:SUPPRIMER'],
        ], ['confirmation.in' => 'Tapez SUPPRIMER en majuscules pour confirmer.']);

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Mot de passe incorrect.'], 422);
        }

        $owned = $user->organizations()->wherePivot('role', Roles::OWNER)->where('organizations.type', '!=', 'personal')->pluck('organizations.name');
        if ($owned->isNotEmpty()) {
            return response()->json([
                'message' => 'Vous êtes propriétaire de : '.$owned->implode(', ').'. Transférez-en la propriété à un autre membre avant de supprimer votre compte.',
            ], 422);
        }

        AuditLogger::log($user, 'auth.account_deleted', $user);
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete(); // l'espace personnel et ses études partent avec le compte
        });

        return response()->json(['message' => 'Compte supprimé.']);
    }

    /** Déconnecte tous les autres appareils (la session actuelle reste ouverte). */
    public function logoutOthers(Request $request): JsonResponse
    {
        $this->revokeOtherTokens($request->user());

        return response()->json(['message' => 'Les autres appareils ont été déconnectés.']);
    }

    private function revokeOtherTokens(User $user): void
    {
        $current = $user->currentAccessToken();
        $query = $user->tokens();
        if ($current && isset($current->id)) {
            $query->where('id', '!=', $current->id);
        }
        $query->delete();
    }

    private function issueToken(User $user): string
    {
        return $user->createToken('web', ['*'], now()->addDays(config('dobi.token_days')))->plainTextToken;
    }
}
