<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->organizations()->withCount(['studies', 'members'])->get()->map(fn ($o) => $o->toArray() + ['role' => $o->pivot->role]);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['company', 'ngo', 'association', 'institution'])], // l'espace personnel est créé à l'inscription
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $organization = Organization::createFor($request->user(), $data['name'], $data['description'] ?? null, $data['type']);
        AuditLogger::log($request->user(), 'organization.created', $organization, $organization->id);

        return response()->json($organization, 201);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        return response()->json($organization->toArray() + [
            'role' => $request->user()->roleIn($organization),
            'members_count' => $organization->members()->count(),
            'studies_count' => $organization->studies()->count(),
        ]);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $organization->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));
        AuditLogger::log($request->user(), 'organization.updated', $organization, $organization->id);

        return response()->json($organization);
    }

    /** Un membre quitte l'organisation (le propriétaire et l'espace personnel ne le peuvent pas). */
    public function leave(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('view', $organization);

        if ($organization->type === 'personal' || $request->user()->roleIn($organization) === Roles::OWNER) {
            return response()->json(['message' => 'Le propriétaire ne peut pas quitter son organisation.'], 422);
        }

        $organization->members()->detach($request->user()->id);
        AuditLogger::log($request->user(), 'member.left', $organization, $organization->id);

        return response()->json(['message' => 'Vous avez quitté l\'organisation.']);
    }

    /** Le propriétaire transfère l'organisation à un autre membre (il devient administrateur). */
    public function transfer(Request $request, Organization $organization): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->roleIn($organization) === Roles::OWNER, 403, 'Seul le propriétaire peut transférer l’organisation.');

        if ($organization->type === 'personal') {
            return response()->json(['message' => 'Un espace personnel ne peut pas être transféré.'], 422);
        }

        $data = $request->validate(['user_id' => ['required', 'integer'], 'password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Mot de passe incorrect.'], 422);
        }
        if ((int) $data['user_id'] === $user->id || ! $organization->members()->where('users.id', $data['user_id'])->exists()) {
            return response()->json(['message' => 'Choisissez un autre membre de l’organisation.'], 422);
        }

        DB::transaction(function () use ($organization, $user, $data) {
            $organization->update(['owner_id' => (int) $data['user_id']]);
            $organization->members()->updateExistingPivot((int) $data['user_id'], ['role' => Roles::OWNER]);
            $organization->members()->updateExistingPivot($user->id, ['role' => Roles::ADMIN]);
        });
        AuditLogger::log($user, 'organization.transferred', $organization, $organization->id, ['to' => (int) $data['user_id']]);

        return response()->json(['message' => 'Propriété transférée. Vous êtes maintenant administrateur.']);
    }
}
