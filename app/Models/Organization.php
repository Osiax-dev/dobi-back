<?php

namespace App\Models;

use App\Support\Roles;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    public const TYPES = ['personal', 'company', 'ngo', 'association', 'institution'];

    protected $fillable = ['name', 'slug', 'type', 'description', 'owner_id'];

    protected $appends = ['public_url', 'display_name'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function studies(): HasMany
    {
        return $this->hasMany(Study::class);
    }

    /** Nom montré au public : pour un espace personnel, le nom de la personne ; sinon celui de l'organisation. */
    public function getDisplayNameAttribute(): string
    {
        return $this->type === 'personal' ? ($this->owner?->name ?? $this->name) : $this->name;
    }

    public function getPublicUrlAttribute(): string
    {
        return rtrim(config('dobi.frontend_url'), '/').'/o/'.$this->slug;
    }

    /** Crée une organisation dont $user est propriétaire. */
    public static function createFor(User $user, string $name, ?string $description = null, string $type = 'company', ?string $slugBase = null): self
    {
        $organization = self::create([
            'name' => $name,
            'type' => $type,
            'slug' => Slug::unique($slugBase ?? $name, 'organizations'),
            'description' => $description,
            'owner_id' => $user->id,
        ]);
        $organization->members()->attach($user->id, ['role' => Roles::OWNER]);

        return $organization;
    }
}
