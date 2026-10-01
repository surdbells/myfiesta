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
 *
 * And, on any entry:
 *   via         the table holds no address or account of its own, only a
 *               key to one that does: `key` is the column pointing at it and
 *               `via` names that table, its `column` (id when left out) and
 *               the `key` there that holds the person. Found and erased
 *               before anything else, while the parent still says whose it is.
 *   erase_where only these rows are erased (all of them are exported): each
 *               column's value must be the id of a row of `table` whose
 *               `column` is one of `in`.
 *   files       columns holding a path on `disk`; the file goes when the row
 *               is erased, once the erasure has committed.
 *
 * Each feature added since has an anchor below, in each section, to add its
 * tables under, so two features never edit the same lines.
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
            // A profile photo is somebody's face: it goes from the disk, not
            // just from the column that named it.
            'files' => ['avatar_path'],
            'disk' => 'public',
            'reason' => 'Row is retained because orders and tickets reference it; identifying fields are cleared.',
        ],

        'sessions' => [
            'strategy' => 'delete',
            'key' => 'user_id',
        ],

        // A new address somebody asked to move to and has not confirmed. Its
        // link works for an hour at most, and it means nothing once the
        // account is gone.
        'email_changes' => [
            'strategy' => 'delete',
            'key' => 'user_id',
        ],

        // Two lists a person keeps for themselves: nights saved for later,
        // and organizers followed. A browsing habit, owed to nobody. The
        // account row is kept rather than deleted, so its cascade never
        // fires and these would otherwise outlive the person.
        'saved_events' => [
            'strategy' => 'delete',
            'key' => 'user_id',
            'reason' => 'A private list. No transaction and no retention duty.',
        ],

        'organization_follows' => [
            'strategy' => 'delete',
            'key' => 'user_id',
            'reason' => 'A private list; an organizer is only ever told how many follow them. No retention duty.',
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
         * Kept as it is. The rows are the record that a refund was sent, a
         * price was dropped, an event was cancelled, and who did it: the
         * question an organization asks of its own history is "who dropped the
         * price at 11pm?", and an answer that vanishes when that person leaves
         * is no answer. The table is append-only, enforced by a trigger, so an
         * erasure that tried to blank the name would be refused by the
         * database halfway through — which is what this entry used to do.
         * Both PIPEDA and the NDPR permit keeping a record needed to account
         * for money and to detect misuse. The account the rows point at is
         * anonymised like any other, so nothing new can be learned through
         * the join.
         */
        'audit_logs' => [
            'strategy' => 'retain',
            'key' => 'actor_id',
            'reason' => 'What you did on an organizer\'s team or as myFiesta staff — a refund, a price change, a cancelled event — stays in that history under the name you had then, with the internet address it came from. Nobody can edit it afterwards, us included, which is what makes it worth keeping.',
        ],

        // Sending an event for review, taking it back, and staff deciding it
        // (EventReviews). The history an organizer is shown on the event.
        'event_reviews' => [
            'strategy' => 'retain',
            'key' => 'actor_id',
            'reason' => 'Sending an event to myFiesta for review, taking it back, and a reviewer\'s decision on it stay in that event\'s review history under the name you had then, so the organization and myFiesta can both see who did what. It records what happened to an event, not anything else about you.',
        ],

        'sensitive_data_accesses' => [
            'strategy' => 'retain',
            'key' => 'user_id',
            'reason' => 'Security audit trail. Erasing it would defeat the log that exists to detect misuse.',
        ],

        // Staff opening an organization's console as it. Kept, like the
        // audit trail it sits beside, as the record that it happened and
        // why; the name and addresses of the member of staff go.
        'impersonation_sessions' => [
            'strategy' => 'anonymise',
            'key' => 'staff_user_id',
            'columns' => ['staff_label', 'started_ip', 'opened_ip'],
            'reason' => 'The record that staff acted inside an organization is kept for that organization; who did it is not, once they have asked to be forgotten.',
        ],

        // --- track: pay ---
        // --- track: transfer ---
        // --- track: profile ---
        // --- track: public ---
        // --- track: sched ---
        // --- track: clone ---
        // --- track: share ---
        // --- track: survey ---
        // --- track: points ---
        // --- track: pass ---
        // --- track: wait ---
        // --- track: audience ---
    ],

    /*
     * Identified by email address. Guest checkout means much personal data is
     * attached to an address rather than an account — an erasure request from
     * someone who never registered still has to find these.
     */
    'by_email' => [

        // The address and browser an online order came from go with the
        // buyer: kept to answer a disputed payment, and a person who has
        // asked to be forgotten is no longer somebody an order is traced to.
        'orders' => [
            'strategy' => 'anonymise',
            'key' => 'buyer_email',
            'columns' => ['buyer_email', 'buyer_name', 'buyer_phone', 'purchase_ip', 'purchase_user_agent'],
            'reason' => 'Financial record. Amounts, tax, and commission are retained; the buyer is detached.',
        ],

        /*
         * What a buyer answered at checkout: the name on each ticket, a
         * phone number for a table, dietary needs, how they heard.
         *
         * Holding no address of its own, so reached through the orders
         * placed under the address, and — for an answer about one person on
         * an order — through the ticket that person holds, often on somebody
         * else's order. "Grace is diabetic" is Grace's as much as the
         * buyer's. Erased before those orders and tickets lose the address,
         * or nothing would lead here afterwards (Eraser).
         *
         * What was typed goes: it can say anything about anybody. What was
         * picked from the organizer's own list stays, saying nothing about
         * anybody once its order no longer names them, and still in the
         * counts the organizer caters from. All of it is in an export.
         */
        'order_answers' => [
            'strategy' => 'delete',
            'key' => 'order_id',
            'via' => [
                ['table' => 'orders', 'column' => 'id', 'key' => 'buyer_email'],
                ['table' => 'tickets', 'column' => 'id', 'key' => 'owner_email', 'through' => 'ticket_id'],
            ],
            'erase_where' => [
                'event_question_id' => ['table' => 'event_questions', 'column' => 'type', 'in' => ['text']],
            ],
            'reason' => 'What you typed in answer to an organizer\'s questions is deleted. An answer picked from their list stays in their totals, and says nothing about you once your order no longer names you.',
        ],

        /*
         * What happened to an order's tickets, and the processor's record of
         * its payment: kept to answer a bank that is asked to take the money
         * back, and deleted 18 months after the event (disputes:prune-evidence),
         * past every card network's window for a dispute.
         *
         * Kept through an erasure until then. Both tables are append-only, so
         * the database would refuse to blank them anyway; and both laws permit
         * keeping what is needed to answer a legal claim for as long as one
         * can be made. The order they point at loses its buyer like any other.
         *
         * Found by the address an email went to, and the address a receipt
         * went to. The rows that are an opening of a ticket link are tied to
         * the order rather than to an address, and go with the rest.
         */
        'ticket_activity' => [
            'strategy' => 'retain',
            'key' => 'recipient_email',
            'reason' => 'A record of the ticket emails sent to you and of your ticket links being opened, kept to answer your bank if a payment is disputed. Nobody can edit it, and it is deleted 18 months after the event.',
        ],

        'payment_evidence' => [
            'strategy' => 'retain',
            'key' => 'receipt_email',
            'reason' => 'The payment processor\'s own record of your payment — card brand and last four digits, never the number, and whether your bank checked it was you — kept to answer your bank if the payment is disputed, and deleted 18 months after the event.',
        ],

        // What was said to a bank about a payment the buyer disputed: made
        // from the two tables above and the order, kept for the same reason
        // and deleted with them once the dispute has closed.
        'dispute_evidence' => [
            'strategy' => 'retain',
            'key' => 'customer_email',
            'reason' => 'What we sent your bank, through Stripe or Paystack, when you disputed a payment: kept while the dispute is open and after it as the record of what was said, and deleted 18 months after the event.',
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

        // A sign-up nobody has confirmed yet: a name, and a password hash
        // for an account that does not exist. Worth nothing once erased, and
        // gone within a day in any case.
        'pending_registrations' => [
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

        // --- track: pay ---
        // --- track: transfer ---
        // --- track: profile ---
        // --- track: public ---
        // --- track: sched ---
        // --- track: clone ---
        // --- track: share ---
        // --- track: survey ---
        // --- track: points ---
        // --- track: pass ---
        // --- track: wait ---
        // --- track: audience ---
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
            'disk' => 'private',
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
