<?php

namespace Modules\Clients\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Clients\Http\Requests\Admin\ReplyTicketRequest;
use Modules\Clients\Http\Requests\Admin\UpdateTicketRequest;
use Modules\Clients\Http\Resources\TicketResource;
use Modules\Clients\Models\SupportTicket;
use Modules\Clients\Notifications\TicketReplyNotification;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Models\User;

class TicketController extends ApiController
{
    /**
     * GET /api/v1/admin/tickets?status=&type=&search=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->with('client')
            ->withCount('messages')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('subject', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('company_name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(15);

        return $this->paginated(TicketResource::collection($tickets));
    }

    /**
     * GET /api/v1/admin/tickets/{ticket}
     */
    public function show(string $ticket): JsonResponse
    {
        $ticket = SupportTicket::with(['client', 'messages.sender'])->findOrFail($ticket);

        return $this->success(TicketResource::make($ticket));
    }

    /**
     * POST /api/v1/admin/tickets/{ticket}/reply
     *
     * A staff reply moves an open ticket to in_progress.
     */
    public function reply(ReplyTicketRequest $request, string $ticket): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('admin');

        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::findOrFail($ticket);

        $ticket->messages()->create([
            'sender_type' => User::class,
            'sender_id' => $user->id,
            'body' => $request->validated('message'),
        ]);

        if ($ticket->status === SupportTicket::STATUS_OPEN) {
            $ticket->update(['status' => SupportTicket::STATUS_IN_PROGRESS]);
        }

        $ticket->client->notify(new TicketReplyNotification($ticket, $user->name));

        return $this->success(
            TicketResource::make($ticket->fresh(['client', 'messages.sender'])),
        );
    }

    /**
     * PATCH /api/v1/admin/tickets/{ticket}
     *
     * Status management: open | in_progress | closed.
     */
    public function update(UpdateTicketRequest $request, string $ticket): JsonResponse
    {
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::findOrFail($ticket);

        $status = $request->validated('status');

        $ticket->update([
            'status' => $status,
            'closed_at' => $status === SupportTicket::STATUS_CLOSED ? now() : null,
        ]);

        return $this->success(
            TicketResource::make($ticket->fresh(['client', 'messages.sender'])),
        );
    }
}
