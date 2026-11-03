<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    /**
     * Types disponibles. « number » et « location » sont conservés pour les anciennes études.
     * Le type « file » (image / pièce jointe) viendra avec un stockage privé sécurisé.
     */
    public const TYPES = [
        'short_text', 'long_text', 'integer', 'decimal', 'number',
        'single_choice', 'dropdown', 'multiple_choice', 'yes_no',
        'date', 'time', 'datetime', 'scale', 'location', 'geolocation', 'note',
    ];

    public const CHOICE_TYPES = ['single_choice', 'dropdown', 'multiple_choice'];

    /** Types qui ne peuvent pas servir de question de référence pour une condition. */
    public const NON_REFERENCEABLE = ['note', 'geolocation'];

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
