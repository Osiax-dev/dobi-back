<?php

namespace App\Support;

use App\Models\ActivityNotification;
use App\Models\Study;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Alertes dans l'application : le propriétaire et les administrateurs voient ce que font les autres membres. */
final class Notifier
{
    /** Actions fréquentes : regroupées en une seule alerte tant qu'elle n'est pas lue. */
    private const GROUPED = ['question.created', 'question.updated', 'question.deleted', 'study.updated', 'response.received'];
    private const GROUP_WINDOW_MINUTES = 60;
    private const ROLES = ['owner' => 'propriétaire', 'admin' => 'administrateur', 'editor' => 'éditeur', 'surveyor' => 'enquêteur', 'analyst' => 'analyste'];
    private const STUDY_ACTIONS = [
        'created' => 'a créé', 'duplicated' => 'a dupliqué', 'publish' => 'a publié', 'resume' => 'a repris',
        'suspend' => 'a suspendu', 'close' => 'a clôturé', 'archive' => 'a archivé', 'restore' => 'a restauré',
    ];

    /** Actions du journal d'audit qui méritent une alerte (le reste reste dans le journal). */
    public static function fromAudit(?User $actor, string $action, ?Model $entity, ?int $organizationId, array $meta): void
    {
        if (! $organizationId || ! $actor) {
            return;
        }
        if (str_starts_with($action, 'study.')) {
            $name = substr($action, 6);
            if ($name === 'updated') {
                self::notify($organizationId, $actor, 'study.updated', $entity instanceof Study ? $entity : null);
            } elseif (isset(self::STUDY_ACTIONS[$name]) && $entity instanceof Study) {
                self::notify($organizationId, $actor, $action, $entity);
            }

            return;
        }
        $subject = $entity instanceof User ? $entity->name : ($meta['email'] ?? null);
        $role = $meta['to'] ?? $meta['role'] ?? null;
        if (in_array($action, ['member.invited', 'member.invitation_accepted', 'member.role_changed', 'member.removed', 'member.left', 'organization.transferred'], true)) {
            self::notify($organizationId, $actor, $action, null, ['subject' => $subject, 'role' => $role]);
        }
    }

    /** Crée (ou regroupe) l'alerte pour chaque propriétaire et administrateur de l'organisation, sauf l'auteur. */
    public static function notify(int $organizationId, ?User $actor, string $event, ?Study $study = null, array $extra = []): void
    {
        try {
            $recipients = DB::table('organization_members')->where('organization_id', $organizationId)
                ->whereIn('role', ['owner', 'admin'])->pluck('user_id')->all();
            $recipients = array_values(array_diff($recipients, $actor ? [$actor->id] : []));

            foreach ($recipients as $userId) {
                $existing = null;
                if (in_array($event, self::GROUPED, true)) {
                    $existing = ActivityNotification::where('user_id', $userId)->where('organization_id', $organizationId)
                        ->where('event', $event)->where('study_id', $study?->id)->where('actor_id', $actor?->id)
                        ->whereNull('read_at')->where('updated_at', '>=', now()->subMinutes(self::GROUP_WINDOW_MINUTES))->first();
                }
                $count = ($existing?->count ?? 0) + 1;
                $message = self::message($event, $actor?->name, $study?->title, $count, $extra);

                if ($existing) {
                    $existing->update(['count' => $count, 'message' => $message, 'updated_at' => now()]);
                } else {
                    ActivityNotification::create([
                        'user_id' => $userId, 'organization_id' => $organizationId, 'actor_id' => $actor?->id,
                        'event' => $event, 'study_id' => $study?->id, 'message' => $message, 'count' => 1,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            report($e); // une alerte ratée ne doit jamais bloquer l'action elle-même
        }
    }

    private static function message(string $event, ?string $actor, ?string $study, int $count, array $extra): string
    {
        $who = $actor ?: 'Un membre';
        $title = $study ? "« $study »" : "l'étude";
        $plural = fn (string $one, string $many) => $count > 1 ? "$count $many" : $one;
        $role = self::ROLES[$extra['role'] ?? ''] ?? ($extra['role'] ?? '');

        return match ($event) {
            'question.created' => "$who a ajouté ".$plural('une question', 'questions')." à l'étude $title.",
            'question.updated' => "$who a modifié ".$plural('une question', 'questions')." de l'étude $title.",
            'question.deleted' => "$who a supprimé ".$plural('une question', 'questions')." de l'étude $title.",
            'study.updated' => "$who a modifié l'étude $title".($count > 1 ? " ($count modifications)." : '.'),
            'response.received' => ($count > 1 ? "$count nouvelles réponses" : 'Nouvelle réponse')." sur l'étude $title.",
            'member.invited' => "$who a invité ".($extra['subject'] ?? 'une personne').($role ? " comme $role." : '.'),
            'member.invitation_accepted' => "$who a rejoint l'organisation.",
            'member.role_changed' => "$who a changé le rôle de ".($extra['subject'] ?? 'un membre').($role ? " en $role." : '.'),
            'member.removed' => "$who a retiré ".($extra['subject'] ?? 'un membre')." de l'organisation.",
            'member.left' => "$who a quitté l'organisation.",
            'organization.transferred' => "$who a transféré la propriété de l'organisation.",
            default => self::studyMessage($event, $who, $title),
        };
    }

    private static function studyMessage(string $event, string $who, string $title): string
    {
        $verb = self::STUDY_ACTIONS[substr($event, 6)] ?? 'a modifié';

        return "$who $verb l'étude $title.";
    }
}
