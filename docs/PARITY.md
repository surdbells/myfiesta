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
| Selling | tiers with their own prices, quantities, per-order limits, sales windows and ladders (`opens_after_id`); group tickets that admit several; inventory holds during checkout; waitlists; presale access codes; discount and promoter codes with batches |
| Money | Stripe and Paystack, guest checkout, quotes before commitment, tax rates, gateway fees recorded per order, refunds, a ledger, settlements, payout details behind KYC, payout requests with staff approval |
| The door | scanning by camera or code, a saved list and a queue that work with no signal, scoped door passes for staff phones, guest lists, ticket transfers |
| Attendees | tickets on the phone that work offline, transfers, saved events, following organizers, reminders, add to calendar |
| Organizers | dashboard, sales and orders, refunds, email to ticket holders, roles and permissions, multiple organizations, recurring series, cloning, images, their own brand |
| Platform | admin panel, audit trail, access logging on sensitive data, tax rates, cancellations with refunds |
| Reach | server-rendered pages that unfurl, structured data, sitemap, promoter attribution through `?ref=`, announcements to followers |

---

## The gaps

### 1. A public page for an organizer — **S**

Every comparable platform has one. RA's promoter pages and DICE's artist pages
are where people actually browse; ours is the one link an organizer cannot
share. We have everything behind it already — a brand with a name, a mark, a
description and a verified tick, plus followers who asked to hear from them —
and nowhere to put it.

`/o/{slug}` on the public site: who they are, what is on, what has been, and a
follow button. The API returns all of it today.

Do this first. It is the cheapest thing on the list and it is the one that
makes following mean something.

### 2. Questions at checkout — **M**

Eventbrite's order forms are the feature organizers ask for by name. We have
`rsvp_questions` already, with per-attendee support, but they are wired to the
invitation flow and its `guests` table — a ticketed order has nowhere to put an
answer.

What it is for here: the name on each ticket when a door checks ID, a phone
number for a table booking, dietary needs for a dinner, "how did you hear about
this" for promoters.

Reuse the question model, add answers against `order_lines`, put them in the
guest list and the export. The door screen should show them under a scanned
ticket, which is the whole reason for collecting them.

### 3. Add-ons: tables, bottles, merchandise — **M**

The domain has one sellable thing, a `ticket_type`. Nightlife's margin is in
the second thing: a table with two bottles, a cloakroom pass, a shirt.

Modelled honestly this is a product alongside the ticket, with its own stock,
appearing on the same order and the same settlement, and admitting nobody at
the door. A table that admits six is a ticket type; a bottle on that table is
not.

### 4. Selling at the door — **M**

Walk-ups are a large share of a club night, and today the only way to issue on
the spot is a comp. Taking a card at the door means a terminal or a payment
link, an order that looks like any other, and a till reconciliation the
organizer can read the next morning.

The pieces exist — issuing, orders, the ledger. What is missing is a screen
built for standing up, and a cash line in the takings so the two numbers agree.

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

### 9. Organizers selling from their own site — **M**

An embeddable widget, or at minimum a checkout that survives being opened in an
iframe from a promoter's own page. Every competitor has this and it is how a
venue with an existing website adopts a ticketing platform without rebuilding
anything.

### 10. Webhooks and keys for organizers — **M**

We consume webhooks from gateways; we emit none. A larger promoter wants
`order.paid` arriving at their own system, and an API key to read their own
sales. This is also the cheapest route to the integrations everybody asks for
by name — Zapier, Mailchimp, a CRM — without building any of them.

### 11. Campaigns, not just messages — **M**

`EventMessage` sends to everybody holding a ticket for one event. A campaign is
choosing who — people who came last time, people who abandoned a basket, people
who follow and have not bought — writing once, scheduling it, and seeing what
it did. The audience data is all in the database already.

### 12. SMS — **S to M**

Worth more in Nigeria than in Canada, where email deliverability is weaker and
a phone number is the reliable address. Orders already collect `buyer_phone`.
Scope it to the messages that matter: the ticket itself, and doors-in-three-hours.

### 13. Analytics an organizer can act on — **M**

Today: what sold and what it earned. Missing: where the buyers came from (we
capture `ref_slug` and never report on it), how many who opened the page
bought, how many who bought turned up, and how this night compares to the last
one. Attendance rate in particular is ours to give — we own the door.

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

### 16. Data requests — **S to M**, compliance

PIPEDA in Canada and NDPR in Nigeria both give people the right to a copy of
their data and to have it deleted. We have the audit trail to do it honestly
and the append-only constraint that makes "delete everything" a question rather
than a `DELETE` — which is exactly the design conversation to have before the
first request arrives rather than during it.

### 17. Chargebacks and fraud — **M**

`ProcessedWebhook` handles the gateway's messages; a dispute has no home in the
admin panel, and nothing flags the patterns — many orders on one card, one
address across many accounts — that precede one.

### 18. More than one language — **L**

Nothing in here is translated. It is a large change touching every string, and
it is the right call to defer it while both markets sell in English.

---

## Sequencing

**Now, because they are cheap and unlock what is already built:** the organizer
page (1), then checkout questions (2). Both are small, both make existing
features mean more, and the first one is a day's work against an API that
already returns everything it needs.

**Next, because they are how organizers make money here:** add-ons (3) and
selling at the door (4). Together they are the difference between a ticketing
tool and the thing a club runs its night on.

**In parallel, whenever the credentials land:** wallet passes (6), push (7).
Neither needs design work — only keys — so they should be picked up the week
they arrive rather than scheduled.

**Then, once there is somebody to integrate with:** webhooks and keys (10), the
embeddable widget (9), campaigns (11). This is the block that makes larger
promoters possible.

**Deliberately later:** resale (5) until we decide whether it is our argument,
instalments (14) until a festival needs it, reserved seating (15) until a
seated venue does, translation (18) while both markets read English.

**On a date, not a backlog:** data requests (16). Compliance work that waits
for a request is compliance work done badly.

---

## Two operational gaps that are not features

Neither is on any competitor's page, and both would be felt immediately.

**Nothing runs the queue or the scheduler** unless a deployment says so. Every
email in this system is queued, and three scheduled commands matter — one of
them releases stock from abandoned baskets. Both are now in
[the API's README](../apps/api/README.md); they need to be in the deployment
before launch, not at it.

**There is no staging environment in this repository.** Every address is
supplied at run time precisely so one build can serve staging and production —
`npm run check` holds that true — but nothing here describes where staging is.
