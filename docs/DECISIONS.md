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

**What is charged is a setting; what was charged is on the order.** The
service charge per currency, the seller of record, tax on the service charge,
Quebec's QST and the numbers on receipts are platform settings an
administrator changes in the admin, over defaults from `.env`, each change
audited with what it replaced. Every order keeps its tax lines (each tax with
its own rate — GST and QST side by side in Quebec) and a copy of the settings
it was priced under, so a receipt never changes after the settings do. Tax on
the service charge is kept inside `service_charge_amount`, with
`service_charge_tax_amount` saying how much of it is tax, rather than added to
`tax_amount`: the ticket's tax is what the organizer's ledger holds back and a
refund returns in proportion, and tax on the platform's own fee is neither
theirs nor part of the ticket. That keeps total = net revenue + tax + service
charge, and every ledger sum, exactly as they were. A refund records its own
share of that tax (`refunds.service_charge_tax_amount`), and the admin's
reports count it as tax rather than as the platform's revenue. In Nigeria the service
charge includes its VAT the way prices do, so the buyer pays the same either
way. Launch tax rates are installed by a migration rather than only a seeder;
a rate that has applied to a sale can only be superseded. A place has one rate
on any day — the database refuses overlapping dates — and a rate never starts
before today, so a scheduled replacement moves together with the rate it
replaces rather than leaving a gap or an overlap.

**Settlement stays manual** for now, per-currency and per-rail. Because the data
stays, banking and KYC columns are encrypted at rest, Filament access is
role-restricted, and access is logged. Stripe Connect and Paystack split
payments remain available later — that is why the gateway sits behind an
interface.

**Nobody decides money for their own organization.** Staff can also run
events. A member of staff on an organization's team cannot pay or refuse its
payout requests, record a settlement or a repayment for it, or open or verify
its payout details: each of those is somebody else checking, and the person
who asked for the money is never the one who pays it. The services refuse it
(`OwnOrganization`), and the admin's buttons are shown switched off with the
reason rather than refusing after the form is filled in. The same rule as an
event's review, where the member of staff who sent it for review does not
approve it.

**An overdraft is an advance, and it is on the record.** Paying a payout
request for more than the organization is owed is allowed in one place — staff
paying the request — for administrators and finance only, after a second
question that shows what is owed, what was asked, what is being paid and the
difference, with a written reason. The request keeps `overdraft_amount`,
`overdraft_reason`, `approved_by` and `approved_at`, and the decision gets its
own audit entry. The ledger records the payout exactly as any other, so the
balance in that currency goes below zero by the advance and nothing else; no
recovery entries are written, because the next sales are credits to a balance
that is below zero and pay it back on their own. Where it stands — advanced on
a day, recovered from sales since, repaid, added by refunds, outstanding — is
worked out from the advance and the balance every time it is read, so it cannot
disagree with the ledger. Nothing more can be asked for until the balance is
above zero. The balance is read once when paying, and that one figure decides
both the advance on the request and the settlement's type, because sales and
refunds do not wait for the organization's lock. The advance's reason is not
the reason for paying to unverified details; that still needs its own note.
An advance paid before requests kept these figures is told from its
settlement, never as refunds. Money sent back outside the platform is a
`repayment`: its own append-only record with the bank reference and a reason,
one ledger credit of its own type, never more than is outstanding, and one
reference per organization and currency, so one transfer is not credited
twice. An organization that owes money
cannot be closed, and its only owner cannot be erased. Currencies never mix: a
naira advance leaves dollars payable as usual. Dashboards count what is owed to
organizers and what is overdrawn side by side, never netted.

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

**Staff can open an organization's console as the organization, for an hour,
with less than an owner.** Administrators and support start it from the admin
panel with a written reason; finance cannot, because its work is done in the
admin panel and it already holds the one role that reads bank details. The
console receives a one-minute, single-use code in the link's fragment and
trades it for a token held only in that tab's sessionStorage, so no token is
ever in a URL and nobody's own sign-in, the staff member's included, is
touched. The token belongs to the staff member, so every audit entry names
them, marked `impersonating`. It acts as an owner without the owner's
irreversible or identity-changing powers — where payouts go and asking for
them, the team, integrations, the brand, cancelling or deleting events,
refunds (made from the admin panel instead), the door — which are removed from
the permission list every policy asks, and refused by route as well with a
message that says why, along with exports and anything that emails people in
the organization's name (messages, campaigns, the waitlist, emailed tickets,
and an event's first publish, which tells its followers). The payouts
statement follows the staff member's own role: support does not see it here
because the admin panel does not show it to support. Every request that could
change something, and every refusal, is written to the audit trail as
`impersonation.request` — route, record ids and status, never the body — so a
change is attributable even where the endpoint keeps no entry of its own.
Nothing extends a session; ending it, losing the staff role, or anything that
revokes the staff member's sign-ins (signed out everywhere, deactivated, a new
password) ends it at once, including a link not yet opened.

**Suspending an organization stops it selling and being paid, and nothing
else.** Administrators suspend from the organization's page in the admin
panel, with a reason kept on the record; staff choose whether the
organization is shown it. Every event on sale goes back to a draft and is
marked as taken off by the suspension — not a night already over by its own
listing, which sells nothing and stays among the organization's past events
with its sales; checkout, door sales, handing tickets
back for resale, telling the waitlist, campaigns and publishing are refused
(the API answers 403 with one plain sentence and where to write); new payout
requests are refused, waiting ones are held — not rejected — and nothing can
be settled. A payment that lands afterwards for an order begun before the
suspension is a sale all the same, so it is refunded in full and issues
nothing, like one for a night that was called off; the buyer is told the
organizer is not selling on myFiesta, never that it is suspended. What is
deliberately left alone: everybody who already has a ticket keeps it and the
door still admits them (scanning, the offline list, door staff opening the
night they work), refunds can still be made, ticket holders can still be
written to and are still sent their reminders, and guest-list tickets can
still be issued (no money moves). An event's campaign that falls due while
the event is held is cancelled, as for any event off sale, and is the
organizer's to schedule again. Lifting it puts back exactly what it took: the
marked events go back on sale unless they have started or were cancelled,
deleted or taken down meanwhile — not re-checked for a ticket type on sale,
since they were on sale as they stood — or changed since they came off (the
mark keeps a fingerprint of what buyers saw; an edit made during the
suspension goes through review, since lifting it is a decision about the
organization, not a look at its listings, and approves nothing) — and the
rest stay drafts; the owners' email names both lists. A marked night that
ended while the suspension lasted, unchanged, goes back among the past events
instead: a past event left a draft could never be put back, since sending one
for review needs a date to come. An event the organizer takes off sale themselves
during the suspension loses its mark and stays a draft. One taken down during
it counts as on sale before the takedown, so lifting both, in either order,
puts it back. Held requests go back to waiting in their place. Suspending
twice changes nothing the first did. Each step is one transaction, written to the audit trail on the organization, on each event
and on each request, and the owners are emailed both ways. The console shows
a banner on every screen for as long as it lasts.

**Every event is looked at before it goes on sale.** An event is a draft
until the organizer sends it for review (the permission and proved address
publishing needed, and an event that is ready — a description, a date to
come, somewhere it is, a ticket on sale — with every missing thing said at
once). While it waits it is `in_review` and frozen: the API refuses (423,
"This event is being reviewed. Withdraw it to make changes.") every write
that changes what a buyer sees or pays — the event's fields, ticket types,
add-ons, pictures, questions, the series and its dates, and discount codes
and code batches — so what staff approve is what goes on sale. Left open,
because they do not change the listing: reminders, door passes, the team,
guest-list tickets, messages to people already holding tickets, refunds,
copying the event, and everything read-only. Administrators and support
approve it (on sale, and its followers hear about it — the first time it is
on sale, never when it is sent) or reject it with a reason of at least
twenty characters, sent to the organizer word for word and shown on the
event in the console until they send it again; the organizer can also take
it back to change something. Finance sees the queue and the review page and
decides nothing. Each step is one transaction on the locked event, kept in
the event's review history and the audit trail, emailed (queued, replies to
support) to everybody at the organization who can publish — and, for a new
submission, to the staff who review, or to `EVENT_REVIEW_NOTIFY` when set —
and pressing it twice does nothing the second time. A decision is made on
what the reviewer saw: the review page carries the fingerprint of the event
as it opened, and approving or rejecting an event that changed since (taken
back, edited and sent again while the page was open) is refused until they
reload it. Whoever sent an event for review does not approve it — staff
acting as an organization can prepare and send an event that tells no
followers, and somebody else at myFiesta decides it. An approval keeps a
fingerprint of what a buyer saw (EventSnapshot; a poster's caption, which
buyers are not shown, is not part of it). Nothing reaches on sale
without one, except: an event taken off sale by its organizer and put back
unchanged since its approval; the next date of an approved series when it is
the approved night on another date (a date missing the gallery, add-ons or
questions the copy does not carry is compared without them — it offers less,
not something else); and staff lifting a takedown, which counts as approving
the event as it stands. Neither shortcut outlasts a rejection: once staff
send an event back, only another approval puts it on sale, even restored to
what was approved before (which may never have been looked at by a person,
for an event on sale before reviews began). Going straight back is held only
to its dates, not to the checks the listing passed when it was approved — an
event on sale with no description is not told it can go back and then
refused. Events on sale when this shipped,
and events imported on sale, were marked approved as they stood. The lock on
codes is the event's own: a code made for one event is changed only through
that event (404 through any other), and a code for all of the organization's
events belongs to no one event, so one event's review does not freeze it.
**Edits to
an event already on sale need no review**: organizers fix typos and move
prices on live nights, and a queue for that would stop the night selling.
They are recorded in the audit trail (the event's fields, its pictures, a
ticket added, closed or removed, an add-on closed or removed and a question
reworded, beside the price changes and new add-ons and questions that
already were), and they do count as changes since the
approval, so taking the event off sale and putting it back sends it through
review. An event waiting when its organization is suspended stays waiting;
approved meanwhile, it goes on sale when the suspension is lifted, as it was
approved, and its followers hear about it then (never one that had been on
sale before the suspension). Sending
for review is refused while suspended, and to staff acting as the
organization when the approval would tell its followers.

**Signing up and buying record that the terms were accepted, and which
ones.** The terms, the privacy policy and the refund policy are agreed to
together, by an unticked box on every sign-up (console and phone) and on the
checkout; the API refuses either without it (422, one plain sentence). What is
kept is the version — `config/terms.php`, bumped in the same change as the
words on the public site — and the moment: on the order, always, so an order
stays explainable against the words it was placed under; on the account, for
somebody who signed up or joined by invitation. A sign-up carries its
agreement until its link is opened, and the account gets the agreement of
whichever sign-up the password opened — never a stranger's. The checkout reads
no sign-in — not the public site's, which has none, and not a token an app
might send — so it asks every buyer every time, places no order for an account
and never writes to one behind a typed address. A person whose account has
not agreed to the version in force is not held back from anything else. A
checkout that knows its buyer would ask an account once per version and keep
the agreement on it too (`Terms` is ready for that), but it has to place the
order for that account in the same change. Door sales are not asked: the
person paying is in front of the organizer's staff, gives nothing, has no
screen of ours to read on, and the organizer selling agreed when they signed
up. The agreement itself is the version and the moment; the address and
browser an online order came from are kept on the order for a different
reason — answering a disputed payment — and the privacy page says so (see
"A chargeback is answered from records").

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

**"Almost sold out" is checkout's count, said as a state.** One service
(`App\Services\Discovery\Availability`) decides it for every client, from the
count checkout takes before it reserves (`Stock`): tickets that still admit
somebody plus baskets that have not run out. Counted any other way, a badge
says "Only 2 left" to somebody checkout then turns away. A tier is almost sold
out once something has sold and what is left is at or under the larger of a
floor and a share of its capacity (5 places and 10% unless staff change them);
a night is sold out when every tier a stranger can see has gone, and almost
sold out when its cheapest tier still selling is — the one its "From" price
names — or when little is left across all of them. A night nothing can be
bought for that did not sell out — online sales stopped at the door, or the
organizer closed them — is `closed`, said "Sales closed", with no waitlist and
no place on the front page's sold-out shelf: calling it sold out put nights
that sold two tickets on that shelf. A night whose early bird ended with places
left and whose general tier then sold out reads "Sales closed" too; that is
true, and "Sold out" would be a claim about places that never sold. A tier
whose sales ended is `closed` on the event page and in the quote alike. The
quote says nothing of a hidden tier unless the buyer's code opens it, as
checkout answers one without its code exactly as it answers an id that does
not exist. Public payloads carry the
state and an exact number only at or under a second setting (10): "Only 4
left" helps somebody decide, and "312 left" is an organizer's sales, readable
by a rival refreshing the page. The organizer's own screens keep their full
counts. The rule is written twice, in PHP for the badges on what a page has
already loaded and in SQL for the filters and the front page's shelves, and
the tests run both over the same nights. Lists are kept for a minute; the
event page, the ticket page and the quote are never kept, and the quote
reports each tier again so a ticket page takes one that sold out while
somebody chose out of their basket before checkout refuses it.

**Today, this weekend and this month are the event's own.** A 10pm Friday
party in Lagos is a Friday night to the people going, whoever is reading and
whatever the server's clock says. Every window is worked out in the event's
own timezone, and a night counts as on until it ends, so a festival that
opened at noon is still "today" at six.

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

**A scan whose answer was lost is one scan, and the server compares it.** A
door scans online first and queues the scan under the same id only when no
answer comes back, so a request that timed out but arrived is not counted
twice. When that queued copy syncs, the server keeps the door's decision beside
its own and, where they differ, records and returns the difference like any
other offline conflict: a guest let in on a ticket it had refused is
`admitted_invalid`, and only people beyond what it already counted are added.
A refusal the door made because the same ticket was already let in offline is
not reported as somebody owed entry — that is a copied ticket working as it
should. Where the door turned away a guest the server had let in, the server
keeps its admission and says so rather than undoing a check-in that has
already been announced to webhooks.

**A ticket for more than one is never let in whole on a scan that did not say
how many.** A scan with no number used to admit everyone the ticket had left,
so the first of a table holding up its ticket counted the whole table in, and
the rest could walk in later, unscanned, past a count that already had them.
Now, with more than one place left, the server answers `choose_party` — the
ticket's type and holder, how many it admits, how many are in and how many are
still to come — and admits nobody. Nothing is recorded for it: a question is
neither an admission nor a refusal, and would read as a refusal in every count
of them. For the same reason it carries none of the guest's checkout answers:
with no row behind it, a door could read them for any table as often as it
liked, off the organizer's record. Both doors pause the camera and ask, "All N
here" or a smaller number (a stepper past six), and scan again with the answer.
That second scan is sent at once rather than left to the camera, which would
take the ticket it read seconds ago as a repeat, and under the id of the scan
that asked. The question left that id free; and when the door asked from its
own list because the server took too long, the server may have let that very
scan in on the last place, so the answer has to be recognised as the same scan
rather than refused as a second person on a spent ticket. A number put in "How
many" first is used without asking, and the last place on a ticket — every
single ticket — needs no question. The same rule decides with no signal, from
the saved list, which already carried how many each ticket admits and how many
are in.

**A sync does not ask.** A queued scan with no number keeps the meaning it had
when the door acted on it: everyone that phone's list had left. Phones from
before the question stay in use until their owners update, and on those the
people are already inside; asking the server to judge them afresh would leave
their places open for somebody else. Doors that ask never queue the question,
and queue the number that went in on anything for more than one, so a null
from them only ever meets a single place. An older door scanning online gets
the question as a refusal it cannot show as buttons, so its words say what to
do: put the number in "How many" and scan again.

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
on Android, because a queue moves at the speed of its worst scan and a native
decoder is quicker in the dark and at an angle than one in a WebView. On iPhone,
and in any browser, the camera runs inside the page and a `BarcodeDetector`
reads it — the engine's own where it has one, ZXing compiled to WebAssembly
where it does not, which includes every Safari. The code box stays on screen
under all of them — a cracked lens, a flat battery and a screen that will not
brighten all end there, and that is not a moment to be hunting for a fallback.
The same code is read many times a second, so the door ignores a repeat until
it has been out of view for four seconds — counted from the last sighting and
held while a scan is still waiting for its answer: one guest, one admission,
rather than a wall of "already used", and never a second send of a group
ticket with its party size cleared. The answer to "how many are here" is the
door's own scan, not the camera's, and holds the ticket in view the same way,
so the rest of a table is not asked about while the first of them still has
the ticket up.

**On iPhone the door scans inside the WebView, not with ML Kit.** The ML Kit
plugin ships a CocoaPods podspec and no `Package.swift`, and the iOS project
links its plugins through Swift Package Manager, so `cap sync` leaves it out.
Linking it means moving iOS to CocoaPods — a structural change for one plugin,
and one that cannot be built or checked without a Mac. Reading in the page
needs neither: `getUserMedia` for the rear camera, and the `barcode-detector`
ponyfill standing in for the detector Safari lacks. Its `.wasm` is part of the
app's own build rather than fetched from the library's CDN, because the door
is where the signal goes. Frames are read five times a second at most, which
is more than a queue needs and less than makes an older phone hot. Which reader
a phone gets is decided by whether ML Kit is actually linked, not by what the
platform is called, so linking it on iOS one day moves iPhones back to it
without a code change. `npm run check` still lists the unlinked plugin, as a
gap that is covered rather than one that is open.

**The console's door reads with the same code, in any browser.** The camera
inside the page lives in `packages/door`, and both apps use it: an iPhone at a
door is the same Safari whichever app it opened, so there is one reader to get
right rather than two. The console ships the `.wasm` from its own origin as the
phone does, and fetches it when the door screen opens rather than when the
camera first starts — which may be after the signal has gone.

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

**A chargeback is not a refund, and is never counted as one.** A refund is the
organizer deciding to give money back; a chargeback is a bank taking it. They
land in different places on purpose: a refund has its own record and its own
ledger type, a lost dispute writes a `chargeback` entry, and the order's status
is left alone so nothing downstream can quietly add the two together. An
organizer who cannot tell them apart cannot tell whether their refund policy
is working or their buyers are being defrauded.

**A dispute does not void a ticket; losing one does.** Plenty are withdrawn or
decided for the organizer, and turning somebody away at a door on an unproven
claim is worse than the money being at risk for a fortnight. Once it is lost,
the ticket stops working — a charged-back ticket that still opens a door is
the whole of ticket fraud in one step, with the organizer paying for the
drinks as well.

**The order keeps the payment's own identifier, not just the checkout
session's.** They are different things at both processors, and everything that
happens after the payment — a dispute, a refund — is named by the second one.
Without it a dispute arrives about a payment nothing here has heard of, and a
refund is sent to Stripe with a session id where a payment intent belongs.

**Fraud signals are facts, not a score.** "This address won a chargeback
against you in June" is something an organizer can check and act on. A number
out of a hundred is something they refuse a stranger over, built from
behaviour we would have to follow people around to collect.

**A chargeback is answered from records, not reconstructed.** On the old
platform buyers disputed tickets with their bank after the night had passed,
and every one was lost: nothing had been kept that a bank would accept. So the
facts are written as they happen, from sources a bank trusts, into tables
nothing can edit, and a dispute months later is answered by reading them:

- the order keeps the address it came from and the browser (online only —
  a door sale's request is the organizer's phone);
- the processor's own record of the payment is fetched once it lands, never
  during the notice that issues the tickets, and fixed once kept — for Stripe,
  whether the card's bank checked it was the cardholder (3D Secure), Radar's
  outcome, the CVC and postcode checks, and the card by brand, last four and
  fingerprint; for Paystack, the channel, the card or bank by brand and last
  four, and the address it saw. Never a card number, and nothing that could
  charge the card again;
- the ticket history (`ticket_activity`): issued, each email with the mail
  provider's own id, each opening of the ticket link, the app drawing the QR
  and the calendar file, each transfer by reference. The door is not copied
  into it — `ticket_scans` is the door's record. The page that waits for the
  payment is not in it: it shows no ticket, needs only the reference, and is
  asked by the site's own server as it draws the page, so writing it down
  would tell a bank the tickets were opened when they were not. A ticket
  passed on is written as shown in its new holder's app, without their
  address: the dispute is the buyer's, and nothing about the friend is the
  bank's business;
- once the door has closed and the offline phones have caught up, the night
  itself, with the listing as it stood and the door's counts, so an organizer
  editing the event afterwards changes nothing a bank is shown — the evidence
  describes the night from this record once it exists, and from the listing
  only before;
- the refund policy each terms version showed, in `resources/legal`, because
  a dispute is judged on what the buyer was shown and the site only shows
  today's words.

It is kept proportionate on purpose. There is still no device fingerprinting
and no location: an address is what every request carries anyway, kept as the
application believes it (`TRUSTED_PROXIES`) and read by nothing but people
answering a bank — never by the fraud signals. Everything about a person here
goes 18 months after the night (`disputes:prune-evidence`), which is past the
longest window any card network gives for a dispute, and not before one still
open is settled — a row written after the night goes with the rest rather
than its own 18 months later, and nothing more is written about a night past
the window. The note that a night took place names nobody and stays.

**The statement names the night.** "I don't recognise this charge" is usually
true of a line reading MYFIESTA and nothing else, six weeks on. Each Stripe
charge carries the night's name after the prefix, in the letters, digits and
spaces Stripe allows, cut at a word to fit its 22 characters. Paystack takes
no suffix per payment; its statement line is set once for the business.

**A chargeback is answered by a person, from an answer already written.**
When a processor opens a dispute, the platform asks it about the dispute,
writes the answer from the records above — field by field in the processor's
own terms, with a receipt, the delivery and entry record, the refund policy
as the buyer's version said it, and the emails we sent them, as PDFs — and
tells Admin and Finance. It never sends anything itself. Some disputes are
fair (a cancelled night nobody refunded, a second charge for one order), and
contesting those loses anyway, with a fee on top; so the page sets out what
suggests the buyer is right above the answer, and a person sends it or
accepts. Every sentence is built from a record and says only what the record
says: a missing record leaves its sentence out and its line on the checklist
unticked, rather than something that sounds right. Visa's Compelling
Evidence 3.0 is claimed only when Stripe says a dispute could qualify and our
records establish it — two earlier undisputed payments on the same card
matching on account and internet address. Today that is never: the checkout
reads no sign-in, so no order records the account it was placed on, and
there is no device fingerprinting to match on instead — nor will there be one
to win a chargeback. The account a buyer's tickets are kept in is not offered
in its place; it is made from the address typed at checkout, and calling it
the account the order was placed on would claim a sign-in that never
happened. The page says so plainly. A checkout that records the signed-in
account would make it work with no change to the evidence.

What that keeps, and for how long: on the dispute, which is a record kept for
good, only the processor's status and reason code and when and how it was
answered and by whom. The answer itself — the processor's account, the words,
what was sent — and the PDFs that went with it (on the private disk, never a
public one) name the buyer, so they go with the rest of the evidence 18 months
after the night once the dispute has closed. Paystack sends the card's first
six digits with every dispute; they are never kept. The audit trail records
who saved, sent or accepted, when, which fields and files went and each
file's checksum and the processor's id for it — not the words or the files,
because the trail outlives both. Admin and Finance answer; Support reads, the
same split as refunds. The organizer hears through the `order.disputed`
webhook, as before; nothing about it is sent to the buyer.

**A text is for the two things somebody paid for, and nothing else.** The
ticket, and the reminder on the day. Marketing by text needs consent neither
market lets us assume from a purchase, so the campaign machinery is email and
this is not part of it. Texting also costs where email does not, which is why
`SMS_COUNTRIES` exists: in Canada the email is read, and paying per head to
repeat it is a cost with no argument behind it.

**STOP is honoured for everything, including the ticket.** Making our most
important message the exception is how a suppression list stops meaning
anything, and the tickets are in the buyer's inbox regardless. The number
survives an erasure for the same reason the email suppression list does.

**A number that could belong to two countries is not texted.** Guessing a
country code would eventually send a stranger somebody's ticket link, which is
worse than the text not arriving.

**A returned ticket is stock again, not a listing.** The whole of resale here
is: the ticket stops working, its place goes back on sale at the organizer's
price, and whoever returned it is paid what they paid when somebody takes it.
There is no asking price to inflate, no choosing whose ticket to buy, nobody
paying a stranger, and nothing for a tout to list — the surface is removed
rather than watched. It also means the buyer's path is the ordinary checkout,
so pricing, tax, stock and tickets have one implementation and not two.

**The ticket dies at the moment it is handed back, not when it sells.** That
is what makes the place safe to sell again: anything else lets somebody sell
their place and walk in on it. The door says so in as many words, and the
holder can take it back off the list until somebody buys it.

**The seller is paid when the place goes, and gets the booking fee back too.**
Paying them at once would make the organizer the underwriter of other
people's change of heart. Keeping their fee would charge them for the
privilege of not going. The platform earns its fee from whoever takes the
place instead.

## Look

**Depth, type and radii are tokens, and nothing else may own them.** Every
colour, corner and shadow comes from `packages/tokens/tokens.json`, built to
`tokens.css` and `tailwind.css` and held by `npm run check`: contrast is
asserted for both themes, and no component may paint with a colour of its own.
The phone app had kept two shadows of its own in raw rgb with its own dark
overrides, and they had already drifted from the console's — a card on a phone
and a card in the console were lit by two files that had to be remembered
together. That is the failure the token layer exists to prevent, so the app
defines none.

**A theme is emitted three times, not twice.** `:root` carries light,
`@media (prefers-color-scheme: dark)` guarded by `:root:not([data-theme="light"])`
carries dark, and `:root[data-theme="dark"]` carries it again for an explicit
choice. A viewer who has never touched a theme switch sees the unstamped
document, so a colour defined only inside a `[data-theme]` block never applies
to most people and renders one theme's text on the other theme's ground.

**Corners are small.** 2/4/6/10/14, with the full pill kept for chips and
avatars. A 16px corner on a card and a 24px corner on a sheet read as a
consumer app from 2019, and the radius scale is the single change that dates a
product fastest. Tailwind's own scale is cleared, so a template asking for
`rounded-2xl` gets no rounding at all rather than a silent fallback — a
mistake that is visible instead of quiet.

**Panels lift; rows do not.** A form, a summary, a thing being read or written
gets a shadow. A row in a list does not, because twenty shadows in a column is
a grey page and the depth stops meaning anything. Borders separate rows;
shadows separate objects.

**Money is set in the display face.** `.figure` — display family, tight
tracking, tabular figures — so the same sum is the same shape in the dashboard,
the event list, the payouts balance and a buyer's order summary. A price set in
the body face at semibold is a number that happens to be bold; this is a number
meant to be read across a room.

**Type is two faces with jobs.** Clash Display for headings and figures,
General Sans for everything read as words, both self-hosted from
`packages/tokens/fonts` under the ITF Free Font Licence. No CDN: a font that
arrives over somebody else's network is a font that sometimes does not arrive,
and the fallback is the moment the product looks unfinished.

**Every table filters the same way and says what it is filtering.**
`ui-filter-bar` carries the search box, the controls a screen adds, the chips
for what is currently applied and one sentence of summary. The chips are the
point: a table narrowed by terms the reader cannot see is a table that appears
to be missing rows. A filter is offered only when there is enough to filter and
only for values that exist — a control that can only ever empty the table, or
that offers a status nothing holds, is a control that lies.

**A stand-in for a missing poster is a designed thing, not a gap.** Plenty of
organizers publish before their artwork is ready. A pale wash where the image
goes reads as an image that failed to load, and on a grid where half the events
have posters the other half look like bugs. The stand-in is a lit brand panel
with the night's initial in it, and it is the same panel everywhere one is
needed.

**The front page is made of what is on.** Its hero is the posters of real
nights on sale, the lead one loaded first as the page's largest picture, and a
category or a city is shown by the next poster filed under it. Never stock
photographs: a crowd that was at none of these nights is a promise the page
cannot keep, and somebody else's picture. Where no poster exists the site
draws its own from the tokens. Every figure on the page is one the database
counted, and the organizer pitch states the service charge from the setting
that charges it — no invented statistics and no testimonials from people who
do not exist. The store buttons are the site's own until the operator supplies
Apple's and Google's official badges (LAUNCH.md).

## Asking first

**Every action asks before it happens, and the question names what it does.**
Anything that writes on the server or sends something — create, save, send for
review, delete, cancel, refund, resend, transfer, give back for resale,
invite, remove, change a role, ask for a payout, change payout details, send a
campaign or a message, issue guest tickets, sign out — opens one dialog first:
`ConfirmDialog` from `packages/ui` on the site and in the console, `Dialogs`
in the phone app, drawn as a bottom sheet, the same request in both. Never
`window.confirm`, and never a modal of a screen's own. The question says what
will happen and to whom, with the figure in it — "Refund 2 tickets on
MF-7Q2K?", "$80.00 goes back to ada@example.com" — because "Are you sure?"
is answered yes without being read. The figure has to be the one that will
happen: a sale at the door is not asked about while its basket is being
priced, a repeat counts the night that already exists as the server does, a
copy names only what the server copies, and a code says when it works from
its own From and Until. A question that is read and wrong is worse than none.
What destroys or takes back is red, with
focus on Cancel. In the admin it is Filament's own modal: every action either
requires confirmation or opens a form, with a heading and a description of its
own, never Filament's default sentence; forms that save a page (platform
settings, a tax rate, a dispute's draft) submit to that question — the button
and Enter alike — and are checked before it is asked, so a mistake shows on
its field rather than behind a modal. `EveryActionAsksFirstTest` walks what
each admin screen offers and fails on an action that does anything on one
click, so a new one cannot quietly skip it.

**Some things deliberately do not ask**, and should not be given a dialog
later:

- *Scanning at the door, and the admission chooser.* The chooser is itself the
  question — how many of this table are going in — and a second one at a door
  with a queue is a crowd, which is a safety matter. Ending a door shift does
  ask, and so does a sale at the door: that is money on the record and tickets
  that did not exist.
- *Choosing quantities, and the pay button in checkout.* The payment
  processor's page is the confirmation; a dialog before it is a third step
  between somebody and a ticket. A presale code on the ticket page only shows
  what it opens and spends nothing.
- *Signing in, signing up, and changing or resetting a password.* The form is
  the deliberate act, and a password typed twice has been checked already.
- *Search, filters, sorting and moving between screens*, exports and
  downloads: none of them change anything.
- *Instantly reversible personal toggles*: saving an event, following an
  organizer, turning the phone's own reminders on or off, the theme, which
  organization the app is showing. One more tap undoes each.
- *Arranging a list* — moving a ticket type, an extra, a question or a picture
  up or down. It is put back by moving it again, and a dialog at every step
  would make ordering five things ten questions.
- *Drafts saved as they are typed*: a picture's caption in the console is kept
  on leaving the box. (A draft saved with a button asks, like everything else.)
- *Pages that are themselves the question, with one button*: accepting an
  invitation to a team, confirming a new email address from its link, opening
  a door pass on a phone, arriving as staff from the admin (asked, with a
  reason, before the link was made), and accepting the terms, where the
  unticked box is the question. Deleting an account has its own dialog, which
  asks for the password and names what goes.

## Still open

- **Merchant of record for tax** — determines who remits. An accountant's call.
  The code no longer waits on it: organizer or platform is a platform setting,
  and each order records which applied. Still to decide: which one, whether the
  service charge is taxed, QST registration, the registration numbers, and —
  where the organizer is the seller — how the ticket tax held back from their
  payout reaches them or is remitted for them.
- **Forced-update threshold** — the share of un-updated installs that gates
  launch. Agree the number in advance, not during launch week.
- **Stripe variance handling** — reconciliation will surface historical sales
  whose recorded amount differs from what was charged, some already settled to
  organizers. A commercial conversation, not a code path.
- **Push credentials** — FCM and APNs. Blocked on keys, not on code: following
  an organizer is the list a "they announced a night" push would ride on, and
  it already sends by email. Push is an addition to that, not a replacement —
  a mailbox reaches somebody who installed the app once in June.
- **Wallet passes** — an Apple Wallet signing certificate and a Google Wallet
  issuer account. Nothing is built against either yet, deliberately: a pass
  format that cannot be signed cannot be tested, and an untested signing path
  is one that fails on the first real ticket.
