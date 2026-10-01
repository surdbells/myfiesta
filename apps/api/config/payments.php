<?php

return [
    /*
     * Which gateway handles which currency is decided by each adapter's
     * supports() method, not here — adding a processor should not mean editing
     * checkout. These are only credentials.
     */
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
         * The shortened descriptor set in Stripe's dashboard (Settings →
         * Business → Public details), which every card charge starts with.
         *
         * Stripe adds "* " and the night's name after it, and the whole line
         * on a bank statement may be 22 characters, so this is what decides
         * how much of the name fits (StatementDescriptor). Keep it the same
         * as the dashboard's: a longer one here only shortens the name, a
         * shorter one makes Stripe refuse the checkout as too long.
         */
        'statement_descriptor_prefix' => env('STRIPE_STATEMENT_DESCRIPTOR_PREFIX', 'MYFIESTA'),

        /*
         * Whether to ask the buyer's bank to check it is them (3D Secure).
         *
         * `automatic` lets Stripe ask when the bank or the rules want it;
         * `any` asks on every card that supports it. A payment the bank
         * checked is one a bank rarely lets its customer dispute as fraud —
         * the liability moves to them — at the cost of a step at checkout.
         * `challenge` asks for the step every time, which only makes sense
         * while a run of fraud is being dealt with.
         */
        'request_three_d_secure' => env('STRIPE_REQUEST_THREE_D_SECURE', 'automatic'),

        /*
         * Stripe's own terms box on its payment page, as well as ours.
         *
         * Off until the terms page address is set in Stripe's dashboard
         * (Settings → Business → Public details), since Stripe refuses every
         * checkout that asks without one. On, Stripe keeps its own record
         * that the box was ticked, which sits beside ours in a dispute.
         */
        'collect_terms_consent' => (bool) env('STRIPE_COLLECT_TERMS_CONSENT', false),

        /*
         * Which ways to pay Stripe's page offers, as two payment method
         * configurations made in Stripe's dashboard (Settings → Payments →
         * Payment methods; docs/OPERATIONS.md says how).
         *
         * `standard` is cards, wallets and Link, for every checkout. `pay_later`
         * is the same with Klarna and Affirm added, sent only for a night
         * whose organizer opted in and that can be paid later now (PayLater).
         * Unset, a checkout names no configuration and Stripe offers whatever
         * the account's default has on, which is how it worked before these
         * existed; with only `standard` set, nobody is offered paying later.
         */
        'payment_method_configurations' => [
            'standard' => env('STRIPE_PMC_STANDARD'),
            'pay_later' => env('STRIPE_PMC_PAY_LATER'),
        ],
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    ],

    /*
     * The service charge, in basis points. 8%.
     *
     * Added to what the buyer pays. It is not deducted from the organizer:
     * somebody selling a $50 ticket is owed $50, and the buyer is charged
     * $54.00. This is the platform's revenue and the only revenue it has.
     *
     * The direction matters more than the rate. Taking the same 8% out of the
     * organizer's side instead would be a pay cut to every organizer already
     * selling, delivered without anybody deciding it — which is precisely what
     * the rebuild did before this was checked against the live database.
     *
     * Charged on revenue net of tax and after any organizer discount, because
     * neither is money the organizer earned and neither is money we sell for.
     */
    'service_charge_bps' => (int) env('PLATFORM_SERVICE_CHARGE_BPS', 800),

    /*
     * What the processor takes, and who bears it.
     *
     * The platform does, out of the service charge. An organizer's payout is
     * the ticket price whether the buyer paid by card, wallet, or transfer,
     * and whether that card was domestic or not.
     *
     * Recorded per order rather than inferred at settlement, so margin is a
     * figure that can be reported rather than one that has to be reconstructed
     * from a processor's statement months later.
     *
     * `flat` is in minor units — 30 means 30 cents, not $0.30. The live
     * platform's stored formula added `0.30` to an amount already in cents,
     * which recorded Stripe's flat fee as three tenths of one cent and
     * overstated margin on every transaction it ever processed.
     */
    'gateway_fees' => [
        'stripe' => ['bps' => 290, 'flat' => 30],
        // Paying later costs more than a card, and the organizer who opted
        // in pays the difference (PayLater::premium). Stripe's published
        // Canadian rates; the fee Stripe reports on the payment replaces
        // these once it is known (ProcessorEvidence).
        'stripe:klarna' => ['bps' => 599, 'flat' => 30],
        'stripe:affirm' => ['bps' => 600, 'flat' => 30],
        'paystack' => ['bps' => 150, 'flat' => 0, 'cap' => 200000],
    ],

    /*
     * Buy now, pay later: Klarna and Affirm, through Stripe, in Canada.
     *
     * Off until staff turn it on (Platform settings), and then only on
     * nights whose organizer opted in. Only for nights this close: Affirm
     * takes a refund for 120 days after the payment and Klarna for 180, so a
     * night further out could be cancelled after the money can no longer go
     * back the way it came. Staff change both in the admin; these are what
     * applies until they do. Read them through PlatformSettings
     * (payLaterEnabled, payLaterMaxDaysBeforeEvent), never from here.
     */
    'pay_later' => [
        'enabled' => (bool) env('PAY_LATER_ENABLED', false),
        'max_days_before_event' => (int) env('PAY_LATER_MAX_DAYS_BEFORE_EVENT', 110),

        /*
         * Who lends, for what size of order, and for how long after the
         * payment the money can still go back through them. Stripe's terms
         * for a Canadian account charging dollars; amounts in cents.
         *
         * Afterpay is not here on purpose: its terms rule out selling
         * alcohol, which most of these nights do.
         */
        'currency' => 'CAD',
        'providers' => [
            'klarna' => ['name' => 'Klarna', 'min' => 100, 'max' => 150000, 'refund_days' => 180],
            'affirm' => ['name' => 'Affirm', 'min' => 5000, 'max' => 3000000, 'refund_days' => 120],
        ],
    ],
];
