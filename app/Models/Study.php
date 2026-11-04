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
        'privacy_mode', 'one_response_per_person', 'status', 'status_before_archive', 'current_version_id', 'published_at',
    ];

    /** Enquêteurs affectés à l'étude (vide = tous les enquêteurs de l'espace). */
    public function collectors(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'study_collectors');
    }

    /** Identifiants des enquêteurs affectés qui sont toujours enquêteurs de l'espace. Tableau vide = pas de restriction. */
    public function assignedSurveyorIds(): array
    {
        return \Illuminate\Support\Facades\DB::table('study_collectors as sc')
            ->join('organization_members as om', function ($join) {
                $join->on('om.user_id', '=', 'sc.user_id')
                    ->where('om.organization_id', $this->organization_id)
                    ->where('om.role', 'surveyor');
            })
            ->where('sc.study_id', $this->id)
            ->pluck('sc.user_id')->map(fn ($id) => (int) $id)->all();
    }

    protected $appends = ['public_url'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'one_response_per_person' => 'boolean',
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
