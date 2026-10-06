<?php

namespace Modules\SupportTickets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Core\Support\QueryFilters;
use Modules\SupportTickets\Enums\TicketStatus;
use Modules\SupportTickets\Http\Requests\StoreMessageRequest;
use Modules\SupportTickets\Http\Requests\StoreTicketRequest;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Services\SupportTicketService;
use Modules\SupportTickets\Transformers\SupportTicketMessageResource;
use Modules\SupportTickets\Transformers\SupportTicketResource;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupportTicketsController extends ApiController
{
    public function __construct(private SupportTicketService $service) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user('client') ?? $request->user('admin');
        $query = SupportTicket::query()->with(['client', 'consultant'])->latest('last_message_at');
        if ($request->user('client')) {
            $query->where('client_id', $actor->id);
        } else {
            $query->visibleTo($actor);
        }
        foreach (['status', 'category', 'client_id', 'consultant_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return $this->paginated(SupportTicketResource::collection($query->paginate(QueryFilters::perPage($request))));
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $ticket = $this->service->create($request->user('client'), $request->validated(), $request->file('image'), $request->file('voice'));

        return $this->created(SupportTicketResource::make($ticket), __('supporttickets::messages.created'));
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        $ticket = $this->findVisible($request, $ticket);
        $ticket->load(['client', 'consultant', 'messages' => fn ($query) => $query->when(! $this->isAdmin($request), fn ($q) => $q->where('is_internal', false))->with(['media', 'sender'])->oldest()]);

        return $this->success(SupportTicketResource::make($ticket));
    }

    public function message(StoreMessageRequest $request, string $ticket): JsonResponse
    {
        $ticket = $this->findVisible($request, $ticket);
        $message = $this->service->addMessage($ticket, $request->user('client') ?? $request->user('admin'), $request->validated('body'), $request->file('image'), $request->file('voice'), $request->boolean('is_internal'));

        return $this->created(SupportTicketMessageResource::make($message), __('supporttickets::messages.sent'));
    }

    public function updateStatus(Request $request, string $ticket): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403);
        $data = $request->validate(['status' => ['required', new Enum(TicketStatus::class)]]);
        $ticket = SupportTicket::query()->findOrFail($ticket);
        $ticket->update($data);

        return $this->success(SupportTicketResource::make($ticket->load(['client', 'consultant'])));
    }

    public function media(Request $request, string $ticket, string $message, string $collection): BinaryFileResponse
    {
        abort_unless(in_array($collection, ['image', 'voice'], true), 404);
        $ticket = $this->findVisible($request, $ticket);
        $message = $ticket->messages()->findOrFail($message);
        abort_if($message->is_internal && ! $this->isAdmin($request), 404);
        $media = $message->getFirstMedia($collection);
        abort_if($media === null, 404);

        return response()->file($media->getPath(), ['Content-Type' => $media->mime_type]);
    }

    private function findVisible(Request $request, string $id): SupportTicket
    {
        $query = SupportTicket::query();
        $actor = $request->user('client') ?? $request->user('admin');
        if ($request->user('client')) {
            $query->where('client_id', $actor->id);
        } else {
            $query->visibleTo($actor);
        }

        return $query->findOrFail($id);
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user('admin');

        return $user !== null && $user->isAdmin();
    }
}
