<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Admin-only list of every user's sign-ins and changes, newest first, with the filter options it needs. */
class ActivityLogApiController extends Controller
{
    public function __invoke(Request $request)
    {
        $query = ActivityLog::query()
            ->select([
                'id', 'user_id', 'actor_name', 'actor_email', 'actor_role', 'action', 'module',
                'method', 'path', 'status_code', 'description', 'subject_user_id', 'subject_name',
                'ip_address', 'created_at',
            ]);

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

        // Avatar lookup is bounded to the current paginated page. Keep historical
        // actor names even when the user account was deleted or offboarded.
        $actorIds = $logs->getCollection()->pluck('user_id')->filter()->unique()->values();
        $actors = $actorIds->isEmpty() ? collect() : User::query()
            ->with(['employee:id,user_id,profile_picture', 'client:id,user_id,profile_picture'])
            ->whereIn('id', $actorIds)->get(['id', 'role', 'profile_picture'])->keyBy('id');
        $logs->getCollection()->each(function ($log) use ($actors) {
            $actor = $actors->get($log->user_id);
            $photo = $actor?->role === 'employee'
                ? ($actor->employee?->profile_picture ?: $actor->profile_picture)
                : ($actor?->role === 'client'
                    ? ($actor->client?->profile_picture ?: $actor->profile_picture)
                    : $actor?->profile_picture);
            $log->setAttribute('actor_profile_picture', $photo);
        });

        // Modules are a tiny bounded list, so they are safe to cache and return with
        // every page. Actor/user options are deliberately NOT returned here: on a
        // 50k-user install that distinct actor list can be tens of thousands of rows and
        // was large enough to exhaust request memory / JSON response time. The frontend
        // now uses /management/people-options?type=user for remote actor lookup.
        $loadModules = fn () => ActivityLog::query()
            ->whereNotNull('module')
            ->where('module', '!=', '')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->values();
        try {
            $modules = Cache::remember('carevma.activity-log.modules.v4', max(30, (int) config('carevma.performance.activity_filter_cache_seconds', 300)), $loadModules);
        } catch (\Throwable) {
            $modules = $loadModules();
        }

        return response()->json(array_merge($logs->toArray(), [
            'modules' => $modules,
            'actors' => [], // backward-compatible key; actor choices are remote-searched.
        ]));
    }
}
