<?php

/**
 * Where personal data lives.
 *
 * PIPEDA in Canada and the NDPR in Nigeria both grant people the right to see
 * what is held about them and to have it erased. Satisfying that means being
 * able to answer "where is this person?" across the whole schema — which is
 * trivial to maintain from the first migration and archaeology once there are
 * forty tables.
 *
 * This map is the input to the export and erasure jobs. It is deliberately
 * configuration rather than prose so it can be executed and tested: a feature
 * test walks every entry and fails if a table or column named here has gone
 * missing, so the map cannot drift away from the schema in silence.
 *
 * Adding a table that holds personal data means adding it here in the same
 * change. Treat a missing entry as a bug, not an oversight.
 *
 * Strategies:
 *   delete    remove the row outright
 *   anonymise blank the listed columns, keep the row for financial integrity
 *   retain    keep as-is; the legal basis is recorded in `reason`
 */

return [

    /*
     * Identified by user id. The person has an account, claimed or unclaimed.
     */
    'by_user' => [

        'users' => [
            'strategy' => 'anonymise',
            'key' => 'id',
            'columns' => ['name', 'email', 'phone', 'avatar_path', 'timezone'],
            'reason' => 'Row is retained because orders and tickets reference it; identifying fields are cleared.',
        ],

        'sessions' => [
            'strategy' => 'delete',
            'key' => 'user_id',
        ],

        'organization_user' => [
            'strategy' => 'delete',
            'key' => 'user_id',
            'reason' => 'Membership ends with the account. Organization-owned records survive.',
        ],

        'ticket_scans' => [
            'strategy' => 'anonymise',
            'key' => 'scanned_by',
            'columns' => ['scanned_by'],
            'reason' => 'The scan happened and stays in the record; who performed it is detached.',
        ],

        /*
         * The audit trail.
         *
         * Added deliberately rather than caught by the heuristic — `actor_label`
         * does not look like a personal column and holds somebody's name.
         *
         * Anonymised, not deleted. The rows are the record that a refund was
         * sent, a price was dropped, an event was cancelled; deleting them
         * because the person who did it left would destroy the history of money
         * that moved, which both PIPEDA and the NDPR permit retaining. What goes
         * is the name: the action survives, the identity does not, and the
         * foreign key is already nullOnDelete so the join disappears with the
         * account.
         */
        'audit_logs' => [
            'strategy' => 'anonymise',
            'key' => 'actor_id',
            'columns' => ['actor_label', 'ip_address'],
            'reason' => 'The record that something happened is a financial record. Who did it is not, once they have asked to be forgotten.',
        ],

        'sensitive_data_accesses' => [
            'strategy' => 'retain',
            'key' => 'user_id',
            'reason' => 'Security audit trail. Erasing it would defeat the log that exists to detect misuse.',
        ],
    ],

    /*
     * Identified by email address. Guest checkout means much personal data is
     * attached to an address rather than an account — an erasure request from
     * someone who never registered still has to find these.
     */
    'by_email' => [

        'orders' => [
            'strategy' => 'anonymise',
            'key' => 'buyer_email',
            'columns' => ['buyer_email', 'buyer_name', 'buyer_phone'],
            'reason' => 'Financial record. Amounts, tax, and commission are retained; the buyer is detached.',
        ],

        'tickets' => [
            'strategy' => 'anonymise',
            'key' => 'owner_email',
            'columns' => ['owner_email', 'holder_name'],
            'reason' => 'Admission record for a real event. Identity is cleared, the ticket remains.',
        ],

        'ticket_transfers' => [
            'strategy' => 'anonymise',
            'key' => 'from_email',
            'columns' => ['from_email', 'to_email'],
        ],

        /*
         * The privacy requests themselves, and the one place keeping an
         * address is the point.
         *
         * A record that somebody asked to be erased, and what was done about
         * it, is the evidence that the law was obeyed — erasing it with them
         * would leave nothing to show for the request but their absence. The
         * export file it points at is deleted after a week; the row stays,
         * holding the address it was about and nothing else about them.
         */
        'data_requests' => [
            'strategy' => 'retain',
            'key' => 'email',
            'reason' => 'Proof that a privacy request was made and answered. Erasing it destroys the record of the erasure.',
        ],

        'password_reset_tokens' => [
            'strategy' => 'delete',
            'key' => 'email',
        ],

        /*
         * The suppression list, and the one entry here that survives erasure.
         *
         * Somebody who unsubscribes and then asks to be erased must stay
         * unsubscribed. Deleting the row would forget the refusal, and the next
         * time that address buys a ticket the reminders start again — which is
         * the exact harm the opt-out existed to prevent, arrived at by way of a
         * privacy request.
         *
         * Both PIPEDA and the NDPR allow retaining the minimum needed to honour
         * a legal obligation, and a record that says "do not email this
         * address" is that minimum. The address is the whole record; there is
         * nothing else in the row to clear.
         */
        'email_preferences' => [
            'strategy' => 'retain',
            'key' => 'email',
            'reason' => 'A suppression list only works if it outlives the account. Deleting it re-subscribes somebody who asked to be left alone.',
        ],

        'reminder_deliveries' => [
            'strategy' => 'delete',
            'key' => 'email',
            'reason' => 'Only exists to stop a resumed send emailing somebody twice. Once the tickets are gone there is nothing left to send.',
        ],

        'campaign_deliveries' => [
            'strategy' => 'delete',
            'key' => 'email',
            'reason' => 'Stops a resumed send writing to somebody twice, and one organizer writing to them more than once a week. Nothing is lost by forgetting it.',
        ],

        'event_message_deliveries' => [
            'strategy' => 'delete',
            'key' => 'email',
            'reason' => 'Same job as reminder_deliveries: it stops a resumed send writing to the same person twice, and has no value once their tickets are gone.',
        ],

        /*
         * Invites+ guests.
         *
         * The most exposed people in the system: added to a list by somebody
         * else, usually without an account, and often without having asked to
         * be there at all. An erasure request from a wedding guest is entirely
         * foreseeable, and deleting outright is right here — unlike an order,
         * a guest row carries no financial obligation to retain.
         */
        'guests' => [
            'strategy' => 'delete',
            'key' => 'email',
            'reason' => 'Cascades to their RSVP and answers. No retention duty applies.',
        ],

        // An address an owner typed in to invite. Nothing depends on it once
        // it is accepted or lapses; the audit log records that it happened.
        'organization_invitations' => [
            'strategy' => 'delete',
            'key' => 'email',
            'reason' => 'An offer to join a team. No transaction and no retention duty.',
        ],

        // Somebody asked to be told if tickets came up. Nothing is owed to
        // anybody on the strength of it, so an erasure request removes it.
        'waitlist_entries' => [
            'strategy' => 'delete',
            'key' => 'email',
            'reason' => 'A request to be emailed about one event. No transaction and no retention duty.',
        ],
    ],

    /*
     * Identified by phone number.
     *
     * A third way to be somebody here, and the shortest: a number is given at
     * checkout and the only thing kept against it is whether it has asked to
     * stop being texted.
     */
    'by_phone' => [

        /*
         * Numbers that replied STOP.
         *
         * Kept for the same reason as email_preferences and erased for none:
         * forgetting that somebody asked not to be texted starts the texts
         * again the next time they buy a ticket. Keyed by the number, which is
         * the whole of the record.
         */
        'phone_preferences' => [
            'strategy' => 'retain',
            'key' => 'phone',
            'reason' => 'A suppression list only works if it outlives the order. Deleting it starts texting somebody who asked us to stop.',
        ],

    ],

    /*
     * Held about an organization rather than an individual, but personal in
     * substance — legal names, dates of birth, government identifiers, bank
     * details. Encrypted at rest, access-logged, and never returned by a
     * general-purpose endpoint.
     */
    'organization_scoped' => [

        'organization_payout_details' => [
            'strategy' => 'delete',
            'key' => 'organization_id',
            'encrypted' => [
                'interac_email', 'bank_name', 'account_name', 'account_number',
                'transit_number', 'institution_number', 'bank_code',
            ],
        ],

        'organization_identity_documents' => [
            'strategy' => 'delete',
            'key' => 'organization_id',
            'encrypted' => [
                'legal_first_name', 'legal_last_name', 'date_of_birth',
                'document_number', 'expires_on',
            ],
            'files' => ['document_path'],
            'reason' => 'Stored on the private disk; the file is removed with the row.',
        ],

        /*
         * A copy of what was sent to an organizer's own systems — buyers'
         * names and addresses inside the payload. Kept thirty days for
         * debugging a receiver, pruned daily after that (webhooks:prune), and
         * gone with the organization.
         *
         * Not erased per buyer: once delivered, the organizer's copy is theirs
         * to answer for, and ours expires on its own.
         */
        'webhook_deliveries' => [
            'strategy' => 'delete',
            'key' => 'organization_id',
            'columns' => ['payload', 'response_excerpt'],
        ],

        'webhook_endpoints' => [
            'strategy' => 'delete',
            'key' => 'organization_id',
            'encrypted' => ['secret'],
        ],
    ],

    /*
     * Erasure cannot be unconditional. A request that would destroy a record
     * still required for tax or accounting is refused with a reason, not
     * silently partially applied.
     */
    'retention' => [
        'financial_records_years' => 7,
        'note' => 'Orders, ledger entries, and settlements are anonymised rather than deleted until this period lapses.',
    ],
];
