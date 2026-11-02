<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Support\AuditLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationInvitationController extends Controller
{
    public function index(Organization $organization): JsonResponse
    {
        $this->authorize('manageMembers', $organization);
        $items = $organization->invitations()->whereNull('accepted_at')->latest()->get()
            ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role,
                'expires_at' => $i->expires_at, 'expired' => $i->expires_at->isPast()]);
        return response()->json(['data' => $items]);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('manageMembers', $organization);
        if ($organization->type === 'personal') {
            return response()->json(['message' => 'Les invitations sont disponibles dans les organisations de travail.'], 422);
        }
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(Roles::ASSIGNABLE)],
        ]);
        $email = Str::lower(trim($data['email']));
        if ($data['role'] === Roles::ADMIN && $request->user()->roleIn($organization) !== Roles::OWNER) {
            abort(403, 'Seul le propriétaire peut inviter un administrateur.');
        }
        $existing = $organization->members()->where('email', $email)->exists();
        if ($existing) return response()->json(['message' => 'Cette personne est déjà membre de l’organisation.'], 422);

        $plainToken = Str::random(64);
        $invitation = DB::transaction(function () use ($organization, $request, $email, $data, $plainToken) {
            OrganizationInvitation::where('organization_id', $organization->id)
                ->where('email', $email)->whereNull('accepted_at')->delete();
            return OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'invited_by' => $request->user()->id,
                'email' => $email,
                'role' => $data['role'],
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addDays(7),
            ]);
        });

        $url = rtrim(config('dobi.frontend_url'), '/') . '/invitations/' . $plainToken;
        try {
            Mail::raw(
                "Bonjour,\n\n" . $request->user()->name . " vous invite à rejoindre « {$organization->name} » sur DOBI avec le rôle : {$data['role']}.\n\nPour accepter l’invitation, ouvrez ce lien :\n{$url}\n\nCe lien expire dans 7 jours. Si vous ne connaissez pas cette invitation, ignorez ce message.\n\nL’équipe DOBI",
                function ($message) use ($email, $organization) {
                    $message->to($email)->subject('Invitation à rejoindre ' . $organization->name . ' sur DOBI');
                }
            );
        } catch (\Throwable $e) {
            $invitation->delete();
            report($e);
            return response()->json(['message' => 'L’invitation n’a pas pu être envoyée. Vérifiez la configuration e-mail puis réessayez.'], 503);
        }

        AuditLogger::log($request->user(), 'member.invited', $invitation, $organization->id, ['email' => $email, 'role' => $data['role']]);
        return response()->json(['message' => 'Invitation envoyée par e-mail.', 'id' => $invitation->id], 201);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = OrganizationInvitation::where('token_hash', hash('sha256', $token))->first();
        if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
            return response()->json(['message' => 'Cette invitation est invalide ou a expiré.'], 422);
        }
        $user = $request->user();
        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            return response()->json(['message' => 'Connectez-vous avec l’adresse e-mail qui a reçu cette invitation.'], 403);
        }
        DB::transaction(function () use ($invitation, $user) {
            $locked = OrganizationInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->accepted_at || $locked->expires_at->isPast()) abort(422, 'Cette invitation a déjà été utilisée ou a expiré.');
            if (! $locked->organization->members()->wherePivot('user_id', $user->id)->exists()) {
                $locked->organization->members()->attach($user->id, ['role' => $locked->role]);
            }
            $locked->forceFill(['accepted_at' => now()])->save();
        });
        AuditLogger::log($user, 'member.invitation_accepted', $invitation, $invitation->organization_id);
        return response()->json(['message' => 'Vous avez rejoint l’organisation.', 'organization_id' => $invitation->organization_id]);
    }

    public function destroy(Request $request, Organization $organization, OrganizationInvitation $invitation): JsonResponse
    {
        $this->authorize('manageMembers', $organization);
        abort_unless($invitation->organization_id === $organization->id, 404);
        $invitation->delete();
        return response()->json(['message' => 'Invitation annulée.']);
    }
}
