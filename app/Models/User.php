<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<int, string|null> */
    protected array $roleCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Rôle de l'utilisateur dans une organisation (null s'il n'en est pas membre). */
    public function roleIn(Organization|int $organization): ?string
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        if (! array_key_exists($id, $this->roleCache)) {
            $this->roleCache[$id] = DB::table('organization_members')
                ->where('organization_id', $id)
                ->where('user_id', $this->id)
                ->value('role');
        }

        return $this->roleCache[$id];
    }
}
