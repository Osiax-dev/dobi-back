<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ancien état (titre, textes, mise en page) d'un rapport, conservé pour pouvoir le restaurer. */
class ReportRevision extends Model
{
    public const UPDATED_AT = null;
    public const KEEP = 30;

    protected $fillable = ['report_id', 'revision', 'title', 'notes', 'custom', 'saved_by'];

    protected function casts(): array
    {
        return ['notes' => 'array', 'custom' => 'array'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }
}
