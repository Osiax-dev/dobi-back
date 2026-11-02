<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Study extends Model
{
    protected $fillable = [
        'organization_id', 'created_by', 'title', 'slug', 'description', 'objective', 'category',
        'target_population', 'geographic_zone', 'starts_at', 'ends_at', 'collection_mode',
        'privacy_mode', 'status', 'status_before_archive', 'current_version_id', 'published_at',
    ];

    protected $appends = ['public_url'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StudyVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(StudyVersion::class, 'current_version_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Submission::class, 'study_id');
    }

    public function getPublicUrlAttribute(): string
    {
        return rtrim(config('dobi.frontend_url'), '/').'/s/'.$this->slug;
    }

    /** Étude publiée, avec une version figée, dans sa période de collecte. */
    public function isOpenForCollection(): bool
    {
        return $this->status === 'published'
            && $this->current_version_id !== null
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gte(now()));
    }
}
