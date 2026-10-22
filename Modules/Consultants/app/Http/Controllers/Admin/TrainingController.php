<?php

namespace Modules\Consultants\Http\Controllers\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultants\Http\Requests\Admin\StoreTrainingSessionRequest;
use Modules\Consultants\Http\Resources\TraineeResource;
use Modules\Consultants\Http\Resources\TrainingSessionResource;
use Modules\Consultants\Models\TrainingSession;
use Modules\Consultants\Services\TrainingService;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Users\Models\User;

/**
 * Admin side of the consultant-training pipeline: list trainees, schedule
 * coaching sessions for them, and promote them to public consultants.
 */
class TrainingController extends ApiController
{
    public function __construct(private TrainingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->consultants()
            ->trainees()
            ->with(['media', 'trainingSessions.scheduler:id,name']);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('specialization', 'like', "%{$search}%"));
        }

        return $this->paginated(
            TraineeResource::collection($query->latest()->paginate(QueryFilters::perPage($request))),
        );
    }

    public function sessions(User $consultant): JsonResponse
    {
        abort_unless($consultant->isTrainee(), 404);

        $sessions = $consultant->trainingSessions()
            ->with('scheduler:id,name')
            ->orderBy('starts_at')
            ->get();

        return $this->success(TrainingSessionResource::collection($sessions));
    }

    public function storeSession(StoreTrainingSessionRequest $request, User $consultant): JsonResponse
    {
        $session = $this->service->schedule(
            $consultant,
            $request->safe()->except([]),
            $request->user('admin'),
        );
        $session->load('scheduler:id,name');

        return $this->created(TrainingSessionResource::make($session), __('consultants::training.session_scheduled'));
    }

    public function destroySession(User $consultant, TrainingSession $trainingSession): JsonResponse
    {
        $this->service->deleteSession($consultant, $trainingSession);

        return $this->noContent(__('consultants::training.session_deleted'));
    }

    public function promote(User $consultant): JsonResponse
    {
        $consultant = $this->service->promote($consultant);
        $consultant->load(['media', 'trainingSessions.scheduler:id,name']);

        return $this->success(TraineeResource::make($consultant), __('consultants::training.promoted'));
    }

    /**
     * Trainee-side: a consultant sees his own scheduled sessions.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user('admin');
        abort_unless($user?->isConsultant(), 403);

        $sessions = $user->trainingSessions()
            ->with('scheduler:id,name')
            ->orderBy('starts_at')
            ->get();

        return $this->success(TrainingSessionResource::collection($sessions));
    }
}
