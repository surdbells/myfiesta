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
| Mobile   | Angular 21 + Ionic 9 in a Capacitor 8 shell — one app, three modes |
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

**An organizer's page is at `/o/{slug}`, not at a slug of its own.** Event slugs
are routed at the root and are imported verbatim from the previous platform, so
an organizer named after one of their own nights would shadow it — and the
namespace organizers name events in should not start losing words to
organization names. The app uses the same address, so a link shared out of it
and a link opened in it are the same link.

**There is no follow button on the public site.** Following needs an account,
and the site has none: guest checkout is the primary path, sign-in there means
the organizer console, and even the heart on an event card is a bookmark in
that browser rather than a synced list. A button asking somebody to sign in to
something that does not exist would be worse than its absence, so following
lives in the app, where there is an account to hang it on. If buyer accounts
ever reach the site, this is the first thing that changes.

**An organization that has never published has no public page.** Registering is
not publishing. A page per registered account is a thin page for a crawler to
index and a way for anybody to ask which names are taken, so the API answers
404 — the same answer it gives for a name that does not exist — and the sitemap
applies the same rule rather than listing addresses that 404.

**The mobile app ships under the existing bundle ID, `myfiesta.os.ca`**, as an
update to the current store listings. A new listing would mean every user has to
find and install a different app by hand, and the forced-update gate would have
nothing to push them onto.

**Attendee mode launches thin** — browse, tickets, notifications, with Buy
handing off to the web checkout. Event tickets are physical goods, so in-app
purchase rules do not apply. This keeps two payment gateways and two mobile
SDKs out of the same sprint; native payment sheets are a follow-up.

**The phone app is Angular in a Capacitor shell, not Flutter.** The spike was
Flutter and it worked; what it cost was a second language, a second set of
idioms, and a second copy of every rule — the money formatting, the event-time
zone handling, the token scopes, the design tokens, each written once in
TypeScript and again in Dart, kept in step by a generator and by hand. For a
team this size that is the largest recurring tax in the repository, and it buys
nothing a WebView cannot do at a door: scanning, a ticket QR, a guest list.

So the phone app is the same Angular the web apps are, sharing their
helpers, their tokens and their release discipline, wrapped by Capacitor for
the native pieces that actually matter — storage, haptics, the status bar, the
back button, and the store listing.

**Ionic supplies structure, not looks.** Its router outlet, page transitions
and platform handling are worth having; its components are not, because an app
built from them looks like every other Ionic app. Every control a person
touches — buttons, fields, bottom sheets, the searchable select — is this
product's own, drawn from the same tokens the web reads, so a phone screenshot
and a browser screenshot are recognisably one product. The rule is worth
stating because the cheap path is always to reach for the framework's
component and override it until it nearly matches.

**A door reads a ticket with the camera, and can always be typed into.** ML Kit
natively, because a queue moves at the speed of its worst scan and a WebView
decoder is the worst scan; `BarcodeDetector` in a browser, which is for
development rather than a promise, since Safari has none. The code box stays on
screen under both — a cracked lens, a flat battery and a screen that will not
brighten all end there, and that is not a moment to be hunting for a fallback.
The same code is read thirty times a second, so the door ignores a repeat for
four seconds: one guest, one admission, rather than a wall of "already used".

**Reminders are scheduled on the phone, not pushed.** Three hours before a
night somebody holds a ticket for. No certificates to manage, no device token
to keep in sync, and nothing needed at the moment it fires — somebody on a bus
with one bar still gets told. The schedule is rebuilt from the tickets on every
load rather than added to, because a transferred ticket, a cancelled night and
a moved start time all have to be able to remove one.

**Saving and following are private, and have no counts.** Saving is for later,
not applause: a save count on an event page tells everyone how quiet a night
is. An organizer is told how many follow them and never who — a follower list
is a mailing list built without asking, and nobody follows a party expecting to
end up on one.

**Announcements go out by email, and once.** Publishing an event tells the
organizer's followers. `announced_at` is claimed before the sending starts, so
unpublishing to fix a typo and publishing again is not a second email, and a
worker that dies halfway leaves some people untold rather than telling everyone
twice. Two ways out, because they mean different things: stop following this
organizer, and the blanket no to mail like this from anyone. Both work without
an account, and both are a page with a button — a mail scanner following a link
must not be able to unsubscribe somebody.

**An account's abilities come from what the account is.** Everybody is an
attendee; organizer comes from being staff somewhere. Signing up on the phone
makes an attendee account with no organization and no organizer ability, and
asking that person to name an events page would be a question about a business
they do not have. Which kind is being made is declared by the client, not
inferred from a missing field, so a console sign-up that loses its organization
field fails loudly instead of quietly making the wrong kind of account.

**The tick is shown only under the name that was checked.** Verification means
somebody read an organizer's identity documents and agreed they are who they
say. Once organizers could edit their own display name, a verified account
could rename itself to a household name and keep the tick, which is the whole
value of the tick handed away. So the name is recorded at the moment of
verification and the public claim is suspended — never the verification itself
— until staff agree the new name is still them. A glance, not a re-upload:
making somebody send a passport again because they fixed a typo is how a
verification queue fills with work nobody needed.

## Still open

- **Merchant of record for tax** — determines who remits. An accountant's call,
  wanted before the money phase because the schema reflects the answer.
- **Forced-update threshold** — the share of un-updated installs that gates
  launch. Agree the number in advance, not during launch week.
- **Stripe variance handling** — reconciliation will surface historical sales
  whose recorded amount differs from what was charged, some already settled to
  organizers. A commercial conversation, not a code path.
- **Camera scanning on iPhone** — the ML Kit scanner ships a CocoaPods podspec
  and no `Package.swift`, and the iOS project links its plugins through Swift
  Package Manager. So `cap sync` leaves it out, silently, and camera scanning
  at a door works on Android and not on iPhone. The app says so and offers the
  code box, which is the fallback that has to exist anyway. Answering it means
  moving iOS to CocoaPods — a structural choice with its own cost — or finding
  a scanner that ships SPM. `npm run check` holds the gap visible meanwhile.
- **Push credentials** — FCM and APNs. Blocked on keys, not on code: following
  an organizer is the list a "they announced a night" push would ride on, and
  it already sends by email. Push is an addition to that, not a replacement —
  a mailbox reaches somebody who installed the app once in June.
- **Wallet passes** — an Apple Wallet signing certificate and a Google Wallet
  issuer account. Nothing is built against either yet, deliberately: a pass
  format that cannot be signed cannot be tested, and an untested signing path
  is one that fails on the first real ticket.
