# Feature parity: where we stand and what is missing

Measured against the platforms an organizer here would otherwise be using:
**DICE** and **Resident Advisor** for nightlife, **Fatsoma** and **Skiddle** for
promoter-led events, **Eventbrite** and **Ticket Tailor** for the general
market, **Ticketmaster** where a venue is large and seated.

Not all of it is worth having. This platform sells the night out in Canada and
Nigeria — that is what the footer says and what the seed data is — so a
conference badge printer is not a gap and a table package is. Each entry below
says what it would cost us, not what it costs them.

Sizes are rough and relative: **S** is days, **M** is a week or two, **L** is
longer than a month or needs a decision first. They assume the person doing it
already knows this codebase.

---

## Where we already match them

Worth stating plainly, because it is what makes the list of gaps short enough
to act on.

| | |
| --- | --- |
| Selling | add-ons sold beside a ticket — tables, bottles, merchandise; questions at checkout, asked once or of each person; tiers with their own prices, quantities, per-order limits, sales windows and ladders (`opens_after_id`); group tickets that admit several; inventory holds during checkout; waitlists; presale access codes; discount and promoter codes with batches |
| Money | Stripe and Paystack, guest checkout, quotes before commitment, tax rates, gateway fees recorded per order, refunds, a ledger, settlements, payout details behind KYC, payout requests with staff approval |
| The door | scanning by camera or code, selling to walk-ups and reconciling the till, a saved list and a queue that work with no signal, scoped door passes for staff phones, guest lists, ticket transfers |
| Attendees | tickets on the phone that work offline, transfers, saved events, following organizers, reminders, add to calendar |
| Organizers | dashboard, sales and orders, refunds, email to ticket holders, roles and permissions, multiple organizations, recurring series, cloning, images, their own brand and their own public page |
| Platform | admin panel, audit trail, access logging on sensitive data, tax rates, cancellations with refunds |
| Reach | server-rendered pages that unfurl, structured data, sitemap, promoter attribution through `?ref=`, announcements to followers |

---

## The gaps

### 1. A public page for an organizer — **done, 17 September 2026**

`/o/{slug}` on the public site and at the same address in the app: who they
are, what is on, what has been. The organizer card on an event page leads to
it, the sitemap lists it, and the console shows an organizer the link with a
way to open it.

The follow button is in the app only, and that is a decision rather than an
omission — the site has no accounts to hang following on. Both are recorded in
[DECISIONS.md](DECISIONS.md), along with why an organization that has never
published gets a 404 instead of an empty page.

### 2. Questions at checkout — **done, 17 September 2026**

The question model the invitation flow already had, renamed to what it always
was and put in front of somebody buying a ticket. Asked once for the order, or
about each person on it; the answers reach the guest list, the export, and the
door screen under the ticket that was just scanned.

Not scoped to a ticket type — a phone number that only matters for a table is
the obvious next turn, and it is left out deliberately rather than half-built.
That and the other decisions this raised are in [DECISIONS.md](DECISIONS.md).

### 3. Add-ons: tables, bottles, merchandise — **done, 17 September 2026**

A thing with a price and a stock, sold on the same order, settled through the
same ledger, and admitting nobody. An order line now carries either a ticket
type or an add-on, and everything that counts tickets says so rather than
counting lines.

Bought with a ticket and never instead of one. Organizers price them on an
Extras tab; buyers add them under the tiers; the sales screen counts them
apart from the room. The decisions this raised are in
[DECISIONS.md](DECISIONS.md).

### 4. Selling at the door — **done, 17 September 2026**

A walk-up is an ordinary order taken on the door phone: the same stock, the
same tickets, the same reports, and three columns saying how it was paid, who
took it and on which pass. Sell, then scan them in, on one screen.

The money stays where it is. The platform charges nothing on cash it never
touched, and the ledger records the sale and then takes the organizer's share
back out, because they are holding it already. The console shows the till: per
method, because a tin and a terminal each have to agree with their own thing,
and per door after that.

Selling lives on the phone rather than in the console — that is where the door
is. The decisions are in [DECISIONS.md](DECISIONS.md).

### 5. Face-value resale — **L, and a decision**

This is DICE's whole argument, and it matters most in exactly our market: a
sold-out night whose tickets reappear at triple on Instagram. Returning a
ticket to a waiting list at the price paid takes touts out of it.

It is long because it is money moving between two strangers: a return, a
refund, a reissue, and a fraud surface that has to be thought about before any
of it is written. The waitlist and transfer machinery are the foundation, and
both already exist.

Worth deciding whether this is a differentiator we want before it is scheduled.

### 6. Wallet passes — **M**, blocked

Apple Wallet needs a signing certificate; Google Wallet needs an issuer
account. Neither is in the repository, and the pass format cannot be tested
without them. Nothing else blocks it: a ticket already knows everything a pass
would carry.

### 7. Push notifications — **M**, blocked

FCM and APNs credentials. The list a push would ride on — who follows whom —
is built and working, and announcements already go out by email. Push is an
addition to that rather than a replacement: a mailbox reaches somebody who
installed the app once in June.

### 8. Camera scanning on iPhone — **M**, and a decision

Already written up in [DECISIONS.md](DECISIONS.md). The ML Kit scanner ships a
CocoaPods podspec and no `Package.swift`, and the iOS project links through
SPM, so `cap sync` silently omits it. Android scans; iPhone offers the code
box. The way out is moving iOS to CocoaPods or finding a scanner that ships
SPM.

### 9. Organizers selling from their own site — **done, 19 September 2026**

One script tag and one element, from the event's overview in the console: a
button that opens the tickets over the organizer's page, or the tickets laid
into it. The buying steps are the ordinary ones under `/embed/`; payment opens
in its own tab, because processors will not be framed, and the frame follows
the order until it is paid and tells the page around it. Only `/embed/` can be
framed — every other page now refuses, which nothing did before.

### 10. Webhooks and keys for organizers — **done, 19 September 2026**

An owner can point up to five addresses at `order.paid`, `order.refunded` and
`ticket.checked_in`, and make read-only keys for `/api/v1` — events, orders
and attendees, paged by cursor, with `since` for a sync that only wants what
changed. One payload shape serves both directions, and neither ever carries a
ticket code.

Every delivery is signed, retried from the scheduler for most of a day, and
recorded with what came back; an address that fails twenty-five times in a
row is switched off and says why. Addresses are checked for being on the
public internet when saved and again at every delivery. The console's
Integrations screen is owners-only. The decisions are in
[DECISIONS.md](DECISIONS.md).

### 11. Campaigns, not just messages — **done, 19 September 2026**

Three lists, chosen and never edited: followers, people who came in the last
two years, and people who got as far as a basket for the event in the last
thirty days. Written once, sent now or at a time, and counted afterwards by
the orders, tickets and money that came through the email's link — no pixel.

Each list drops anybody who turned marketing off, anybody already holding a
ticket to the night being sold, and anybody this organizer wrote to in the
last week. Owners, managers and marketing can send; every send is in the audit
trail.

Building it found that the unsubscribe on a follower announcement had never
stopped announcements — it switched off reminders instead. Links now say
which kind of mail they stop.

### 12. SMS — **S to M**

Worth more in Nigeria than in Canada, where email deliverability is weaker and
a phone number is the reliable address. Orders already collect `buyer_phone`.
Scope it to the messages that matter: the ticket itself, and doors-in-three-hours.

### 13. Analytics an organizer can act on — **done, 19 September 2026**

The sales screen now opens with four numbers: how many looked, how many of
them bought, how many came, and how that stands against the same point before
the last night. Under them, the pace of both nights lined up by days to go,
where the buyers came from — found it themselves, a promoter's link, one of
your emails, your own website, the door — and the two nights side by side on
the same measures.

Looking is counted as a number per event per day, from the browser, once a
visit. Nothing about who: no address, no cookie, no visitor, so there is
nothing to export or erase and nothing to ask anybody about.

### 14. Instalments — **M**, market-dependent

Festival tickets at a hundred dollars-plus sell better in four payments, and
both our gateways support it. For a twenty-dollar club night it is noise.
Schedule it when the first festival signs, not before.

### 15. Reserved seating — **L**

A seat map, holds against seats, and a picker in the checkout. Genuinely large,
and almost irrelevant to a dance floor. It matters the day a theatre or a
seated concert hall wants to use this, and not before — but it is the single
biggest thing on this list, so it should be a deliberate "not yet" rather than
an oversight.

### 16. Data requests — **done, 19 September 2026**

Asked for from the privacy page, by anybody with an address and no account.
A link goes to that address and nothing happens until it comes back — an
erasure that ran on a typed-in address would be a way to delete a stranger.

An export is every row the map points at, as one file on the private disk for
a week, without ticket codes or anything else that opens a door. An erasure
runs the map's three strategies and says which happened to what: rows deleted,
rows kept without the person in them, and the two things kept on purpose — the
suppression list, because forgetting it starts the emails again, and the
security log. Somebody who is the only owner of an organization is refused,
with the step to take first.

Staff see the queue and anything past its thirty days in the admin panel, which
should always be empty: requests are carried out the moment they are proved.

### 17. Chargebacks and fraud — **M**

`ProcessedWebhook` handles the gateway's messages; a dispute has no home in the
admin panel, and nothing flags the patterns — many orders on one card, one
address across many accounts — that precede one.

### 18. More than one language — **L**

Nothing in here is translated. It is a large change touching every string, and
it is the right call to defer it while both markets sell in English.

---

## Sequencing

**Done, and they were the cheap two:** the organizer page (1) and checkout
questions (2). Both made something already built mean more — following now
leads somewhere, and the question model the invitation flow had is now the
order form organizers ask for by name.

**Done:** add-ons (3) and selling at the door (4) — together, the difference
between a ticketing tool and the thing a club runs its night on.

**Done:** webhooks and keys (10), selling from an organizer's own site (9) and
campaigns (11) — the block that makes larger promoters possible — and the
analytics an organizer can act on (13).

**Done:** data requests (16).

**Next:** chargebacks (17), then SMS (12) — which needs a provider account in
each market before it can send anything — and resale (5).

**In parallel, whenever the credentials land:** wallet passes (6), push (7).
Neither needs design work — only keys — so they should be picked up the week
they arrive rather than scheduled.

**Deliberately later:** resale (5) until we decide whether it is our argument,
instalments (14) until a festival needs it, reserved seating (15) until a
seated venue does, translation (18) while both markets read English.

**On a date, not a backlog:** data requests (16). Compliance work that waits
for a request is compliance work done badly.

---

## Two operational gaps that are not features

Neither is on any competitor's page, and both would be felt immediately.

**Nothing runs the queue or the scheduler** unless a deployment says so. Every
email in this system is queued, and six scheduled commands matter — one
releases stock from abandoned baskets, one retries webhooks, one sends
scheduled campaigns. Both are now in
[the API's README](../apps/api/README.md); they need to be in the deployment
before launch, not at it.

**There is no staging environment in this repository.** Every address is
supplied at run time precisely so one build can serve staging and production —
`npm run check` holds that true — but nothing here describes where staging is.
