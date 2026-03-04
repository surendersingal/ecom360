<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen on the channel.
|
*/

/*
|--------------------------------------------------------------------------
| Session-scoped private channel for real-time interventions.
|--------------------------------------------------------------------------
|
| The frontend JS SDK subscribes to `private-session.{sessionId}` to
| receive FrontendInterventionRequired events (popups, discounts, etc.)
| broadcast by the Dynamic Rules Engine.
|
| Authorization: Any authenticated user can listen on any session channel.
| In production, you would validate that the session belongs to the user's
| tenant context.
*/
Broadcast::channel('session.{sessionId}', function ($user, string $sessionId) {
    return $user !== null;
});
