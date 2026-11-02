<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParticipantIdentifier extends Model
{
    protected $fillable = ['response_id', 'full_name', 'email', 'phone'];
}
