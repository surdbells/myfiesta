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

**A door sale is an ordinary order with three extra columns.** Same stock, same
tickets, same reports, same services — it reserves and fulfils through the
checkout path rather than a second one, so it cannot oversell against an online
buyer reaching the last ticket at the same moment. What it carries that an
online order does not: how it was paid, who took it, and on which door pass.

**The platform charges nothing on money it never touched.** The service charge
is the buyer paying us for a checkout, and at a door there was no checkout.
Charging for it would mean invoicing an organizer for cash we cannot see.

**The ledger records a door sale and then takes it back out.** The sale, its tax
and its discount are written as for any order, so the night's gross still reads
as the night's gross; a `collected` entry then removes the organizer's share,
because they are holding it already. The two cancel, and nobody is settled twice
for one ticket. Omitting the sale entirely was the other option, and it makes
the orders list and the ledger disagree about what the night took.

**A door sale may have no buyer email; nothing else may.** The person paying
cash in front of you is not going to spell one out, and their ticket is scanned
by the phone that just sold it. The database says so — `channel = 'door' OR
buyer_email IS NOT NULL` — so the guarantee every other part of the system
relies on is unchanged. A ticket with no address must belong to an order, which
is what keeps a nameless ticket from being something anybody can mint out of
nowhere.

**Selling is on the door token.** The person selling walk-ups is the person on
the door, and a sale that needs an owner standing there is a sale that does not
happen. What makes it safe is that the order names who took it and on which
pass, and the audit log carries the same entry: a till with a name on it is the
control that matters for money handled in a doorway.

**Selling needs a connection; scanning does not.** The offline list exists to
admit people who already hold a ticket. Money changing hands and stock leaving
the room cannot be decided by a phone on its own, so the sell sheet says plainly
that it needs signal rather than queueing something it cannot honour.

**An add-on is a line on the order, not a table of its own.** A bottle, a table,
a shirt: sold with a ticket, settled through the same ledger, and admitting
nobody. An order line therefore carries exactly one of a ticket type or an
add-on — the database refuses both and refuses neither — so an order stays one
list of what was bought. The cost of that choice is every query that silently
meant "tickets" when it counted lines, and each of those now says so: sales by
day, sales by code, and a promoter's own figures filter to ticket lines, while
revenue still counts everything, because a bottle is money.

**Admits nobody is the whole distinction.** It is what decides which console
screen something belongs on, whether buying it mints a ticket, and whether it
appears in the list an organizer reads capacity from. A Table of 6 is a ticket
type because six people walk through a door on it; the bottle on that table is
an add-on because nobody does.

**A basket of add-ons and no tickets is refused.** A bottle on its own is a bar
tab, and this is not a bar. The checkout page keeps its steppers dead until a
ticket is chosen rather than letting somebody find that out at the end.

**Discount codes are about the tickets.** "50% off" is something an organizer
says about their night, not about the bar, and a fixed-value code larger than
the tickets it applies to would otherwise spill onto merchandise. A code names
ticket types and discounts ticket lines; add-ons are never eligible.

**Add-on stock is counted from what was paid for.** A ticket type counts the
tickets it has minted, and twenty tables have no rows to count — so paid order
lines are the count, plus the holds of baskets in progress. A fully refunded
order gives its table back by leaving the paid statuses.

**One question model for both halves of the product.** A wedding asks its
guests about dietary requirements when they RSVP; a club night asks its buyers
for the name that goes on each ticket. The question is the same shape either
way — a label, how it is answered, whether it must be, and whether it is asked
once or of each person — so `rsvp_questions` was renamed `event_questions` and
both flows read it. Two tables would have meant two editors, two validators and
two exports, drifting from the first time somebody added a type to one of them.

**Answers outlive the question they answer.** Questions are soft-deleted:
removing one stops the checkout asking it and keeps every answer, and the
export keeps the heading, because a column of dietary requirements with no
label is a column nobody can read. For the same reason a question can be
reworded after somebody has answered — a typo does not invalidate an answer —
and cannot be reshaped: turning a typed question into a choice would leave
every answer already given outside the list of things it was possible to say.

**A question nobody can answer any more does not fail a sale.** An answer to a
question that has since been removed is dropped rather than refused. An
organizer tidying their form while somebody is on the checkout page should not
turn that person's purchase into an error they cannot act on.

**The door sees what was asked of the person in front of it, and nothing
else.** Per-person answers are stamped onto the ticket when it is issued, so a
scan reads them in one query; what the buyer answered for the order — how they
heard about the night — is not a door's business and is not sent. Door staff
still cannot read the guest list: that is a separate ability, and a phone
handed over for one night is not an attendee database.

**Questions are asked of everybody, not per ticket type.** Eventbrite scopes a
question to a tier; we do not, yet. It is the obvious next turn of this screw —
a phone number that only matters for a table — and it is left out deliberately
rather than half-built, because the join it needs touches the checkout, the
console and the export at once.

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

**Other systems hear from us, signed, and never at our own network.** An
organizer's webhook address is somewhere we will post buyers' names to, and an
address is also a way to make our server fetch something. So it must be https,
every address its name resolves to must be public, the connection goes to the
address that was checked rather than to whatever the name answers a second
time, and redirects are not followed — at saving and again at every delivery,
because what a name resolves to can change in between. Each delivery is signed
over its timestamp and exact body, so a receiver can refuse a forgery and a
replay alike.

**A webhook never breaks the thing it is about.** Emitting writes a row inside
the transaction and queues the send for after it commits, so a rolled-back
order never announces itself and a receiver that is down never rolls one back.
Retries come from the schedule, not the queue's own retry machinery: a
delivery then survives a flushed queue and a deploy, and what is outstanding is
a query rather than a guess. Twenty-five failures in a row switches the
address off and says so, rather than sending personal data at a dead host for
ever.

**Keys belong to the organization and can only read.** A person's token can
refund; a key in somebody's accounting script should not be able to. Keys are
shown once, kept as a hash with the last four characters for recognising them,
see only their own organization — an event of anyone else's is a 404, not a
403 — and never reach the console API. Both directions carry the same payload
shape, and neither carries a ticket code: an integration is an export by
another name, and codes have never been in an export. Only an owner can create
either, and creating or removing one is in the audit trail.

**Selling from somebody else's page, without their page touching the card.**
The embedded checkout is our own buying steps in a frame, not a second
checkout: one set of rules about prices, questions and holds, tested once.
Paying happens in a tab of its own on the processor's page — they refuse to be
framed, and a card form inside a frame on a site we do not control is the
shape of every card-skimming attack, so a buyer should not be taught to trust
one. The tab is opened on the buyer's own click, before the order is placed,
because a tab opened when the order comes back is a popup the browser blocks.

**Only the embed may be framed.** Nothing said whether the site could be framed
before, which meant anybody could put the checkout under an invisible layer of
their own. Now every page answers `frame-ancestors 'self'` except `/embed/`,
which any site may frame — the organizer's site is wherever it is, and asking
them to register a domain first is a step most would not take. What the frame
says to the page around it is the height and, when an order is paid, the event
and the number of tickets: nothing the buyer could not see on screen, so it
can go to any origin.

**A campaign chooses a list, never an address.** An organizer picks one of
three lists and the server decides who is on it: followers, people whose
ticket stood to one of their nights in the last two years, and people who gave
an address for this event's basket in the last thirty days and never paid.
The windows are CASL's implied consent — two years after a purchase, six
months after an enquiry — tightened for the basket, because a nudge about May
arriving in September is not a reminder. Every list then loses anybody who
turned marketing off, anybody already holding a ticket to the night being
sold, and anybody this organizer wrote a campaign to in the last seven days.
The list is worked out when the campaign sends, not when it is written, so
somebody who unsubscribed on Tuesday is not written to on Friday.

**What a campaign did is counted from its link, not from a pixel.** Every
campaign has a ref on its ticket button, and the orders that carry it are what
it sold. That is the number an organizer wants; opens are a guess the mail
client makes up, and a tracking pixel tells us something about the reader
they did not choose to tell.

**Two kinds of unsubscribe.** Reminders are about a night somebody bought for;
marketing is everything they did not ask for individually — announcements,
campaigns. The link in each email says which it stops, and the button a mail
client draws from List-Unsubscribe stops the same thing. Until campaigns, every
link stopped reminders, which meant pressing unsubscribe on an announcement
lost somebody the reminder for their ticket and kept the announcements coming.

**How many looked is a count, not a log.** An organizer needs to know whether
a quiet night is a traffic problem or a pricing one, which takes one number:
views. So that is all that is kept — one row per event per day with two
counters, no visitor id, no cookie, no address, nothing to join against
anything. It is sent from the browser once a session, so a crawler reading the
server-rendered page is not a person who looked and a buyer going back and
forth to the checkout is one. It is honest about being approximate, and it is
not a number anybody is paid on; promoter links are counted from orders, which
are facts.

**The benchmark is their own last night.** Not an industry average, which we
cannot know and they cannot act on. The comparison lines both nights up by
days before the doors, so "by a week out last time we had sold 140" is a
sentence the screen can say in March about a night in June.

**A privacy request is proved by reaching the address, not by signing in.**
Guest checkout means most of the people with a right to ask have no account,
so an erasure behind a login would be closed to exactly the people who most
often want one. The link in the email is the whole credential, a GET only
shows what will happen, and the POST is what acts — mail scanners follow links
in messages, and an erasure on a prefetch would erase somebody who never
clicked. An address we hold nothing about is never written to, so the form
cannot be used to send mail to a stranger, and the answer on screen is the same
either way so it cannot be used to ask who has an account.

**Erasure says what it actually did.** It is not a DELETE across the schema and
claiming otherwise would not survive being looked at. Rows that owe nobody
anything go; orders, tickets and audit entries stay with the person taken out
of them, because both laws permit keeping financial records and the ledger's
append-only trigger would refuse to give them up anyway; and two things are
kept on purpose — the suppression list, because forgetting somebody's "stop
emailing me" starts the emails again, and the security access log, which
exists to catch misuse. The person is shown the list, table by table.

**The record of a privacy request outlives the data it was about.** Erasing
the request along with the person would leave nothing to show for it but their
absence, which is the opposite of what a regulator asks for.

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
