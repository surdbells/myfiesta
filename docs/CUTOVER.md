# Cutover from the old platform

The day the old myFiesta app stops taking money and this one starts. Two
commands do the work: `legacy:import` brings the old database across, and
`legacy:reconcile` checks the money it brought against Stripe, which is where
the money actually is. Everything else here is the order to run them in and
what to do with what they say.

The old platform was Stripe only, in CAD. There is no Paystack payment in its
database to check: Nigerian sales start on this platform.

## Before the day

- **The dump stays on its machine.** Load it into MySQL on the migration host
  and point `LEGACY_DB_HOST`, `LEGACY_DB_DATABASE`, `LEGACY_DB_USERNAME` and
  `LEGACY_DB_PASSWORD` at it. Nothing else in the application reads that
  connection. Or, with the old app on the same server as this one, point them
  at its live database through an account that can only read it: no dump at
  all, and nothing written there
  ([RUNBOOK-CONTABO-AAPANEL.md](RUNBOOK-CONTABO-AAPANEL.md#121-the-old-database-read-only)).
- **A read-only Stripe key.** In the Stripe account the old platform charged
  through, create a restricted key with *read* on Checkout Sessions,
  PaymentIntents, Refunds and Disputes, and nothing else. Put it in
  `LEGACY_STRIPE_KEY` (config/legacy.php). Without it the command uses
  `STRIPE_SECRET_KEY`, which can move money; the command only ever reads, but
  the key is the guarantee. On a host with cached config, run
  `php artisan config:cache` after changing either.
- **Rehearse** the whole sequence below on the migration host against a
  throwaway database, with the most recent dump you have. The first
  `legacy:reconcile --limit=50` of the rehearsal answers the one question
  the code cannot: whether the old checkout charged buyers what the import
  says it did (see "Amount or currency differs").
- **Know what does not come across.** Bank and Interac payout details and
  identity documents are left behind on purpose; organizers verify again here.
  The 34 accounts on SHA-1 passwords arrive with none and reset on first
  sign-in. Blogs, newsletter, featured, notes and the hand-written
  `transactions` table are not imported.
- **See what a run would do** without doing it:
  `php artisan legacy:import --dry-run` reads everything, writes nothing, and
  says per table how many rows would come, would be tried again, are already
  here, or wait for another.

## In one go, or with a parallel run

**In one go.** The old app is frozen, a final dump is taken, and everything
is imported into a database that has never had an import. The freeze lasts
as long as the whole import does.

**With a parallel run.** The first import goes into this platform's own
database days before, while the old app is still selling, and is run again —
hourly, say — to bring what is new. Each run brings only rows the database
does not have yet, and lists the rows the old app has changed or deleted since
they came across, for a person to settle ("Rows that changed after they came
across"). On the day, the freeze lasts only as long as the last run: the rows
of the final hour. The rest of the day is the same.

A parallel run reads the old database as it is, so it needs the old database
reachable while the old app runs: on the same server, or through a tunnel
([RUNBOOK-CONTABO-AAPANEL.md](RUNBOOK-CONTABO-AAPANEL.md#12-moving-off-the-old-app-on-this-server)
has both, and the commands for every step below on that server). A run while
the old app sells leaves the checkouts of the last 48 hours for a later run,
because one of them may be being paid for that minute; the last run, after
the freeze, brings them with `--frozen`.

Until the switch nobody but staff uses this platform. Anything done here in
between — an event made, a ticket sold — is not in the old app, and never
will be.

That is made so, not hoped for. From the first run this platform holds the
old app's events, on sale, and its organizers' accounts; open to everybody,
it would sell seats the old app is still selling, and an organizer signing up
here with an old account's address would stop that account and its events
coming across. So the API and the console answer staff's addresses alone
until the switch, with the payment webhooks let through
([RUNBOOK-CONTABO-AAPANEL.md](RUNBOOK-CONTABO-AAPANEL.md#123-the-order-of-it),
step 0, on aaPanel; a firewall or load-balancer rule anywhere else).

## On the day, in this order

1. **Freeze the old app.** Stop it taking orders and stop it writing. A
   Stripe Checkout Session it opened before the freeze can still be paid until
   it expires (24 hours unless the old app set less). You can wait that out,
   or go ahead: a payment that lands after the dump (or the last run) shows up
   in the report as `paid_in_stripe_only`.
2. **Take the final dump** and load it on the migration host. With a parallel
   run there is nothing to load: the old database itself, frozen, is read.
3. **Import.** In one go, into an empty database: run
   `php artisan migrate --force` first. With a parallel run, into the same
   database every earlier run went into. Either way:

   ```
   php artisan legacy:import --frozen
   ```

   `--frozen` because the old app has stopped: a checkout it opened in the
   last 48 hours will never be settled there, so it comes across as it
   stands, and a payment that lands on it after all is what
   `legacy:reconcile` reports as `paid_in_stripe_only`. Without it, those are
   left for a later run that a frozen app makes pointless.

   Rows are never updated here, only added. In one go, a database that held a
   rehearsal import would keep the rehearsal's copy of every row, so the final
   import goes into one that never had an import. With a parallel run the
   database has had every run, and every change the old app made to a row
   after it came across has been listed and settled by now ("Rows that changed
   after they came across"). If that list has grown past settling by hand,
   the way out is the other shape: an empty database and one import.

   Only one import runs at a time against a database, whoever starts it. A
   second one says another is running and does nothing.

   The command ends with the rows that did not come across, if any, and exits
   non-zero while there are some. Fix the cause and run it again (see
   "Running it again"). A row that is meant to stay behind, such as a
   duplicate account or a test sale, is said so with a reason, and is then no
   longer listed:

   ```
   php artisan legacy:import --leave-behind=user_accounts:32 --because="duplicate of account 26"
   ```

   Only for rows that should not exist. A paid order left behind is a buyer
   with no tickets and a sale the organizer is never credited for, and a
   settlement left behind is a payout the organizer's balance will pay again.
   Fix those instead.
4. **Posters.** `php artisan legacy:import --posters-only`. About 288 MB, and
   it can be interrupted and re-run the same way. The platform works without
   them, so if time is short this can run after the switch.
5. **First look at the money.**

   ```
   php artisan legacy:reconcile --limit=50
   ```

   If most of these are `amount_mismatch`, stop here. See below.
6. **The full dry run.** `php artisan legacy:reconcile`. Nothing is changed.
   Read the report (see "Reading the report") and deal with every line that
   is not `matched`.
7. **Record what Stripe already refunded.** `php artisan legacy:reconcile --apply`.
   This records refunds made in Stripe that the old database never heard
   about, and nothing else, without emailing organizers about them (see
   "Refunded in Stripe, not here").
8. **Sign off the report**, then **switch myfiesta.ca** to this platform:
   DNS, or, with the old app on this same server under aaPanel, its site's
   configuration, which is instant and as quick to put back
   ([RUNBOOK-CONTABO-AAPANEL.md](RUNBOOK-CONTABO-AAPANEL.md#125-switching-myfiestaca)).
9. **Afterwards:** delete the restricted key in Stripe, remove
   `LEGACY_STRIPE_KEY`, and keep the reports with the cutover record.

## Running it again

**`legacy:import`** is safe to run as often as you like. Each row of the old
database is written in one short transaction together with its `legacy_map`
entry: an order with its lines and its ledger entry, an account with its
organization, an event with its venue, one ticket. It all commits, or none of
it does. A row that
fails is rolled back, written to `legacy_import_failures` with its source
table, its old id and the reason, and the run moves on. The next run skips
everything already in `legacy_map` and tries only what is missing. Rows that
depend on a missing one come across on the run after their parent does. Until
then they are skipped with a note (the tickets of a failed order) or listed
as waiting for it (an order whose ticket type failed, a settlement whose event
failed). Nothing is written without a parent that is still in the old
database: an order never comes across without its basket, or a settlement
without its event. Only a parent the old database no longer has at all is
written around, with a note: a basket line whose ticket type was deleted is
dropped, and a settlement whose event was deleted comes across under no event.

A row listed as failed that is in fact across (the connection dropped just as
it committed) is cleared by the next run. A row left behind with
`--leave-behind` is still tried each run in case its cause was fixed; if it
comes across, the record says so, and if not, it stays off the list. The
reason is kept in `legacy_import_failures.left_behind_because`.

The run is not one big transaction on purpose: that would hold locks for as
long as the import takes and lose everything to one bad row two hours in.

A row that has come across is passed over by every later run and never
changed, but it is not forgotten. What the old row said is kept beside it as a
digest (`legacy_map.source_hash`: of the columns the import reads, never the
values), and a later run that finds the old row saying something else, or
gone, lists it (next section). Rows imported before the digest was kept have
none to compare with, and are not reported on a guess.

A sale whose checkout the old app opened in the last 48 hours is left for a
later run and counted as `left for later`: somebody may be paying for it in
the old app that minute, and brought across now it would arrive cancelled and
stay so. A run once it is older brings it as the old app then says. After the
freeze, `--frozen` brings them all as they stand.

`--dry-run` does everything but write, and prints the same table a run does
with what it would do. It cannot say whether a row would fail — only writing
it tells — and it clears and retries nothing.

One import at a time: `legacy:import` holds a lock in this database (a
Postgres advisory lock) for as long as it runs, and one started meanwhile,
from any shell or container, says another is running and does nothing. The
lock goes with the process's connection, so a run that was killed leaves
nothing behind to clear.

Two things not to do. Do not delete rows from `legacy_map` to force a row
in again: what it points at is still there and would be made twice. And do
not expect a fix in the old database to reach a row that has already come
across; fix those here. Each run lists them.

## Rows that changed after they came across

During a parallel run the old app keeps writing to the rows already imported:
a refund, a night moved, a ticket scanned at the door, an organizer's new
address. The import never changes a row it already made — by then a ledger
entry, a refund or a ticket may hang off it — so every run says which rows
moved instead, and each is for a person to settle. The run ends:

```
  WARN  Rows changed in the old app since they came across.
  Nothing here was changed: each is for a person to settle.
  tickets_sales changed .................................. 17, 18
  events changed ......................................... 176
  ticket_issued deleted in the old app ................... 901
```

then, for orders, a table of each one's legacy sale id, its reference here,
its status here, the old app's status now, and what to do. Ids, references and
statuses only, never a name, an address or a ticket code: the output ends up in
logs. A row is listed on every run from then on, settled or not — it is
compared with what it said when it came across, which nothing here changes —
so keep a list of the ones settled.

| Old table | What has usually changed | What to do here |
| --- | --- | --- |
| `tickets_sales` (orders) | the payment status | what the table's last column says. *Refunded in the old app*: the money went back through Stripe, and step 7's `legacy:reconcile --apply` records it — nothing else to do. If `legacy:reconcile` calls it `matched` instead, Stripe holds no refund and the old app only said so: ask the organizer. *Paid in the old app since*: `legacy:reconcile` lists it as `paid_in_stripe_only`; settle it as that class says. *No longer paid there*: look the payment up in `legacy:reconcile`'s report. *Status agrees*: the basket, the buyer or the amount changed; compare the two by hand, and a change of money is an engineer's ledger entry, never an edit. |
| `events` | title, date, time, venue, on or off sale | make the same change here. A night the old app cancelled needs the organizer's word on refunds before it is cancelled here. |
| `event_tickets` | price, how many, on or off sale | the same change to the ticket type here. |
| `ticket_issued` | checked in at the door, or the holder's name | a check-in at a night that has passed needs nothing. A name, correct here. |
| `user_accounts` | name, email, phone, password | the address matters most, being what they sign in with: have it changed here, or tell the person to sign in with the old one and change it. A password changed there does not come across; "forgot password" does it. |
| `settlements` | the amount or the note of a payout already made | compare with the old platform's payout records, as "What this does not check" asks for all of them; a correction is an engineer's ledger entry. |

**Deleted in the old app.** A row that came across and is no longer in the old
database is listed as deleted and kept here: an order the old app deleted may
still be somebody's tickets. Decide with the organizer. A ticket that should
not exist is voided in the admin's Tickets screen; nothing is deleted, because
the ledger and the audit log could not follow.

**`legacy:reconcile`** without `--apply` only reads, and can be run any number
of times. Each run writes a new report. `--resume` carries on the latest
report of the same kind (a dry run resumes a dry run, `--apply` an apply) and
asks Stripe again about orders it could not reach last time. `--apply` is
keyed on Stripe's refund id across every order, so a second run records
nothing twice and one Stripe refund is never written against two orders.

It exits non-zero when some orders could not be checked (run again with
`--resume`) or when Stripe refuses the key. A test key asking about live
sessions stops the run at once rather than reporting every order missing.

## Reading the report

`storage/app/reconciliation/reconcile-<time>.csv`, or `…-apply.csv` for an
`--apply` run. One line per imported order that reached Stripe, worst first.
Abandoned checkouts that never opened a session are counted, not listed.

| Column | |
| --- | --- |
| `legacy_sale_id` | `tickets_sales.sales_id` in the old database |
| `order_reference` | the order here; search for it in the admin panel |
| `outcome` | one of the classes below |
| `status_here`, `total_here`, `refunded_here`, `currency_here` | what this database says |
| `stripe_status`, `stripe_amount`, `stripe_refunded`, `stripe_currency` | what Stripe says |
| `stripe_reference`, `payment_intent` | what to look up in the Stripe dashboard |
| `detail` | the disagreement in a sentence |
| `action` | what `--apply` did, or why it left it |

Amounts are in cents. The file holds no buyer names, emails or ticket codes,
but it is a list of orders and amounts: keep it with the cutover record, not
in a shared channel.

## What to do with each class

**`matched`.** Nothing.

**`unreachable`.** Stripe did not answer. `php artisan legacy:reconcile --resume`.

**`paid_in_stripe_only`: paid in Stripe, not here.** A buyer paid and the old
app never recorded it, so there are no tickets. Usually a payment that landed
after the freeze, or a buyer who closed the tab. Do not mark the order paid in
the database. Decide with the organizer: refund it in Stripe, or have them
issue the tickets.

**`disputed_in_stripe`: disputed in Stripe, not here.** The buyer disputed
the charge with their bank (a chargeback). The session still reads paid and
the payment still reads succeeded, which is why nothing else in the report
would show it, but Stripe has taken the money back or is holding it until the
dispute is decided. If the order reads paid here, the organizer's balance
still counts the sale. Do not approve a payout request from that organization
until it is settled. The dispute id is in `detail`; look it up in the Stripe
dashboard. While it is open, answer it there before its evidence deadline. If
it was lost, the sale comes out of the organizer's balance: an engineer writes
the chargeback ledger entry, and the order's tickets are cancelled. A dispute
the organizer won, or an inquiry that closed without becoming a dispute, took
nothing and is not listed; nor is one this platform already holds in the same
state. `--apply` does not touch these orders, and a Stripe refund on the same
payment is only mentioned in `detail`.

**`shared_stripe_payment`: one Stripe payment, several orders.** Two or more
imported orders carry the same Checkout Session, so each credits the
organizer with money Stripe took once. `detail` names the other orders. Look
at them together, in the Stripe dashboard and in the old database, and decide
which order the payment belongs to. The others need their sale reversed in
the ledger by an engineer, and their tickets cancelled unless they are the
ones the buyer is holding. Hold payouts to that organization until it is done.
`--apply` does not touch these orders.

**`not_paid_in_stripe`: paid here, not in Stripe.** The old app said paid
and Stripe never took the money: the session expired or the payment failed.
The buyer holds tickets they did not pay for, and the organizer's balance
counts the sale. Do not approve a payout request from that organization until
it is settled. Tell the organizer. They can cancel the tickets or keep them as
comps, and an engineer writes the reversing ledger entry (the ledger is
append-only).

**`missing_in_stripe`: not found in Stripe.** First check the key belongs to
the account the old app used. Several at once almost always mean the wrong
account. On the right account, treat it as `not_paid_in_stripe`.

**`amount_mismatch`: amount or currency differs.** If most orders differ the
same way (say, every one by a processing fee), the import's totals are wrong,
not the orders. The importer takes the total as `_cost` plus the 8% service
charge; if the old checkout charged something else, that rule has to change.
Do not run `--apply`. Stop, have the importer corrected, and import again into
an empty database. A few scattered mismatches are individual orders: look each
one up in Stripe, agree with the organizer what was really paid, and have the
ledger corrected before any payout. Stripe refunds on these orders are
mentioned in `detail`, and `--apply` does not record them until the amount is
settled.

**`refunded_in_stripe_only`: refunded in Stripe, not here.** Money went back
through Stripe and the old database never heard. `--apply` records it the
way a refund made in the Stripe dashboard is recorded after the cutover: a
refund row, the ledger reversal, the order partly or fully refunded, and the
tickets cancelled when the whole order went back. Unlike a dashboard refund
after the cutover, it does this quietly: nobody at the organization is
emailed and their integrations are not sent `order.refunded`. These refunds
went back before the switch, some of them months ago, and one email per
refund on the morning of it would be news about nothing that just happened.
It is still audited: `refund.made_elsewhere` and `refund.reconciled`, each
with `organizer_told: false`.

The one thing the email would have done is ask which tickets a partial refund
was for. Stripe does not say, so a partial refund cancels no ticket, and its
line in `action` ends "part of the order: no ticket stopped". For an event
that has already happened there is nothing to do. For one still to come, ask
the organizer which tickets it was for and Void those in the admin's Tickets
screen. Do not refund them: that would send the money a second time.

The refund and its ledger entries are dated the day you run `--apply`;
Stripe's own date is in the `action` column and the audit log. A line still
showing this after `--apply` says in `action` why it was left: a refund still
pending here, a different currency, or more than is left on the order. Those
are settled by hand.

**`refunded_here_only`: refunded here, not in Stripe.** The old database says
refunded and Stripe shows no refund, so the buyer may not have their money.
Check the payment in the Stripe dashboard. If nothing went back and they are
owed it, refund it in Stripe. The order already reads refunded here, so there
is nothing more to record.

**`no_stripe_id`: paid here, no Stripe id.** A paid order with no session to
check it by. Find it in Stripe by amount and date. If there is no payment,
treat it as `not_paid_in_stripe`.

## What this does not check

Settlements, the money the old platform already paid out to organizers, are
imported as they were recorded and not checked against anything. Before the
first payout here, compare each organizer's imported settlements with the old
platform's payout records.
