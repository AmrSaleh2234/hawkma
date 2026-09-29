<?php

namespace Modules\Consultants\Http\Controllers\Public;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultants\Http\Controllers\Concerns\BuildsSlotsPayload;
use Modules\Consultants\Http\Requests\AvailableDatesRequest;
use Modules\Consultants\Http\Requests\SlotsRequest;
use Modules\Consultants\Http\Resources\PublicConsultantResource;
use Modules\Consultants\Services\SlotService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Models\User;

class PublicConsultantController extends ApiController
{
    use BuildsSlotsPayload;

    public function __construct(protected SlotService $slots) {}

    /**
     * PUB-03 GET /api/v1/public/consultants
     *
     * Only active consultants with at least one availability row.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->consultants()
            ->active()
            ->whereHas('availabilities')
            ->with(['availabilities', 'media']);

        QueryFilters::apply($query, $request, ['name', 'title', 'specialization'], ['name', 'created_at']);

        if ($request->filled('specialization')) {
            $query->where('specialization', $request->string('specialization')->value());
        }

        $perPage = (int) $request->query('per_page', 12);
        $perPage = $perPage < 1 ? 12 : min($perPage, QueryFilters::MAX_PER_PAGE);

        return $this->paginated(PublicConsultantResource::collection($query->paginate($perPage)));
    }

    /**
     * PUB-04 GET /api/v1/public/consultants/{consultant}
     */
    public function show(User $consultant): JsonResponse
    {
        abort_unless($consultant->is_active, 404);

        return $this->success(PublicConsultantResource::make($consultant->load('availabilities')));
    }

    /**
     * PUB-05 GET /api/v1/public/consultants/{consultant}/available-dates?month=2026-09
     */
    public function availableDates(AvailableDatesRequest $request, User $consultant): JsonResponse
    {
        abort_unless($consultant->is_active, 404);

        $month = CarbonImmutable::createFromFormat('Y-m', $request->string('month')->value(), config('app.timezone'))->startOfMonth();

        return $this->success([
            'month' => $month->format('Y-m'),
            'dates' => $this->slots->getAvailableDates($consultant, $month),
        ]);
    }

    /**
     * PUB-06 GET /api/v1/public/consultants/{consultant}/slots?date=2026-09-23
     */
    public function slots(SlotsRequest $request, User $consultant): JsonResponse
    {
        abort_unless($consultant->is_active, 404);

        return $this->success($this->slotsPayload($consultant, $request->string('date')->value()));
    }
}
