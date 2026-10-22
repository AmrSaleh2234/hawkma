<?php

namespace Modules\Team\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\Team\Http\Requests\StoreTeamMemberRequest;
use Modules\Team\Http\Requests\UpdateTeamMemberRequest;
use Modules\Team\Models\TeamMember;
use Modules\Team\Transformers\TeamMemberResource;
use Modules\Users\Services\AvatarService;

class TeamMembersController extends ApiController
{
    public function __construct(private readonly AvatarService $avatars) {}

    // PUB: active members for the landing page (plain array, ordered).
    public function index(): JsonResponse
    {
        $members = TeamMember::query()
            ->with('media')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->success(TeamMemberResource::collection($members));
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TeamMember::query()->with('media');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('job_title', 'like', "%{$search}%"));
        }
        $query->when($request->query('status'), fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'));

        $members = $query->orderBy('sort_order')->orderBy('id')->paginate(QueryFilters::perPage($request));

        return $this->paginated(TeamMemberResource::collection($members));
    }

    public function show(TeamMember $teamMember): JsonResponse
    {
        return $this->success(TeamMemberResource::make($teamMember->load('media')));
    }

    public function store(StoreTeamMemberRequest $request): JsonResponse
    {
        $data = $request->safe()->except('avatar');
        $member = TeamMember::query()->create($data);
        if ($file = $request->file('avatar')) {
            $this->avatars->update($member, $file);
        }

        return $this->created(TeamMemberResource::make($member), __('team::messages.created'));
    }

    public function update(UpdateTeamMemberRequest $request, TeamMember $teamMember): JsonResponse
    {
        $teamMember->update($request->safe()->except('avatar'));
        if ($file = $request->file('avatar')) {
            $this->avatars->update($teamMember, $file);
        }

        return $this->success(TeamMemberResource::make($teamMember->refresh()), __('team::messages.updated'));
    }

    public function destroy(TeamMember $teamMember): JsonResponse
    {
        $teamMember->clearMediaCollection('avatar');
        $teamMember->delete();

        return $this->noContent(__('team::messages.deleted'));
    }
}
