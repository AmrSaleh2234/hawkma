<?php

namespace Modules\JoinRequests\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\JoinRequests\Http\Requests\RejectJoinRequestRequest;
use Modules\JoinRequests\Http\Requests\StoreJoinRequestRequest;
use Modules\JoinRequests\Models\JoinRequest;
use Modules\JoinRequests\Services\JoinRequestService;
use Modules\JoinRequests\Transformers\JoinRequestResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class JoinRequestsController extends ApiController
{
    public function __construct(private JoinRequestService $service) {}

    public function store(StoreJoinRequestRequest $request): JsonResponse
    {
        $joinRequest = $this->service->submit($request->safe()->except('cv'), $request->file('cv'));

        return $this->created(JoinRequestResource::make($joinRequest), __('joinrequests::messages.submitted'));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected'], 'search' => ['nullable', 'string', 'max:191'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = JoinRequest::query()->with(['media', 'reviewer:id,name'])->when($request->query('status'), fn (Builder $q, string $status) => $q->where('status', $status));
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('specialization', 'like', "%{$search}%"));
        }

        return $this->paginated(JoinRequestResource::collection($query->latest()->paginate(QueryFilters::perPage($request))));
    }

    public function show(string $joinRequest): JsonResponse
    {
        return $this->success(JoinRequestResource::make(JoinRequest::query()->with(['media', 'reviewer:id,name'])->findOrFail($joinRequest)));
    }

    public function downloadCv(string $joinRequest): BinaryFileResponse
    {
        $joinRequest = JoinRequest::query()->findOrFail($joinRequest);
        $media = $joinRequest->getFirstMedia('cv');
        abort_if($media === null, 404);

        return response()->download($media->getPath(), $media->file_name);
    }

    public function approve(Request $request, string $joinRequest): JsonResponse
    {
        $joinRequest = JoinRequest::query()->findOrFail($joinRequest);

        return $this->success(JoinRequestResource::make($this->service->approve($joinRequest, $request->user('admin'))), __('joinrequests::messages.approved'));
    }

    public function reject(RejectJoinRequestRequest $request, string $joinRequest): JsonResponse
    {
        $joinRequest = JoinRequest::query()->findOrFail($joinRequest);

        return $this->success(JoinRequestResource::make($this->service->reject($joinRequest, $request->user('admin'), $request->string('reason')->value())), __('joinrequests::messages.rejected'));
    }
}
