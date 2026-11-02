<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ResponseFilters
{
    /** Filtres communs aux statistiques et aux exports : from, to (dates), version (id de version). */
    public static function apply($query, Request $request, string $table = 'responses.')
    {
        if ($request->filled('from')) {
            $query->where($table.'submitted_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where($table.'submitted_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
        }
        if ($request->filled('version')) {
            $query->where($table.'study_version_id', (int) $request->query('version'));
        }

        return $query;
    }

    public static function rules(): array
    {
        return ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'version' => ['nullable', 'integer']];
    }
}
