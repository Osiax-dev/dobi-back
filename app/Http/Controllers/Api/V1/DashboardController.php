<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Study;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /** Synthèse d'un espace de travail : uniquement des chiffres calculés à partir des données réelles. */
    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('viewWorkspace', $organization);

        $studyIds = $organization->studies()->select('id');
        $responses = fn () => Submission::whereIn('study_id', $studyIds);

        $byStatus = $organization->studies()
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $perDay = $responses()
            ->where('submitted_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(submitted_at) as day, COUNT(*) as total')->groupBy('day')->pluck('total', 'day');

        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'responses' => (int) ($perDay[$date] ?? 0)];
        }

        $recent = $organization->studies()->withCount('responses')->latest('updated_at')->limit(5)->get()
            ->map(fn (Study $s) => [
                'id' => $s->id, 'title' => $s->title, 'status' => $s->status,
                'responses_count' => $s->responses_count, 'ends_at' => $s->ends_at, 'updated_at' => $s->updated_at,
            ]);

        $logs = AuditLog::where('audit_logs.organization_id', $organization->id)
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select('audit_logs.*', 'users.name as user_name')
            ->latest('audit_logs.created_at')->limit(8)->get();
        $titles = Study::whereIn('id', $logs->where('entity_type', 'Study')->pluck('entity_id'))->pluck('title', 'id');

        return response()->json([
            'workspace' => ['id' => $organization->id, 'name' => $organization->name],
            'kpis' => [
                'studies' => (int) $byStatus->sum(),
                'published' => (int) ($byStatus['published'] ?? 0),
                'responses' => $responses()->count(),
                'responses_30d' => $responses()->where('submitted_at', '>=', now()->subDays(30))->count(),
                'responses_prev_30d' => $responses()->whereBetween('submitted_at', [now()->subDays(60), now()->subDays(30)])->count(),
            ],
            'by_status' => $byStatus,
            'series' => $series,
            'recent_studies' => $recent,
            'activity' => $logs->map(fn ($l) => [
                'action' => $l->action,
                'user_name' => $l->user_name,
                'study_title' => $l->entity_type === 'Study' ? ($titles[$l->entity_id] ?? null) : null,
                'created_at' => $l->created_at,
            ]),
        ]);
    }
}
