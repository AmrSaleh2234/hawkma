<?php

namespace Modules\ActivityLogs\Http\Controllers\Admin;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\ActivityLogs\Enums\ActivityEvent;
use Modules\ActivityLogs\Http\Requests\Admin\ActivityLogIndexRequest;
use Modules\ActivityLogs\Http\Resources\ActivityLogResource;
use Modules\ActivityLogs\Models\ActivityLog;
use Modules\Clients\Models\Client;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Models\User;

class ActivityLogController extends ApiController
{
    /**
     * LOG-01 GET /api/v1/admin/activity-logs — Perm: view-activity-logs
     *
     * The unified audit trail: system events (model create/update/delete/
     * restore) and API events (login/logout/failed logins). Filterable by
     * actor, module, event, log source and date/time range.
     */
    public function index(ActivityLogIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = ActivityLog::query()
            ->with(['causer', 'subject'])
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))
            ->when($filters['client_id'] ?? null, fn (Builder $q, $id) => $q->where('causer_type', (new Client)->getMorphClass())->where('causer_id', $id))
            ->when($filters['module'] ?? null, fn (Builder $q, $module) => $q->where('module', strtolower($module)))
            ->when($filters['event'] ?? null, fn (Builder $q, $event) => $q->where('event', $event))
            ->when($filters['log_name'] ?? null, fn (Builder $q, $name) => $q->where('log_name', $name))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->where('created_at', '>=', $this->boundary($from, false)))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->where('created_at', '<=', $this->boundary($to, true)));

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhereHasMorph('causer', '*', fn (Builder $r) => $r->where('name', 'like', "%{$search}%"));
            });
        }

        $sort = (string) ($filters['sort'] ?? '-created_at');
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id', str_starts_with($sort, '-') ? 'desc' : 'asc');

        $logs = $query->paginate(QueryFilters::perPage($request));

        return $this->paginated(ActivityLogResource::collection($logs));
    }

    /**
     * LOG-02 GET /api/v1/admin/activity-logs/meta — Perm: view-activity-logs
     *
     * The values behind the filter dropdowns: modules that have produced
     * log rows, the known event types, and the log sources.
     */
    public function meta(): JsonResponse
    {
        return $this->success([
            'modules' => ActivityLog::query()->select('module')->distinct()->orderBy('module')->pluck('module'),
            'events' => ActivityEvent::values(),
            'log_names' => [ActivityLog::NAME_SYSTEM, ActivityLog::NAME_API],
        ]);
    }

    /**
     * Normalise a date or datetime filter into a `Y-m-d H:i:s` boundary:
     * a bare date expands to the start/end of that day.
     */
    private function boundary(string $value, bool $endOfDay): string
    {
        $date = Carbon::parse($value);

        if (! preg_match('/\d{1,2}:\d{2}/', $value)) {
            $endOfDay ? $date->endOfDay() : $date->startOfDay();
        }

        return $date->format('Y-m-d H:i:s');
    }
}
