<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Slug
{
    /** Slug unique pour une table. $alwaysSuffix = true ajoute toujours un suffixe aléatoire (liens publics d'études). */
    public static function unique(string $base, string $table, bool $alwaysSuffix = false, string $column = 'slug'): string
    {
        $slug = Str::slug(Str::limit($base, 60, '')) ?: 'dobi';
        $candidate = $alwaysSuffix ? $slug.'-'.Str::lower(Str::random(6)) : $slug;

        while (DB::table($table)->where($column, $candidate)->exists()) {
            $candidate = $slug.'-'.Str::lower(Str::random(6));
        }

        return $candidate;
    }
}
