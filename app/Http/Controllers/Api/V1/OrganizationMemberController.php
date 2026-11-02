<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationMemberController extends Controller
{
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return response()->json(['data' => $organization->members()->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role,
        ])]);
    }

    /** Ajoute un utilisateur déjà inscrit (l'invitation par e-mail viendra dans une phase ultérieure). */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        if ($organization->type === 'personal') {
            return response()->json(['message' => 'Un espace personnel n\'a pas de membres. Créez une organisation pour travailler en équipe.'], 422);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::in(Roles::ASSIGNABLE)],
        ]);

        if ($data['role'] === Roles::ADMIN && $request->user()->roleIn($organization) !== Roles::OWNER) {
            abort(403, 'Seul le propriétaire peut nommer un administrateur.');
        }

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($organization->members()->wherePivot('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Cet utilisateur est déjà membre.'], 422);
        }

        $organization->members()->attach($user->id, ['role' => $data['role']]);
        AuditLogger::log($request->user(), 'member.added', $user, $organization->id, ['role' => $data['role']]);

        return response()->json(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $data['role']], 201);
    }

    public function update(Request $request, Organization $organization, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        $data = $request->validate(['role' => ['required', Rule::in(Roles::ASSIGNABLE)]]);
        $current = $organization->members()->wherePivot('user_id', $user->id)->first()?->pivot->role;

        if ($current === null) {
            abort(404, 'Membre introuvable.');
        }
        if ($current === Roles::OWNER) {
            return response()->json(['message' => 'Le rôle du propriétaire ne peut pas être modifié.'], 422);
        }
        $actorIsOwner = $request->user()->roleIn($organization) === Roles::OWNER;
        if (! $actorIsOwner && ($current === Roles::ADMIN || $data['role'] === Roles::ADMIN)) {
            abort(403, 'Seul le propriétaire peut gérer les administrateurs.');
        }

        $organization->members()->updateExistingPivot($user->id, ['role' => $data['role']]);
        AuditLogger::log($request->user(), 'member.role_changed', $user, $organization->id, ['from' => $current, 'to' => $data['role']]);

        return response()->json(['id' => $user->id, 'role' => $data['role']]);
    }

    public function destroy(Request $request, Organization $organization, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        $current = $organization->members()->wherePivot('user_id', $user->id)->first()?->pivot->role;

        if ($current === null) {
            abort(404, 'Membre introuvable.');
        }
        if ($current === Roles::OWNER) {
            return response()->json(['message' => 'Le propriétaire ne peut pas être retiré.'], 422);
        }
        if ($current === Roles::ADMIN && $request->user()->roleIn($organization) !== Roles::OWNER) {
            abort(403, 'Seul le propriétaire peut retirer un administrateur.');
        }

        $organization->members()->detach($user->id);
        AuditLogger::log($request->user(), 'member.removed', $user, $organization->id);

        return response()->json(['message' => 'Membre retiré.']);
    }
}
