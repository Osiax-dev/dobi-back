<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    /** Journalise une action sensible. Ne jamais y mettre de réponses de participants. */
    public static function log(?User $user, string $action, ?Model $entity = null, ?int $organizationId = null, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'organization_id' => $organizationId,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
