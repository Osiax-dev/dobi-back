<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = ['study_id', 'title', 'description', 'position'];

    public function rules(): HasMany
    {
        return $this->hasMany(SectionRule::class);
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class);
    }
}
