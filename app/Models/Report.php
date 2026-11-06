<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rapport généré à partir des résultats d'une étude. Les calculs sont figés ; les textes de l'enquêteur sont dans « notes ». */
class Report extends Model
{
    public const STATUSES = ['draft', 'final'];
    public const NOTE_KEYS = ['context', 'objectives', 'analysis', 'conclusions', 'recommendations'];

    protected $fillable = ['organization_id', 'study_id', 'created_by', 'updated_by', 'title', 'status', 'revision', 'content', 'notes', 'custom', 'finalized_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'notes' => 'array', 'custom' => 'array', 'finalized_at' => 'datetime'];
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ReportRevision::class);
    }
}
