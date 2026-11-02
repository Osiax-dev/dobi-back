<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyVersion extends Model
{
    protected $fillable = ['study_id', 'version_number', 'snapshot', 'published_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime'];
    }
}
