<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Clients\Models\Client;
use Modules\Users\Models\User;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Two private channels, one per notifiable type. A notification pushed to
| `admin.{id}` reaches a staff user or consultant (admin guard), while
| `client.{id}` reaches a client (client guard).
|
| The `guards` option tells the broadcaster which guard must resolve the
| user, so a client token can never subscribe to an admin channel and
| vice versa — even when both models share the same numeric id.
|
*/

Broadcast::channel('admin.{id}', function (?User $user, string $id) {
    return $user !== null && (string) $user->getKey() === $id;
}, ['guards' => ['admin']]);

Broadcast::channel('client.{id}', function (?Client $user, string $id) {
    return $user !== null && (string) $user->getKey() === $id;
}, ['guards' => ['client']]);
