<?php

namespace Modules\Clients\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Clients\Http\Requests\Client\ReplyTicketRequest;
use Modules\Clients\Http\Requests\Client\StoreTicketRequest;
use Modules\Clients\Http\Resources\TicketResource;
use Modules\Clients\Models\Client;
use Modules\Clients\Models\SupportTicket;
use Modules\Clients\Notifications\NewTicketNotification;
use Modules\Clients\Notifications\TicketClientReplyNotification;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Users\Models\User;

class TicketController extends ApiController
{
    /**
     * GET /api/v1/client/tickets
     *
     * Not paginated (like reviews); newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $tickets = $request->user('client')->tickets()
            ->withCount('messages')
            ->latest('id')
            ->get();

        return $this->success(TicketResource::collection($tickets));
    }

    /**
     * POST /api/v1/client/tickets
     *
     * Creates the ticket and its first message in one call.
     */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user('client');

        $ticket = DB::transaction(function () use ($client, $request) {
            $ticket = $client->tickets()->create([
                'subject' => $request->validated('subject'),
                'type' => $request->validated('type'),
                'status' => SupportTicket::STATUS_OPEN,
            ]);

            $ticket->messages()->create([
                'sender_type' => Client::class,
                'sender_id' => $client->id,
                'body' => $request->validated('message'),
            ]);

            return $ticket;
        });

        Notification::send(
            User::staff()->active()->permission('view-tickets')->get(),
            new NewTicketNotification($ticket->load('client')),
        );

        return $this->created(
            TicketResource::make($ticket->load(['messages.sender', 'client'])),
            __('core::messages.created'),
        );
    }

    /**
     * GET /api/v1/client/tickets/{ticket}
     */
    public function show(Request $request, string $ticket): JsonResponse
    {
        /** @var SupportTicket $ticket */
        $ticket = $request->user('client')->tickets()
            ->with(['messages.sender', 'client'])
            ->findOrFail($ticket);

        return $this->success(TicketResource::make($ticket));
    }

    /**
     * POST /api/v1/client/tickets/{ticket}/reply
     *
     * Replying to a closed ticket reopens it.
     */
    public function reply(ReplyTicketRequest $request, string $ticket): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user('client');

        /** @var SupportTicket $ticket */
        $ticket = $client->tickets()->findOrFail($ticket);

        $ticket->messages()->create([
            'sender_type' => Client::class,
            'sender_id' => $client->id,
            'body' => $request->validated('message'),
        ]);

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            $ticket->update([
                'status' => SupportTicket::STATUS_OPEN,
                'closed_at' => null,
            ]);
        }

        Notification::send(
            User::staff()->active()->permission('view-tickets')->get(),
            new TicketClientReplyNotification($ticket->load('client')),
        );

        return $this->success(
            TicketResource::make($ticket->fresh(['messages.sender', 'client'])),
        );
    }
}
