<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consent extends Model
{
    protected $fillable = ['response_id', 'accepted', 'notice_version', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted' => 'boolean', 'accepted_at' => 'datetime'];
    }
}
