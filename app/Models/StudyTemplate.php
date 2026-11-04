<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modèle de questionnaire d'une organisation (étapes, questions, choix et conditions). */
class StudyTemplate extends Model
{
    protected $fillable = ['organization_id', 'created_by', 'name', 'description', 'content'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
