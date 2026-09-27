<?php

namespace App\Support\Session;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Carbon;

/**
 * Database sessions, where a signed-in session is kept for the staff lifetime.
 *
 * Only the admin panel signs anybody in to a browser session — the apps use
 * API tokens — so a session row with a user on it is a staff member's. Staff
 * are not timed out (KeepStaffSignedIn), but the admin is not the only thing
 * that starts sessions: every webhook post and unsubscribe page does too, with
 * the ordinary two-hour lifetime, and the stock handler's garbage collection
 * runs from those requests as well. It would delete a staff session idle for
 * more than two hours, and the open admin tab's next click would come back
 * "page expired".
 *
 * So both of the stock handler's lifetime decisions — is this row too old to
 * read, and is it old enough to delete — give a signed-in row the staff
 * lifetime whichever request is asking. Rows with nobody on them keep the
 * ordinary lifetime and are collected as before.
 *
 * If some other part of the site ever signs people in to browser sessions,
 * their sessions last as long as staff ones. That would be a deliberate change
 * worth revisiting this for.
 */
class StaffSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  int  $minutes  the ordinary lifetime, as the stock handler takes it
     * @param  int  $staffMinutes  how long a signed-in session may sit idle
     */
    public function __construct(
        ConnectionInterface $connection,
        $table,
        $minutes,
        private readonly int $staffMinutes,
        ?Container $container = null,
    ) {
        parent::__construct($connection, $table, $minutes, $container);
    }

    protected function expired($session)
    {
        if (! isset($session->last_activity)) {
            return false;
        }

        $minutes = empty($session->user_id)
            ? (int) $this->minutes
            : max((int) $this->minutes, $this->staffMinutes);

        return $session->last_activity < Carbon::now()->subMinutes($minutes)->getTimestamp();
    }

    public function gc($lifetime): int
    {
        $now = $this->currentTime();

        $anonymous = $this->getQuery()
            ->whereNull('user_id')
            ->where('last_activity', '<=', $now - $lifetime)
            ->delete();

        $signedIn = $this->getQuery()
            ->whereNotNull('user_id')
            ->where('last_activity', '<=', $now - max($lifetime, $this->staffMinutes * 60))
            ->delete();

        return $anonymous + $signedIn;
    }
}
