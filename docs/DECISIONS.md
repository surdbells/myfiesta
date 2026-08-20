# Decisions

Settled decisions and their consequences. The full plan — feature inventory of
the previous platform, findings, phases, and migration — lives in the rebuild
dossier; this file is the short in-repo record of what was chosen and why, so
the reasoning survives without opening it.

## Approach

**The previous codebase is abandoned.** No code carries over. It is not
imported here, not as a base and not as a reference. What was extracted from it
is the feature inventory, which is the specification for this build.

**All data migrates.** Organizer accounts, brands, KYC and payout records,
events, ticket types, orders, issued tickets, settlements, and reference data.
Two things transfer verbatim and must not be regenerated:

- **Ticket codes** — codes already in guests' inboxes have to scan.
- **Event slugs** — routed at the root, `myfiesta.ca/{slug}`. Those links are in
  shared messages, bios, and printed QR codes, and cannot be edited.

**Stripe is the source of truth for historical money.** The previous checkout
took the amount from the client, so recorded sale figures cannot be trusted on
their own. Every historical charge is reconciled against Stripe before the
ledger is backfilled.

## Stack

| Area     | Choice                                              |
| -------- | --------------------------------------------------- |
| API      | Laravel 13 + Filament (admin console)               |
| Database | PostgreSQL 17                                       |
| Cache    | Redis — queues, cache, rate limiting                |
| Storage  | Flysystem, local disk now, Cloudflare R2 at scale   |
| Mail     | ZeptoMail via its HTTP API, through a custom transport |
| Errors   | Sentry                                              |
| Web      | Angular 21 — public site (SSR) and organizer console |
| Mobile   | Flutter 3.47 — one app, three modes                 |
| Hosting  | Laravel Forge on a VPS                              |

The plan was written against Laravel 11 and Angular 20; the current releases at
scaffolding time were 13 and 21, and those are what the repository uses.

**Forge is the only control plane on the server.** Pairing it with a second
panel means two tools overwriting the same nginx vhosts, PHP pools, and
certificates.

**PHP-FPM needs `memory_limit` at 256M or above** on the pool that serves
uploads. A 2400-pixel-edge photograph is roughly 23MB as an uncompressed GD
bitmap and resizing holds two at once, which does not fit in PHP's 128M default
alongside a booted framework. The symptom is a 500 on the first banner an
organizer uploads, and nothing before it. `phpunit.xml` sets 512M for the same
reason, so the image tests exercise the real path rather than a smaller one.

**Postgres was chosen for what it absorbs**, not just as a swap: full-text
search removes the need for a separate search service, `numeric` gives a money
type that does not drift between cents and dollars, and row-level locking makes
inventory holds real. Migration from MySQL is cross-engine and belongs in the
rehearsal estimate.

## Money

**Two rails, one contract.** A `PaymentGateway` interface with Stripe and
Paystack implementations. Cashier lives inside the Stripe one; its abstractions
do not leak into the domain. Routing is a data-driven rule — NGN to Paystack,
everything else to Stripe.

**Signed webhooks are the only thing that mints tickets**, on both rails. The
browser's return URL is cosmetic. The previous platform fulfilled orders from a
client-supplied payload and had no webhook at all.

**Currency is scoped per event.** Money is `(amount, currency)` everywhere.

**Tax is keyed on jurisdiction**, with currency as the lookup default —
Canadian rates vary by province while Nigerian VAT is flat, so currency alone
would be wrong for most of Canada. Rates are administered in Filament, and each
records whether it is inclusive or added at checkout.

**Order of operations is fixed**: discount applies to the subtotal, tax
calculates on the discounted amount, platform commission takes the net. The
discount is the organizer's cost, not one the platform shares.

**Settlement stays manual** for now, per-currency and per-rail. Because the data
stays, banking and KYC columns are encrypted at rest, Filament access is
role-restricted, and access is logged. Stripe Connect and Paystack split
payments remain available later — that is why the gateway sits behind an
interface.

## Access

**Organizations own resources, not users.** People hold roles within an
organization. Every migrated organizer becomes an organization of one, so adding
a second person later is an invitation rather than a data migration.

**Token scope is the access boundary.** Sanctum abilities: `attendee`,
`organizer`, and event-bound `door:{event_id}`. A door token is rejected
outright by sales, guest-list, payout, and profile endpoints.

This matters more than usual here. The mobile app compiles all three modes into
one bundle, so the client cannot hold the boundary — a door-staff phone has the
organizer UI in it, hidden. If the API is permissive, that phone becomes an
organizer console.

**Attendee accounts are optional; guest checkout is the primary path.** Buying
requires no app and no account. Ownership is not optional, though — every ticket
belongs to an identity, claimed or unclaimed, which is what makes authenticated
transfer possible and is the precondition for controlled resale. Historical
buyers migrate as unclaimed records keyed on their email.

## Clients

**The web is the sales channel, not a brochure.** Organizers sell through
shareable links, so server rendering is a functional requirement — a link that
unfurls without a title, image, or price reads as broken. Links carry `?ref=`
for promoter attribution, captured on landing and persisted to the order.

**One codes namespace.** A code may discount, attribute a promoter, or both — in
nightlife the discount code is frequently the attribution mechanism.

**The mobile app ships under the existing bundle ID, `myfiesta.os.ca`**, as an
update to the current store listings. A new listing would mean every user has to
find and install a different app by hand, and the forced-update gate would have
nothing to push them onto.

**Attendee mode launches thin** — browse, tickets, notifications, with Buy
handing off to the web checkout. Event tickets are physical goods, so in-app
purchase rules do not apply. This keeps two payment gateways, two mobile SDKs,
and a first Flutter project out of the same sprint; native payment sheets are a
follow-up.

## Still open

- **Merchant of record for tax** — determines who remits. An accountant's call,
  wanted before the money phase because the schema reflects the answer.
- **Forced-update threshold** — the share of un-updated installs that gates
  launch. Agree the number in advance, not during launch week.
- **Stripe variance handling** — reconciliation will surface historical sales
  whose recorded amount differs from what was charged, some already settled to
  organizers. A commercial conversation, not a code path.
