<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityNotification extends Model
{
    protected $fillable = ['user_id', 'organization_id', 'actor_id', 'event', 'study_id', 'message', 'count', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
