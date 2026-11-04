<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Les alertes de l'utilisateur connecté (les 30 plus récentes), avec le nombre de non lues. */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityNotification::where('user_id', $request->user()->id);
        if ($request->filled('organization')) {
            $query->where('organization_id', (int) $request->query('organization'));
        }

        $unread = (clone $query)->whereNull('read_at')->count();
        $items = $query->orderByDesc('updated_at')->orderByDesc('id')->limit(30)->get()->map(fn (ActivityNotification $n) => [
            'id' => $n->id,
            'event' => $n->event,
            'message' => $n->message,
            'study_id' => $n->study_id,
            'organization_id' => $n->organization_id,
            'count' => $n->count,
            'read' => $n->read_at !== null,
            'at' => $n->updated_at,
        ]);

        return response()->json(['unread' => $unread, 'data' => $items]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        ActivityNotification::where('user_id', $request->user()->id)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Lue.']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $query = ActivityNotification::where('user_id', $request->user()->id)->whereNull('read_at');
        if ($request->filled('organization')) {
            $query->where('organization_id', (int) $request->input('organization'));
        }
        $query->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les alertes sont lues.']);
    }
}
