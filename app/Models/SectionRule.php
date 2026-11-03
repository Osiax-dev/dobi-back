<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectionRule extends Model
{
    protected $fillable = ['section_id', 'source_question_id', 'operator', 'value'];
}
