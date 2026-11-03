<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionRule extends Model
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte', 'answered', 'not_answered'];

    protected $fillable = ['question_id', 'source_question_id', 'operator', 'value'];
}
