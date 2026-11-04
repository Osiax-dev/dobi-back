<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Une soumission de participant (table "responses"). */
class Submission extends Model
{
    protected $table = 'responses';

    protected $fillable = ['uuid', 'client_token', 'study_id', 'study_version_id', 'status', 'submitted_at', 'collected_by'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'response_id');
    }

    public function participant(): HasOne
    {
        return $this->hasOne(ParticipantIdentifier::class, 'response_id');
    }
}
