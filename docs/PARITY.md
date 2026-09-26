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
| Selling | add-ons sold beside a ticket — tables, bottles, merchandise; questions at checkout, asked once or of each person; tiers with their own prices, quantities, per-order limits, sales windows and ladders (`opens_after_id`); group tickets that admit several; inventory holds during checkout; waitlists; presale access codes; discount and promoter codes with batches; the same checkout embedded in an organizer's own website |
| Money | Stripe and Paystack, guest checkout, quotes before commitment, tax rates, gateway fees recorded per order, refunds, chargebacks answered and accounted for separately, a ledger, settlements, payout details behind KYC, payout requests with staff approval |
| The door | scanning by camera or code, selling to walk-ups and reconciling the till, a saved list and a queue that work with no signal, scoped door passes for staff phones, guest lists, ticket transfers |
| Attendees | tickets on the phone that work offline, transfers, giving a ticket back at what was paid for it, saved events, following organizers, reminders by email and text, add to calendar, asking for their data or to be forgotten |
| Organizers | dashboard, sales and orders, refunds, email to ticket holders, campaigns to people who have not bought yet, what each one sold, conversion and turnout against their own last night, webhooks and read-only API keys, roles and permissions, multiple organizations, recurring series, cloning, images, their own brand and their own public page |
| Platform | admin panel, audit trail, access logging on sensitive data, privacy requests answered on the spot, tax rates, cancellations with refunds |
| Reach | server-rendered pages that unfurl, structured data, sitemap, promoter attribution through `?ref=`, announcements to followers, campaigns counted by what they actually sold |

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

### 5. Face-value resale — **done, 19 September 2026**, one decision taken by default

Built as a return rather than a marketplace, which removes the fraud surface
instead of policing it. Somebody who cannot go hands the ticket back from the
link in their email; it stops working that moment, and the place goes back
into the event's ordinary stock because availability counts live tickets. The
next buyer goes through the ordinary checkout at the organizer's own price and
is never told whose place it was, because it was not anybody's — they bought
from the organizer, and a stranger got their money back.

The seller is paid when the place sells, not when they return it, and gets
exactly what they paid including the booking fee. Returns are the organizer's
to allow, per event, and close 24 hours before the doors.

**The decision was taken by default and is worth revisiting.** Face value
only, no choosing whose ticket to buy, no asking price, seller paid on sale.
That is the strictest reading of "takes touts out of it" and the easiest to
explain; a looser one — letting sellers set a price, or paying them at once —
is a different product and a much larger fraud surface.

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

### 8. Camera scanning on iPhone — **done, 26 September 2026**

The iPhone reads codes inside the WebView. The camera comes through
`getUserMedia`, and ZXing compiled to WebAssembly reads the frames, standing
in for the `BarcodeDetector` Safari does not have. The `.wasm` ships inside
the app rather than coming from a CDN, so a door with no signal still scans.
Android keeps ML Kit, which links there.

Neither of the two ways out it was waiting on turned out to be needed: iOS
still links through SPM and ML Kit is still absent from it, which
`npm run check` records as a covered gap. Why the WebView won is in
[DECISIONS.md](DECISIONS.md).

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

### 12. SMS — **built, waiting on an account**

Both messages are wired: the ticket link when the payment settles, and the
last reminder before the doors. Nothing sells anything, because a marketing
text needs consent this platform does not collect in either market.

With no credentials the log driver writes what it would have sent and reports
success, so the whole path runs in development and in the tests. Filling in
`TERMII_API_KEY` (or Twilio's) and setting `SMS_DRIVER` is the only step
between that and real messages. `SMS_COUNTRIES` decides where a text is worth
its cost — Nigeria by default, since that is where it is the message that gets
read.

STOP works before anything is switched on: replies land on a secret-bearing
webhook and the number goes on a suppression list that is honoured for every
message, including somebody's own ticket, and survives erasure.

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

### 17. Chargebacks and fraud — **done, 19 September 2026**

A dispute used to arrive, get written to the log, and stop there. It is now a
record against the order: staff see the queue and the date it has to be
answered by, the organizer sees it on the order, and anybody listening for
`order.disputed` hears about it.

Opening one changes nothing else — a claim is not a verdict, and voiding a
ticket on one would turn a bank's paperwork into somebody being turned away.
Losing one writes a negative `chargeback` entry to the ledger and voids the
tickets it paid for. It is never counted as a refund: that is the organizer's
own decision, and this is a bank's.

The fraud half is two facts on the order list rather than a score — this
address won a chargeback before, or has placed several orders today — because
a number out of a hundred invites refusing somebody on a hunch the platform
made up.

Building it found two bugs in the payment plumbing, both from orders knowing
only the checkout session: a Stripe dispute names the payment, so it could
never have been matched to an order, and refunds were sending that session id
to Stripe as a payment intent.

### 18. More than one language — **L**

Nothing in here is translated. It is a large change touching every string, and
it is the right call to defer it while both markets sell in English.

---

## Sequencing

**Done, in this order.** The organizer page (1) and checkout questions (2)
first, because both made something already built mean more. Then add-ons (3)
and selling at the door (4) — together, the difference between a ticketing
tool and the thing a club runs its night on. Then the block that makes larger
promoters possible: webhooks and keys (10), selling from an organizer's own
site (9), campaigns (11), and the analytics to judge all three by (13). Then
the two compliance pieces that should never wait for the first request or the
first chargeback: data requests (16) and disputes (17). Then resale (5), built
as a return rather than a marketplace. Then camera scanning on iPhone (8),
answered in the WebView rather than by changing how iOS links its plugins.

**Built, waiting on an account:** SMS (12). The path runs end to end against a
log driver; a provider account in each market is the only missing piece.

**Waiting on credentials, and nothing else:** wallet passes (6) and push (7).
Neither needs design work, so both should be picked up the week the keys
arrive rather than scheduled.

**Deliberately later:** instalments (14) until a festival needs them, reserved
seating (15) until a seated venue does, translation (18) while both markets
read English.

---

## The two operational gaps — **closed, 20 September 2026**

Neither is on any competitor's page and both would have been felt in the first
hour.

**Nothing ran the queue or the scheduler** unless a deployment said so, and
neither failure says anything: with no worker, an order is paid, tickets are
minted, and the buyer is told nothing; with no scheduler, abandoned baskets
hold stock until a night reads as sold out that nobody bought. Both are now in
`ops/docker/compose.prod.yml` and in `ops/systemd/` for a host without
containers, and [DEPLOYMENT.md](DEPLOYMENT.md) opens with them.

**Nothing described where staging is.** Every address is supplied at run time
precisely so one build serves both, and that is now written down along with the
two things staging must not share: a database with real addresses in it, and a
mailer that can reach real people.

The console was the missing half of the run-time address arrangement — its
meta tags were stamped by nobody, which would have pointed every organizer's
console at their own laptop. Its image stamps them at start-up and refuses to
start without them, and `npm run check` now holds that to the same standard as
the site and the phone app.
