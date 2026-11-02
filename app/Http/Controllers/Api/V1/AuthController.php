<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        return response()->json([
            'user' => $user,
            'organization' => $organization,
            'token' => $this->issueToken($user),
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

        AuditLogger::log($user, 'auth.login', $user);

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user),
        ]);
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

        return response()->json(['user' => $user]);
    }

    private function issueToken(User $user): string
    {
        return $user->createToken('web', ['*'], now()->addDays(config('dobi.token_days')))->plainTextToken;
    }
}
