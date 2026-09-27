<?php

/*
|--------------------------------------------------------------------------
| Answering a chargeback
|--------------------------------------------------------------------------
|
| A buyer can tell their bank a payment was wrong for months after the night,
| and on the old platform every one of those was lost: nothing had been kept
| that a bank would accept. What is kept now is written as it happens, from
| sources a bank trusts — our own server, the processor's own API, the door —
| and nothing is ever edited afterwards. docs/DECISIONS.md has the reasoning.
|
| Only the timings live here. What is kept, and why, is in the classes under
| App\Services\Disputes and in the migrations that made their tables.
|
*/

return [

    /*
     * How long the evidence outlives the night.
     *
     * Eighteen months after the event, which is past every card network's
     * window for disputing a payment — the longest, a service not provided,
     * runs 540 days from the transaction — and then it goes: the address and
     * browser on each order, the ticket activity, and the processor's record
     * (disputes:prune-evidence). Anything about an order whose dispute is
     * still open waits until it closes. Orders, tickets, scans, the ledger
     * and the audit trail are records and are never pruned.
     */
    'retention_months' => 18,

    'evidence' => [
        /*
         * When to ask the processor again, in minutes after each failed try.
         *
         * The first try is the next sweep after the payment lands. A
         * processor having a bad hour is caught by the early ones; one down
         * for longer is caught within the day. After the last, the row says
         * so and a person can look (docs/OPERATIONS.md).
         */
        'retry_after_minutes' => [5, 15, 60, 360, 1440],

        // How many to ask about in one sweep. Each is a request or two to a
        // processor, and the sweep runs every five minutes.
        'batch' => 50,

        // A paid order with no row at all is picked up for this long — the
        // notice that should have made it may have failed after the payment
        // was recorded. Older than this, it is left alone.
        'look_back_days' => 7,
    ],

    'activity' => [
        /*
         * The same thing opened again from the same address within this many
         * minutes is not written down twice, whatever the browser calls
         * itself.
         *
         * The app refreshes its list whenever it comes back to the front, and
         * a page is reloaded. A row for each would bury the openings that mean
         * something under the ones that are a timer — and a new row for every
         * new browser name would let anybody holding a link pad the history.
         */
        'repeat_minutes' => 10,
    ],

    'completion' => [
        /*
         * How long after the door closes before the night is written down.
         *
         * A door phone that lost its signal keeps scanning and sends its scans
         * when it finds one again, which can be the next morning. Written down
         * before then, the night would be missing its last hour.
         */
        'after_door_closes_hours' => 12,
    ],

];
