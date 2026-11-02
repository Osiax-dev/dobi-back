<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
