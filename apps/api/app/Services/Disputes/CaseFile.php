<?php

namespace App\Services\Disputes;

use App\Models\Dispute;
use App\Models\DoorPass;
use App\Models\Event;
use App\Models\EventCompletion;
use App\Models\Order;
use App\Models\PaymentEvidence;
use App\Models\Refund;
use App\Models\ResaleListing;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketScan;
use App\Models\TicketTransfer;
use App\Services\Receipts\Receipt;
use App\Services\StaffSupport\MaskedCode;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything the records say about one disputed order, read once.
 *
 * The evidence (EvidenceDraft) and the documents (EvidenceDocuments) are both
 * written from this, and only from this, so what staff read on the page, what
 * goes in each field and what the PDFs say can never tell three different
 * stories. Each fact here is a row somebody can point at: the order, the
 * processor's record of the payment, the ticket history, the door's scans, the
 * night's completion record, the refunds, the refund policy kept for the
 * version the buyer accepted.
 *
 * Tickets are named by type and the masked end of their code — never the code,
 * which opens a door and is going to a bank. Other people's email addresses (a
 * ticket passed on to a friend) are masked the same way, and where they opened
 * it from is left out altogether: the dispute is the buyer's, not theirs.
 */
final class CaseFile
{
    /** Times are written like this everywhere a bank reads them: one zone, no guessing. */
    public const TIME = 'j M Y, H:i:s \U\T\C';

    /** A moment as the evidence writes it, in UTC whatever zone it was read in. */
    public static function at(?CarbonInterface $moment): string
    {
        return $moment === null ? '—' : CarbonImmutable::instance($moment)->utc()->format(self::TIME);
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @param  Collection<int, TicketActivity>  $activity
     * @param  Collection<int, TicketScan>  $scans
     * @param  Collection<string, string>  $doorPasses  id => label
     * @param  Collection<int, TicketTransfer>  $transfers
     * @param  Collection<int, Refund>  $refunds
     * @param  Collection<int, ResaleListing>  $resales
     * @param  Collection<int, Order>  $priorOrders
     * @param  Collection<int, Order>  $sameNight
     */
    public function __construct(
        public readonly Dispute $dispute,
        public readonly Order $order,
        public readonly ?Event $event,
        public readonly Collection $tickets,
        public readonly Collection $activity,
        public readonly Collection $scans,
        public readonly Collection $doorPasses,
        public readonly Collection $transfers,
        public readonly Collection $refunds,
        public readonly Collection $resales,
        public readonly ?EventCompletion $completion,
        public readonly ?PaymentEvidence $payment,
        public readonly Collection $priorOrders,
        public readonly Collection $sameNight,
        public readonly ?string $policy,
        public readonly ?string $policySummary,
    ) {}

    /** Read everything about the dispute's order. */
    public static function for(Dispute $dispute): self
    {
        /** @var Order $order */
        $order = Order::query()
            ->with([
                'lines',
                'event' => fn ($query) => $query->withTrashed()->with([
                    'venue' => fn ($venue) => $venue->withTrashed(),
                    'organization' => fn ($organization) => $organization->withTrashed(),
                ]),
            ])
            ->findOrFail($dispute->order_id);

        $tickets = Ticket::query()
            ->with(['ticketType' => fn ($type) => $type->withTrashed()->select('id', 'name')])
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $ticketIds = $tickets->modelKeys();

        $activity = TicketActivity::query()
            ->where(fn ($query) => $query->where('order_id', $order->id)->orWhereIn('ticket_id', $ticketIds))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $scans = $ticketIds === [] ? collect() : TicketScan::query()
            ->with('scanner:id,name')
            ->whereIn('ticket_id', $ticketIds)
            ->orderBy('scanned_at')
            ->get();

        $passes = $scans->pluck('door_pass_id')->filter()->unique()->values();

        $refunds = Refund::query()->where('order_id', $order->id)->orderBy('created_at')->get();

        $policy = app(RefundPolicy::class);
        $version = $order->terms_version;

        return new self(
            dispute: $dispute,
            order: $order,
            event: $order->event,
            tickets: $tickets,
            activity: $activity,
            scans: $scans,
            doorPasses: $passes->isEmpty() ? collect() : DoorPass::query()->whereIn('id', $passes->all())->pluck('label', 'id'),
            transfers: $ticketIds === [] ? collect() : TicketTransfer::query()->whereIn('ticket_id', $ticketIds)->orderBy('transferred_at')->get(),
            refunds: $refunds,
            resales: ResaleListing::query()->where('order_id', $order->id)->orderBy('listed_at')->get(),
            completion: EventCompletion::query()->where('event_id', $order->event_id)->first(),
            payment: PaymentEvidence::query()->where('order_id', $order->id)->first(),
            priorOrders: self::priorOrders($order),
            sameNight: self::sameNight($order),
            policy: $version === null ? null : $policy->text($version),
            policySummary: $version === null ? null : $policy->summary($version),
        );
    }

    // --- the payment ------------------------------------------------------------

    public function gateway(): string
    {
        return (string) ($this->order->gateway ?? $this->dispute->gateway);
    }

    public function processorName(): string
    {
        return match ($this->gateway()) {
            'stripe' => 'Stripe',
            'paystack' => 'Paystack',
            default => 'the payment processor',
        };
    }

    /** @return array<string, mixed> the processor's record of the payment, when it was kept */
    public function paymentFacts(): array
    {
        return $this->payment?->isCaptured() ? (array) $this->payment->facts : [];
    }

    /** @return array<string, mixed> Stripe's charge, or nothing */
    public function charge(): array
    {
        return (array) ($this->paymentFacts()['charge'] ?? []);
    }

    /** @return array<string, mixed> the card, as Stripe or Paystack described it */
    public function card(): array
    {
        $facts = $this->paymentFacts();

        return (array) ($facts['charge']['card'] ?? $facts['authorization'] ?? []);
    }

    /** "Visa ending 4242", or null when the processor's record was not kept. */
    public function cardLine(): ?string
    {
        $card = $this->card();
        $last4 = $card['last4'] ?? null;

        if (! is_string($last4) || $last4 === '') {
            return null;
        }

        $brand = trim((string) ($card['brand'] ?? $card['card_type'] ?? 'Card'));

        return ucfirst($brand).' ending '.$last4;
    }

    /** @return array<string, mixed>|null 3D Secure as Stripe recorded it */
    public function threeDSecure(): ?array
    {
        $secure = $this->card()['three_d_secure'] ?? null;

        return is_array($secure) && $secure !== [] ? $secure : null;
    }

    /**
     * Whether the card's bank checked it was the cardholder.
     *
     * Stripe's result of authenticated, or on older versions authenticated
     * true; either way with the network's own indicator where it gave one — 05
     * (Visa, Amex) and 02 (Mastercard) are a full authentication. An attempt
     * (06, 01) is not counted here, though it is still shown.
     */
    public function authenticated(): bool
    {
        $secure = $this->threeDSecure();

        if ($secure === null) {
            return false;
        }

        $eci = (string) ($secure['electronic_commerce_indicator'] ?? '');

        return (($secure['result'] ?? null) === 'authenticated' || ($secure['authenticated'] ?? null) === true)
            && ($eci === '' || in_array($eci, ['05', '02'], true));
    }

    /** "authenticated (3D Secure 2.2.0, challenge, ECI 05)", in Stripe's own words. */
    public function threeDSecureLine(): ?string
    {
        $secure = $this->threeDSecure();

        if ($secure === null) {
            return null;
        }

        $result = $secure['result'] ?? (($secure['authenticated'] ?? null) === true ? 'authenticated' : 'not authenticated');

        $how = array_filter([
            isset($secure['version']) ? '3D Secure '.$secure['version'] : '3D Secure',
            $secure['authentication_flow'] ?? null,
            isset($secure['electronic_commerce_indicator']) ? 'ECI '.$secure['electronic_commerce_indicator'] : null,
            isset($secure['result_reason']) ? 'reason: '.str_replace('_', ' ', (string) $secure['result_reason']) : null,
        ]);

        return str_replace('_', ' ', (string) $result).' ('.implode(', ', $how).')';
    }

    /** "CVC pass, postcode pass" — the checks the card's bank answered. */
    public function checksLine(): ?string
    {
        $checks = (array) ($this->card()['checks'] ?? []);

        $said = array_filter([
            isset($checks['cvc_check']) ? 'CVC '.$checks['cvc_check'] : null,
            isset($checks['address_postal_code_check']) ? 'postcode '.$checks['address_postal_code_check'] : null,
            isset($checks['address_line1_check']) ? 'street address '.$checks['address_line1_check'] : null,
        ]);

        return $said === [] ? null : implode(', ', $said);
    }

    public function cvcPassed(): bool
    {
        return ($this->card()['checks']['cvc_check'] ?? null) === 'pass';
    }

    /** "normal (score 32), authorized by the network" — Stripe's fraud checks. */
    public function riskLine(): ?string
    {
        $outcome = (array) ($this->charge()['outcome'] ?? []);

        if (($outcome['risk_level'] ?? null) === null && ($outcome['network_status'] ?? null) === null) {
            return null;
        }

        return trim(implode(', ', array_filter([
            isset($outcome['risk_level']) ? 'risk '.$outcome['risk_level'].(isset($outcome['risk_score']) ? ' (score '.$outcome['risk_score'].')' : '') : null,
            isset($outcome['network_status']) ? str_replace('_', ' ', (string) $outcome['network_status']) : null,
        ])));
    }

    public function statementDescriptor(): ?string
    {
        $line = $this->charge()['statement_descriptor'] ?? null;

        return is_string($line) && $line !== '' ? $line : null;
    }

    /** Stripe's charge id for the disputed payment, or Paystack's transaction id. */
    public function chargeId(): ?string
    {
        $facts = $this->paymentFacts();
        $id = $facts['charge']['id'] ?? $facts['transaction']['id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    /** What Stripe's own terms box on its payment page said, when Stripe asked. */
    public function stripeConsent(): ?string
    {
        $checkout = (array) ($this->payment->checkout ?? []);
        $consent = $checkout['terms_of_service'] ?? null;

        return is_string($consent) && $consent !== '' ? $consent : null;
    }

    // --- the order ----------------------------------------------------------------

    public function money(int $minorUnits, ?string $currency = null): string
    {
        return Money::of($minorUnits, $currency ?? $this->order->currency)->format();
    }

    public function total(): string
    {
        return $this->money((int) $this->order->total_amount);
    }

    public function receipt(): Receipt
    {
        return Receipt::for($this->order);
    }

    /** "2 × General, 1 × VIP". */
    public function whatWasBought(): string
    {
        $lines = $this->order->lines
            ->map(fn ($line) => $line->quantity.' × '.$line->name)
            ->implode(', ');

        return $lines === '' ? $this->tickets->count().' ticket(s)' : $lines;
    }

    /*
     * The night as the bank is told it.
     *
     * Once the night has been written down (EventCompletions), from that
     * record alone: the listing as it stood when the door closed. The event
     * row stays editable by its organizer afterwards, and an organizer with a
     * chargeback on the line must not be able to change the title or the date
     * the bank reads. Before then — a dispute on a night still to come — the
     * listing is all there is, and it is read as it is now.
     */

    /** "Afro Fest", "Sat 24 Oct 2026, 22:00 (America/Toronto)", "The Great Hall, 1 Queen St W, Toronto". */
    public function eventTitle(): string
    {
        return (string) ($this->completion->title ?? $this->event->title ?? 'the event');
    }

    public function eventWhen(): ?string
    {
        $night = $this->completion ?? $this->event;
        $start = $night?->starts_at;

        if ($start === null) {
            return null;
        }

        $zone = (string) ($night->timezone ?? 'UTC');
        $local = CarbonImmutable::instance($start)->setTimezone($zone);
        $end = $night->ends_at === null ? null : CarbonImmutable::instance($night->ends_at)->setTimezone($zone);

        return $local->format('D j M Y, H:i')
            .($end === null ? '' : ' to '.$end->format($local->isSameDay($end) ? 'H:i' : 'D j M, H:i'))
            .' ('.$zone.')';
    }

    public function eventWhere(): ?string
    {
        if ($this->completion !== null) {
            // The venue and city it was written down with; the venue's own
            // row, and its street address, can be edited since.
            $parts = array_filter([$this->completion->venue, $this->completion->city]);

            return $parts === [] ? null : implode(', ', array_unique($parts));
        }

        $venue = $this->event?->venue;

        $parts = array_filter([
            $venue?->name,
            $venue?->address_line,
            $venue->city ?? $this->event?->city,
        ]);

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    public function organizer(): ?string
    {
        return $this->event?->organization?->name;
    }

    /** Whether the night was called off or taken down. */
    public function wasCancelled(): bool
    {
        return $this->event !== null
            && ($this->event->status === 'cancelled' || $this->event->cancelled_at !== null || $this->event->taken_down_at !== null);
    }

    /**
     * Whether the night has happened: written down as having taken place, or
     * one of the order's tickets let in at the door, or — for a night nobody
     * has written down yet — its listed start is behind us. The listing only
     * decides when nothing on record does, since its date can be moved.
     */
    public function hasHappened(): bool
    {
        if ($this->completion !== null || $this->admissions()->isNotEmpty()) {
            return true;
        }

        $start = $this->event?->starts_at;

        return $start !== null && $start->isPast();
    }

    public function refunded(): int
    {
        return (int) $this->refunds->where('status', 'succeeded')->sum('amount');
    }

    // --- the tickets ----------------------------------------------------------------

    /** "General · ••••7KQ2" — never the code itself. */
    public function ticketLabel(?Ticket $ticket): string
    {
        if ($ticket === null) {
            return 'a ticket';
        }

        return ($ticket->ticketType->name ?? 'Ticket').' · '.MaskedCode::of($ticket->code);
    }

    public function ticketById(?string $id): ?Ticket
    {
        return $id === null ? null : $this->tickets->firstWhere('id', $id);
    }

    public function buyerEmail(): ?string
    {
        return $this->order->buyer_email;
    }

    /** @return Collection<int, TicketActivity> each email that went to the buyer's own address */
    public function emailsToBuyer(): Collection
    {
        return $this->activity
            ->where('kind', TicketActivity::EMAILED)
            ->filter(fn (TicketActivity $row) => in_array(true, array_map(fn (string $address) => $this->isBuyers($address), self::addresses($row->recipient_email)), true))
            ->values();
    }

    /** Whether an address is the buyer's own: the whole address, not a part of one. */
    public function isBuyers(?string $address): bool
    {
        $buyer = Str::lower(trim((string) $this->buyerEmail()));

        return $buyer !== '' && Str::lower(trim((string) $address)) === $buyer;
    }

    /** @return list<string> each address an email went to, as it was written down */
    private static function addresses(?string $recipients): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $recipients)), fn (string $address) => $address !== ''));
    }

    /** How many emails about the order went to somebody other than the buyer. */
    public function emailsToOthers(): int
    {
        return $this->activity->where('kind', TicketActivity::EMAILED)->count() - $this->emailsToBuyer()->count();
    }

    /** @return Collection<int, TicketActivity> the tickets opened, shown or downloaded */
    public function openings(): Collection
    {
        return $this->activity->whereIn('kind', TicketActivity::ACCESS)->values();
    }

    /**
     * Whether an opening was the buyer's own, rather than the app of somebody
     * the ticket had been passed on to by then — the last transfer before it
     * went to another address. Only the buyer's own openings carry an address
     * or a browser anywhere a bank reads them.
     */
    public function byBuyer(TicketActivity $row): bool
    {
        if ($row->kind !== TicketActivity::QR_IN_APP || $row->ticket_id === null) {
            return true;
        }

        $last = $this->transfers
            ->where('ticket_id', $row->ticket_id)
            ->filter(fn (TicketTransfer $transfer) => $transfer->transferred_at !== null && $transfer->transferred_at->lessThanOrEqualTo($row->occurred_at))
            ->sortBy('transferred_at')
            ->last();

        return $last === null || $this->isBuyers($last->to_email);
    }

    /** The address an opening came from, when it was the buyer's and one was kept. */
    public function addressOf(TicketActivity $row): ?string
    {
        return $this->byBuyer($row) && $row->ip_address !== null && $row->ip_address !== '' ? $row->ip_address : null;
    }

    /** @return Collection<int, TicketScan> the scans that let somebody in */
    public function admissions(): Collection
    {
        return $this->scans->where('result', 'accepted')->values();
    }

    /** How many of the order's tickets were let in at least once. */
    public function ticketsAdmitted(): int
    {
        return $this->admissions()->pluck('ticket_id')->unique()->count();
    }

    public function issuedAt(): ?CarbonInterface
    {
        return $this->activity->firstWhere('kind', TicketActivity::ISSUED)->occurred_at
            ?? $this->tickets->min('created_at');
    }

    /** Who was on the door: the member of the team, or the door phone's name. */
    public function scannedBy(TicketScan $scan): string
    {
        return $scan->scanner->name
            ?? ($scan->door_pass_id !== null ? ($this->doorPasses[$scan->door_pass_id] ?? 'a door phone') : 'door staff');
    }

    public static function scanResult(string $result): string
    {
        return match ($result) {
            'accepted' => 'let in',
            'duplicate' => 'turned away: already used',
            'void' => 'turned away: ticket cancelled',
            'wrong_event' => 'turned away: another night\'s ticket',
            'over_capacity' => 'turned away: room full',
            'not_found' => 'turned away: not recognised',
            default => str_replace('_', ' ', $result),
        };
    }

    /** a•••@example.com: enough to tell addresses apart, not enough to write to. */
    public static function maskEmail(?string $address): string
    {
        $address = (string) $address;
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return '•••';
        }

        return mb_substr($address, 0, 1).'•••'.substr($address, $at);
    }

    /** A browser's name for itself, cut to what a person reads. */
    public static function browser(?string $agent): string
    {
        return $agent === null || $agent === '' ? 'browser not given' : Str::limit($agent, 160);
    }

    // --- the story ------------------------------------------------------------------

    /**
     * Everything that happened, oldest first: the sale, the tickets, the door,
     * the night, the refunds, the dispute.
     *
     * @return list<array{at: CarbonInterface, what: string, detail: string|null}>
     */
    public function timeline(): array
    {
        $order = $this->order;
        $entries = [];
        $add = function (?CarbonInterface $at, string $what, ?string $detail = null) use (&$entries) {
            if ($at !== null) {
                $entries[] = ['at' => $at, 'what' => $what, 'detail' => $detail];
            }
        };

        $add($order->created_at, 'Order '.$order->reference.' placed online by '.$order->buyer_name.' ('.$order->buyer_email.')',
            $order->purchase_ip ? 'From internet address '.$order->purchase_ip.', '.self::browser($order->purchase_user_agent) : null);

        if ($order->terms_accepted_at !== null) {
            $add($order->terms_accepted_at, 'Accepted the terms, the privacy policy and the refund policy (version '.$order->terms_version.')', 'By ticking the box at checkout');
        }

        $add($order->paid_at, 'Paid '.$this->total().($this->cardLine() ? ' by '.$this->cardLine() : '').' through '.$this->processorName(),
            implode('; ', array_filter([
                $this->threeDSecureLine() ? '3D Secure: '.$this->threeDSecureLine() : null,
                $this->checksLine() ? 'Card checks: '.$this->checksLine() : null,
                $this->statementDescriptor() ? 'Statement line: '.$this->statementDescriptor() : null,
            ])) ?: null);

        foreach ($this->activity as $row) {
            $ticket = $this->ticketById($row->ticket_id);

            match ($row->kind) {
                TicketActivity::ISSUED => $add($row->occurred_at, ((int) ($row->details['count'] ?? 0)).' ticket(s) issued',
                    collect((array) ($row->details['tickets'] ?? []))->map(fn ($id) => $this->ticketLabel($this->ticketById((string) $id)))->implode('; ')),
                TicketActivity::EMAILED => $add($row->occurred_at, $this->emailLine($row), $row->message_id ? 'Mail provider\'s id: '.$row->message_id : null),
                TicketActivity::TICKET_PAGE => $add($row->occurred_at, 'Ticket page opened from the link in the email', $this->where($row)),
                TicketActivity::ORDER_LINK => $add($row->occurred_at, 'Tickets opened from the signed order link', $this->where($row)),
                TicketActivity::QR_IN_APP => $this->byBuyer($row)
                    ? $add($row->occurred_at, 'Ticket '.$this->ticketLabel($ticket).' shown with its QR code in the myFiesta app', $this->where($row))
                    : $add($row->occurred_at, 'Ticket '.$this->ticketLabel($ticket).' shown with its QR code in the myFiesta app of the person it was passed on to'),
                TicketActivity::CALENDAR => $add($row->occurred_at, 'Night added to a calendar from the ticket page', $this->where($row)),
                TicketActivity::TRANSFERRED => $add($row->occurred_at, 'Ticket '.$this->ticketLabel($ticket).' passed on to '.self::maskEmail($this->transfers->firstWhere('id', $row->ticket_transfer_id)?->to_email)),
                default => null,
            };
        }

        foreach ($this->scans as $scan) {
            $add($scan->scanned_at, 'Ticket '.$this->ticketLabel($this->ticketById($scan->ticket_id)).' scanned at the door: '.self::scanResult((string) $scan->result),
                'Scanned by '.$this->scannedBy($scan).($scan->result === 'accepted' ? ', '.((int) $scan->admitted).' admitted' : ''));
        }

        foreach ($this->resales as $resale) {
            $add($resale->listed_at, 'Ticket '.$this->ticketLabel($this->ticketById($resale->ticket_id)).' given back by its holder for somebody else to buy');

            if ($resale->sold_at !== null) {
                $add($resale->sold_at, 'That place was bought by somebody else');
            }
        }

        foreach ($this->refunds as $refund) {
            $add($refund->created_at, 'Refund of '.$this->money((int) $refund->amount, $refund->currency).': '.match ($refund->status) {
                'succeeded' => 'made',
                'failed' => 'refused by the processor',
                default => 'asked for, not yet confirmed',
            }, $refund->reason ? 'Reason given: '.$refund->reason : null);
        }

        if ($this->completion !== null) {
            $add($this->completion->recorded_at, 'The night was recorded as having taken place', $this->completionLine());
        }

        if ($this->event?->cancelled_at !== null) {
            $add($this->event->cancelled_at, 'The organizer cancelled the night', $this->event->cancellation_reason);
        }

        $add($this->dispute->opened_at, 'The bank opened a dispute: '.Reasons::label($this->dispute->reason));

        usort($entries, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $entries;
    }

    /** "Door open from 22:10 to 03:40 UTC; 412 let in on 390 tickets; 9 turned away." */
    public function completionLine(): ?string
    {
        $night = $this->completion;

        if ($night === null) {
            return null;
        }

        return implode('; ', array_filter([
            $night->door_opened_at && $night->door_closed_at
                ? 'Door open from '.self::at($night->door_opened_at).' to '.self::at($night->door_closed_at)
                : 'No scans at the door',
            $night->people_admitted.' people let in on '.$night->tickets_issued.' ticket(s) issued',
            $night->turned_away > 0 ? $night->turned_away.' scan(s) turned away' : null,
        ])).'.';
    }

    /**
     * Every opening of the tickets, and every scan at the door, one line each:
     * what Stripe asks for as access_activity_log.
     *
     * An address and a browser only on the buyer's own openings: a ticket
     * passed on and shown in its new holder's app is said to have been, and
     * nothing more about them.
     */
    public function accessLog(): string
    {
        $lines = [];

        foreach ($this->openings() as $row) {
            $own = $this->byBuyer($row);

            $lines[] = [$row->occurred_at, implode(' | ', array_filter([
                self::at($row->occurred_at),
                match ($row->kind) {
                    TicketActivity::TICKET_PAGE => 'ticket page opened',
                    TicketActivity::ORDER_LINK => 'signed order link opened',
                    TicketActivity::QR_IN_APP => 'ticket '.$this->ticketLabel($this->ticketById($row->ticket_id)).' shown in the app'.($own ? '' : ' of the person it was passed on to'),
                    TicketActivity::CALENDAR => 'calendar file downloaded',
                    default => str_replace('_', ' ', $row->kind),
                },
                $this->addressOf($row) !== null ? 'IP '.$this->addressOf($row) : null,
                $own ? self::browser($row->user_agent) : null,
            ]))];
        }

        foreach ($this->scans as $scan) {
            $lines[] = [$scan->scanned_at, implode(' | ', [
                self::at($scan->scanned_at),
                'door scan of ticket '.$this->ticketLabel($this->ticketById($scan->ticket_id)).': '.self::scanResult((string) $scan->result),
                'by '.$this->scannedBy($scan),
            ])];
        }

        usort($lines, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return implode("\n", array_column($lines, 1));
    }

    /** Each address in full when it is the buyer's own, and masked when it is anybody else's. */
    private function emailLine(TicketActivity $row): string
    {
        $subject = $row->details['subject'] ?? null;

        $to = array_map(
            fn (string $address) => $this->isBuyers($address) ? $address : 'another holder, '.self::maskEmail($address),
            self::addresses($row->recipient_email),
        );

        return 'Email '.($subject ? '"'.$subject.'" ' : '').'sent to '.($to === [] ? 'an address not recorded' : implode('; ', $to));
    }

    private function where(TicketActivity $row): string
    {
        $address = $this->addressOf($row);

        return ($address !== null ? 'From internet address '.$address : 'Address not recorded').', '.self::browser($row->user_agent);
    }

    // --- the buyer's other orders -------------------------------------------------

    /**
     * Earlier orders from the same address, paid and never disputed.
     *
     * @return Collection<int, Order>
     */
    private static function priorOrders(Order $order): Collection
    {
        if ($order->buyer_email === null) {
            return collect();
        }

        return Order::query()
            ->with(['event:id,title,starts_at,timezone'])
            ->whereRaw('lower(buyer_email) = ?', [Str::lower($order->buyer_email)])
            ->whereKeyNot($order->id)
            ->whereIn('status', ['paid', 'partially_refunded'])
            ->whereNotNull('paid_at')
            ->where('paid_at', '<', $order->paid_at ?? $order->created_at ?? now())
            ->whereNull('disputed_at')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('disputes')->whereColumn('disputes.order_id', 'orders.id'))
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get();
    }

    /**
     * The buyer's other paid orders for the same night — what a "charged
     * twice" claim is usually about.
     *
     * @return Collection<int, Order>
     */
    private static function sameNight(Order $order): Collection
    {
        if ($order->buyer_email === null) {
            return collect();
        }

        return Order::query()
            ->whereRaw('lower(buyer_email) = ?', [Str::lower($order->buyer_email)])
            ->where('event_id', $order->event_id)
            ->whereKeyNot($order->id)
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->limit(10)
            ->get();
    }

    /** Whether any of an earlier order's tickets was let in at the door. */
    public static function wasUsed(Order $order): bool
    {
        return TicketScan::query()
            ->where('result', 'accepted')
            ->whereIn('ticket_id', Ticket::query()->where('order_id', $order->id)->select('id'))
            ->exists();
    }
}
