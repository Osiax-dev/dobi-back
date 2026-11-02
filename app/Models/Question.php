<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    /** Le type "file" est volontairement absent : téléversement désactivé par défaut (cahier des charges §9). */
    public const TYPES = [
        'short_text', 'long_text', 'single_choice', 'multiple_choice',
        'yes_no', 'number', 'date', 'scale', 'location',
    ];

    public const CHOICE_TYPES = ['single_choice', 'multiple_choice'];

    protected $fillable = ['study_id', 'section_id', 'type', 'label', 'description', 'required', 'position', 'settings'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'settings' => 'array'];
    }

    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position')->orderBy('id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(QuestionRule::class, 'question_id');
    }
}
