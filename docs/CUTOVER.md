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
  connection.
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

## On the day, in this order

1. **Freeze the old app.** Stop it taking orders and stop it writing. A
   Stripe Checkout Session it opened before the freeze can still be paid until
   it expires (24 hours unless the old app set less). You can wait that out,
   or go ahead: a payment that lands after the dump shows up in the report as
   `paid_in_stripe_only`.
2. **Take the final dump** and load it on the migration host.
3. **Import into an empty database.** Run `php artisan migrate --force`, then:

   ```
   php artisan legacy:import
   ```

   A database holding a rehearsal import keeps the rehearsal's copy of every
   row it already has: rows are never updated, only added. So the final
   import goes into a database that has never had one.

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
   about, and nothing else (see "Refunded in Stripe, not here").
8. **Sign off the report**, then **switch DNS** to this platform.
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

Two things not to do. Do not delete rows from `legacy_map` to force a row
in again: what it points at is still there and would be made twice. And do
not expect a fix in the old database to reach a row that has already come
across; fix those here.

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
tickets cancelled when the whole order went back. Each one queues an email to
the people who can refund on that organization. For a partial refund it asks
them which tickets it was for. The refund and its ledger entries are dated
the day you run `--apply`; Stripe's own date is in the `action` column and
the audit log. A line still showing this after `--apply` says in `action`
why it was left: a refund still pending here, a different currency, or more
than is left on the order. Those are settled by hand.

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
