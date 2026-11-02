<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    protected $fillable = ['response_id', 'question_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
