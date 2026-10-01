<?php

// Sending a ticket to somebody else, from the web tickets page and the phone.

use App\Http\Controllers\Api\TicketTransferController;
use Illuminate\Support\Facades\Route;

/*
 * "Send to someone", on the same credential as the tickets themselves: the
 * order's link, or the link a ticket was sent with (TicketLink). As many
 * tries as giving one back. The phone's own route, for a signed-in holder,
 * is POST /tickets/{ticket}/transfer in routes/api.php, through the same
 * handover (TicketHandover).
 */
Route::post('/tickets/{token}/transfer/{ticket}', [TicketTransferController::class, 'store'])
    ->whereUuid('ticket')
    ->middleware('throttle:20,1');
