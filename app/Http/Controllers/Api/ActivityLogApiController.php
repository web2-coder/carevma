<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Admin-only list of every user's sign-ins and changes, newest first, with the filter options it needs. */
class ActivityLogApiController extends Controller
{
    public function __invoke(Request $request)
    {
        $query = ActivityLog::query()->with('user:id,name,email,role');

        if ($request->filled('user_id')) $query->where('user_id', $request->integer('user_id'));
        if ($request->filled('module')) $query->where('module', $request->string('module'));
        if ($request->filled('role')) $query->where('actor_role', $request->string('role'));
        if ($request->filled('from')) $query->where('created_at', '>=', $request->date('from')->startOfDay());
        if ($request->filled('to')) $query->where('created_at', '<=', $request->date('to')->endOfDay());
        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('action', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('actor_name', 'like', $like)
                ->orWhere('actor_email', 'like', $like)
                ->orWhere('subject_name', 'like', $like)
                ->orWhere('path', 'like', $like)
                ->orWhere('ip_address', 'like', $like));
        }

        $logs = $this->sortAndPaginate($request, $query, [
            'created_at' => 'created_at', 'action' => 'action', 'module' => 'module', 'actor' => 'actor_name', 'id' => 'id',
        ], 'created_at');

        // Filter options change rarely but were scanning the complete activity_logs
        // table on every page request. Cache them briefly; if the configured cache store
        // is unavailable, fall back to the same direct queries instead of failing the page.
        $loadFilters = fn () => [
            'modules' => ActivityLog::query()->distinct()->orderBy('module')->pluck('module'),
            'actors' => ActivityLog::query()->whereNotNull('user_id')
                ->select('user_id', 'actor_name', 'actor_email', 'actor_role')->distinct()->orderBy('actor_name')->get(),
        ];
        try {
            $filters = Cache::remember('carevma.activity-log.filters.v1', max(30, (int) config('carevma.performance.activity_filter_cache_seconds', 300)), $loadFilters);
        } catch (\Throwable) {
            $filters = $loadFilters();
        }

        return response()->json(array_merge($logs->toArray(), $filters));
    }
}
