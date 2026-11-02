<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Section extends Model
{
    protected $fillable = ['study_id', 'title', 'description', 'position'];

    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class);
    }
}
