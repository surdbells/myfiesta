# Running it

What keeps the platform up once it is deployed, and how to get it back when it
is not: what to watch, the nightly backup and how to restore it, what is
deleted on a schedule, where errors go, and how to roll a release back.
[DEPLOYMENT.md](DEPLOYMENT.md) is how it is put up in the first place.

Commands below are written for the compose file, run from the repository root
on the host. On another arrangement, run the same `php artisan …` in the API
image of the release that is running.

```sh
alias fiesta='docker compose --env-file .env.production --env-file .env.release -f ops/docker/compose.prod.yml'
```

`.env.release`, beside `.env.production`, is one line: `TAG=<the release that
is running>`. Every release and every rollback rewrites it
([below](#deploys-and-rolling-back)), and every command reads the images to
run from it — a restore drill, a switch-over, a one-off `artisan` — so none of
them quietly starts a different build from the one serving. The compose file
has no `latest` to fall back on and stops, saying so, when it is missing.
`TAG=… fiesta …` on the command line overrides it for that one command, which
is only ever right for a release that is not running yet. Two `--env-file`s
need Compose 2.17 or later.

## What to watch

Two addresses on the API, for two different jobs.

| Address | Answers | For |
| ------- | ------- | --- |
| `https://api.myfiesta.ca/up` | 200 whenever PHP is serving | the load balancer. It routes on this. |
| `https://api.myfiesta.ca/api/health/ready` | 200 when everything an order needs works, 503 when anything does not | the uptime monitor. It pages on this. |

They are separate on purpose. A queue worker that has stopped is a reason to
wake somebody, not a reason for the load balancer to take the site away from
the people buying on it — which is what routing on the readiness check would do.

`/api/health/ready` checks, in order:

| Check | Fails when | Usually means |
| ----- | ---------- | ------------- |
| `database` | a query does not come back | Postgres down, unreachable, or out of connections |
| `cache` | a value written is not read back | Redis down; sessions, rate limits and the heartbeats below go with it |
| `queue` | no worker has run the heartbeat job for 10 minutes, or at least 10 jobs failed in the last hour | the `worker` container stopped, or every email is failing (the admin's Operations health page has the count and the errors) |
| `storage` | a file cannot be written to and read back from the `private` or `public` disk | a volume full or unmounted, or the bucket's keys wrong |
| `scheduler` | the scheduler has not run for 3 minutes | the `scheduler` container stopped — and with it abandoned baskets, reminders, webhooks and the backup |

The answer says which check failed in a fixed sentence and never why in
detail: anybody can ask, so no host name, exception message or count of
anything the platform holds is in it. The reason is in the API's log (`fiesta logs api`), written as a warning
with the exception. It is limited to 30 requests a minute from one address.

With Redis down it still answers — 503, naming `cache` (and `queue` and
`scheduler`, whose heartbeats are kept there) — and so does `/up`. Every other
request to the API gets a 500 until Redis is back: maintenance mode is kept in
Redis, and while nobody can read whether the platform is down, it is not
assumed to be up. During maintenance (`artisan down`) the readiness check
answers 503 like everything else, without the list; `/up` stays 200.

```json
{"status":"failing","checks":{"database":{"ok":true},"cache":{"ok":true},
 "queue":{"ok":false,"detail":"No worker has run a job for 14 minutes."},
 "storage":{"ok":true},"scheduler":{"ok":true}}}
```

**Set up in the uptime monitor** (any: Better Stack, UptimeRobot, Pingdom —
which one is the operator's choice):

| Monitor | Every | Alert when |
| ------- | ----- | ---------- |
| `GET https://api.myfiesta.ca/api/health/ready` | 1 minute | not 200 on 2 checks in a row |
| `GET https://myfiesta.ca/robots.txt` | 1 minute | not 200 on 2 checks in a row |
| `GET https://console.myfiesta.ca/` | 5 minutes | not 200 |
| `GET https://myfiesta.ca/` containing text only a rendered page has | 5 minutes | missing: the site answers but cannot render, usually because it cannot reach the API |
| TLS expiry on all three hosts | daily | under 14 days |

From two regions if the monitor offers it; one probe failing is more often the
probe. The heartbeats are written into Redis, so on the very first deployment
`queue` and `scheduler` fail for the first minute or two until each has run
once. That is expected.

**Inside the host** each container checks its own part, and `docker ps` shows
which is unhealthy:

| Container | Healthcheck |
| --------- | ----------- |
| `api` | `app:health --only=database,cache,storage` |
| `worker` | `app:health --only=queue` — also unhealthy when the scheduler stops sending it heartbeats |
| `scheduler` | `app:health --only=scheduler` |
| `api-web` | `GET /up` through nginx and php-fpm |
| `site` | `GET /robots.txt` |
| `console` | `GET /` |

Compose does not restart an unhealthy container by itself; the healthchecks
are for `docker ps`, for whatever supervises the host, and for a person. The
same command answers from a shell: `fiesta exec api php artisan app:health`.

**In maintenance mode** workers take no jobs, so after about ten minutes the
`queue` check fails and `worker` shows unhealthy, until `up`; then they catch
up with the heartbeats sent meanwhile within a minute or two. That is expected.
The scheduler keeps its heartbeat through maintenance, so `scheduler` stays
healthy for as long as its container runs.

## Backups

### What is backed up

**The database**, nightly at 06:30 UTC (02:30 in Toronto in summer, 01:30 in
winter; 07:30 in Lagos), by
`php artisan backup:run` in the `scheduler` container:

- `pg_dump` in custom format, compressed, taken from a snapshot — it blocks
  nobody. The rows in every table are counted inside the same snapshot and
  written to a manifest beside the dump, with a checksum of the stored file and
  the release that made it.
- Sealed before it leaves the server with `BACKUP_ENCRYPTION_KEY`
  (XChaCha20-Poly1305, libsodium). Production will not start with the key
  empty or malformed (`app:preflight`). On S3 the bucket also encrypts it at rest
  when `BACKUP_S3_SSE` is set (`AES256` or `aws:kms`; R2 always does).
- Written to `BACKUP_TARGET`: `s3`, a private S3-compatible bucket of its own,
  or `volume`, the `api-backups` volume on the application host. The volume is
  better than nothing and is not off-site: a lost host takes the backups with
  it. Production should use a bucket, in another provider or region from the
  database.
- Kept: the newest of each of the last 7 days, 4 weeks and 6 months. A day or
  week with no backup is skipped, not counted, so a run of failed nights never
  uses up the good ones. Older backups are only removed after that night's
  backup has been stored.

With `SENTRY_LARAVEL_DSN` set, Sentry watches the nightly run as a cron
monitor (`database-backup`) and says so when a night passes without one, or
one fails. `app:health --only=backup`
fails when the newest backup is more than 26 hours old; worth a daily check
from the host's cron or the uptime monitor's heartbeat feature if Sentry is not
set up.

**Not backed up by this — decide before launch:**

| What | Where | The gap |
| ---- | ----- | ------- |
| Uploaded pictures | `MEDIA_DISK=s3`: the media bucket | Covered only if **versioning** is switched on for that bucket, with a lifecycle rule expiring old versions after 30 days. Files are never overwritten (every upload gets a new name), so versioning is what brings back a deleted one. Switch it on. |
| Uploaded pictures | `MEDIA_DISK=local`: the `api-media` volume | **Not backed up.** A lost host loses every poster and logo. Snapshot the volume with the host provider nightly, or move to `MEDIA_DISK=s3` (DEPLOYMENT.md, "Uploaded pictures"). |
| Identity documents and data exports | the `private` disk, on the `api-storage` volume | **Not backed up.** Exports are deleted after a week anyway and can be asked for again. Identity documents would have to be uploaded again by organizers, and their review status survives in the database. Whether that is acceptable is the operator's call; if not, snapshot `api-storage` the same way — encrypted, because these are passports. |

### RPO and RTO

Said plainly:

- **Recovery point: up to 24 hours** from these backups alone. A failure at
  06:29 UTC loses the whole day since the last run. Every sale in that window is
  still in Stripe and Paystack, but rebuilding orders from the processors is a
  manual job. The way to a recovery point of minutes is the managed database's
  own **point-in-time recovery** (RDS, DigitalOcean, Crunchy and the rest all
  offer it): turn it on, with at least 7 days' retention. These nightly backups
  are then the second line — portable, encrypted, and checked — for when the
  provider or the account is the problem.
- **Recovery time: estimated at an hour** for a database of a few gigabytes —
  fetching and loading are most of it, then the switch-over below. It is an
  estimate. The only measured figure so far is the drill on a development
  database (70 tables, 250 KB), run in the production image against Postgres
  17: fetched, decrypted, restored and every table counted in about a second.
  That says the procedure works, not how long production takes. **Run the
  drill on a production-sized copy before launch and write the time here** —
  an RTO nobody has measured is a guess.

### The restore drill

Monthly, and after anything changes about backups. Twenty minutes. It restores
into a new database beside the live one and touches nothing else.

`sh ops/backup/restore-drill.sh` does steps 1, 2 and 4 in one go and prints how
long the restore took (`KEEP=1` to leave the database for step 3). By hand:

1. See what there is:

   ```sh
   fiesta run --rm --no-deps scheduler php artisan backup:list
   ```

2. Restore the newest into a new database on the same server, checking every
   table's rows against the manifest:

   ```sh
   fiesta run --rm --no-deps scheduler php artisan backup:restore --into=myfiesta_drill_$(date +%Y%m%d) --create
   ```

   It fetches the backup, refuses it if its checksum does not match the
   manifest or the key does not open it, loads it with `pg_restore
   --no-owner --no-privileges`, then counts every table. The last line is either
   "all N tables hold exactly the rows the backup recorded", or a table of the
   ones that do not. The counts were taken in the same snapshot as the dump, so
   they match exactly or something is wrong.

   `--into` is refused if it names the live database; there is no flag that
   overrides that. `--create` needs a database user allowed to create databases
   (the managed instance's main user usually is). It connects to the server's
   `postgres` database to do it (`template1` if there is none), never to the
   live one, so it works when the live database is gone. Without `--create`,
   create an empty database first. An older backup: `backup:restore myfiesta-20260920T063000Z.dump.enc --into=…`.

3. Look at it. Connect to the new database and check something a person would
   know: last night's orders, one organizer's balance.

4. Drop it: `DROP DATABASE myfiesta_drill_…;` — or pass `--drop-after` in
   step 2, which drops it at the end, and only if that run created it.

5. Write down the date, the backup's name, how long step 2 took, and anything
   that surprised you.

### Switching over

When the live database is gone or wrong. The same restore, then the
application pointed at it.

1. **Stop writes.** `fiesta exec api php artisan down`, then
   `fiesta stop worker scheduler`. Payment webhooks are refused while it is
   down; Stripe and Paystack both retry for days, so nothing paid is lost —
   they arrive once it is back. Maintenance mode is kept in Redis
   (`APP_MAINTENANCE_DRIVER=cache` in `.env.production.example`), so it holds
   for every API container and survives step 3 recreating them. With the
   driver left at `file` it lives inside the one container, and recreating
   it would quietly open the site again half way through.
2. **Restore into a new database**, as in the drill:
   `fiesta run --rm --no-deps scheduler php artisan backup:restore --into=myfiesta_restored_… --create`.
   Keep the old database if it still exists; do not drop anything today.
3. **Point the application at it.** `DB_DATABASE=myfiesta_restored_…` in
   `.env.production`, then `fiesta up -d` so every container is recreated with
   it, in the release `.env.release` names. `api-migrate` runs first, and
   brings the restored schema up to that release if the backup is older than
   its last migration. The worker and the scheduler start again too, but while
   the platform is down the worker takes no jobs and the scheduler runs
   nothing but its heartbeat. Then check what the restore touched:
   `fiesta exec api php artisan app:health --only=database,cache,storage`.
   Not the full check yet: `queue` fails until the workers are let go in step 5.
4. **Account for the gap.** Everything paid between the backup and the
   failure is in the Stripe and Paystack dashboards. The processors' webhooks
   that arrive after `up` will mark some of those orders paid on their own; list
   the rest before telling anybody it is over.
5. **Open, and back up.** `fiesta exec api php artisan up`. A couple of minutes
   later, when the workers have caught up, every check:
   `fiesta exec api php artisan app:health`. Then
   `fiesta exec scheduler php artisan backup:run` so there is a backup of the
   new state straight away.

### The key

`php artisan backup:key` prints a new one. Keep it in the password manager,
and nowhere near the backups: not on the server's disk outside
`.env.production`, never in the bucket. A backup and its key in the same place
protect nothing; a key lost with the server makes every backup useless.
Changing it only affects new backups — keep the old key until the last backup
made with it has aged out (six months).

## What is deleted, and what never is

Every night between 05:00 and 05:20 UTC, rows that were only ever waiting:

| Command | Deletes |
| ------- | ------- |
| `queue:prune-failed --hours=720` | failed jobs older than 30 days (their payloads carry buyers' names and addresses) |
| `sanctum:prune-expired --hours=168` | sign-in tokens a week past their expiry |
| `auth:clear-resets` | password reset tokens past their hour |
| `app:prune-expired` | address changes and sign-ups whose links have run out |
| `model:prune` | anything a model marks `Prunable` — nothing yet |

Already scheduled before this: `checkouts:expire` (stock holds a day after
they expire), `webhooks:prune` (deliveries after 30 days),
`impersonation:close-lapsed` and `privacy:prune`. Every one of them is safe to
run again at once; a second run finds nothing.

**Never deleted by anything on a schedule:** the ledger, the audit log, orders,
tickets and scans. The ledger and the audit log refuse updates and deletes in
the database itself. `PruneScheduleTest` runs every clean-up against
years-old records of each and counts them afterwards.

### Dispute evidence

What is kept to answer a chargeback ([DECISIONS.md](DECISIONS.md), "A
chargeback is answered from records") runs on three schedules of its own:

| Command | When | Does |
| ------- | ---- | ---- |
| `disputes:collect-evidence` | every 5 minutes | asks Stripe or Paystack for its record of each payment that has landed, and keeps it in `payment_evidence` |
| `disputes:record-completions` | hourly | writes each finished night into `event_completions`, half a day after its door closed |
| `disputes:prune-evidence` | 05:25 UTC | 18 months after each night: clears `purchase_ip` and `purchase_user_agent` on its orders, deletes its `ticket_activity` (whenever each row was written) and `payment_evidence` |

A processor that cannot answer is asked again after 5, 15, 60, 360 and 1440
minutes (`config/disputes.php`); nothing about the order waits on it. After
the last try the row reads `gave_up` with the processor's last answer in
`last_error`, and a warning is logged. To ask again, once the processor is
answering:

```sh
fiesta exec api php artisan tinker --execute="App\Models\PaymentEvidence::where('status','gave_up')->update(['status'=>'pending','attempts'=>0,'next_attempt_at'=>now()])"
```

`ticket_activity`, `event_completions` and a captured `payment_evidence` row
refuse updates in the database. `ticket_activity` and `payment_evidence` take
a delete only from inside `disputes:prune-evidence`, which sets
`myfiesta.retention_prune` for its own transaction; `event_completions` never
takes one. `DisputeEvidenceRetentionTest` holds the prune away from orders,
tickets, scans, the ledger and the audit log. An order whose dispute is still
open keeps its evidence past the 18 months, until the dispute closes. Once a
night is past the 18 months nothing new is written about it: opening its
tickets, or seeing them in the app, leaves no row.

What counts as the tickets being opened: the ticket page, the signed order
link, the calendar file and the app drawing the QR. The page a buyer lands on
after paying is not one — it shows no ticket, and the site's own server asks
it while drawing the page — so it writes nothing. A ticket passed on to
somebody else is written as shown in their app, without their address or
browser. The same thing opened again from the same address within 10 minutes
is one row, whatever the browser calls itself.

## Chargebacks

When Stripe (`charge.dispute.created`) or Paystack (`charge.dispute.create`)
opens a dispute, it is recorded on the order and, a moment later, answered in
draft: the platform asks the processor about the dispute, writes the evidence
from its own records, and emails every Admin and Finance member of staff with
the amount, the reason, the night, the deadline and a link. Organizers hear
through the `order.disputed` webhook, as before. **Nothing is sent to the
processor until one of you sends it.**

### How to respond

1. Open **Money → Chargebacks**. Open, unanswered disputes are at the top,
   soonest deadline first; a deadline within three days is red. Filter by
   deadline, reason, processor or where the answer stands.
2. On the dispute's page, read **Before you answer** first. When the records
   say the buyer is right — a cancelled night nobody refunded, a second charge
   for one order — use **Accept the dispute** and say why. The buyer keeps the
   money; when the processor confirms, the chargeback comes off the
   organizer's balance and the tickets stop working, as for any lost dispute.
3. Otherwise read **What the records hold**, then the fields under **What will
   be sent**. Correct what is wrong and add what you know to be true — never
   what you do not — and **Save draft**. Open the documents to read them.
4. **Submit evidence**. It is final: each processor takes one answer. Stripe
   gets each document through its Files API, then every field with
   `submit=true`; Paystack gets its evidence, one document with everything in
   it, and a `declined` resolution. Paystack refuses evidence without the
   buyer's phone number, which the order may not have; ask the organizer.
5. If the processor refuses, the page says why and nothing is marked sent.
   What it already has is kept, so trying again uploads nothing twice. A try
   Stripe answered with a refusal is tried again under new idempotency keys,
   since Stripe gives an old key its old answer for a day; one that heard
   nothing back is tried under the same keys. Words corrected after Paystack
   already took the evidence go to Paystack as new evidence, and the answer
   names that one.

**Check with Stripe** (or Paystack) asks the processor again — the page says
"Not asked yet" when it did not answer at opening. **Rebuild from the
records** replaces the draft, edits and all, with a fresh one.

Admin and Finance answer; Support can read every dispute but cannot send,
accept or open the documents. Every save, send, acceptance and refusal of
either is in the audit trail: who, when, which fields went and how long each
was, each file's name, SHA-256 and the processor's id for it — including a
file that left on a try the processor then refused — not the words or the
files. `disputes:remind` (hourly) emails Admin and Finance when an unanswered
dispute has five days left and again at two, each once; one that opens inside
five days is announced with its deadline and only reminded at two.

### What wins each reason

| Their reason | What wins it | Accept instead when |
| ------------ | ------------ | ------------------- |
| Fraudulent, unrecognised | the card's bank authenticated the payment (3D Secure — the loss then moves to the bank), CVC and postcode passed, the order's internet address the same one that opened the tickets, the tickets used, earlier undisputed orders; Visa Compelling Evidence 3.0 when Stripe lists it and the records establish it | the bank did not authenticate it and nothing ties the tickets to the cardholder |
| Not received | issued, emailed, opened, scanned in at the door, the night recorded as having taken place | the night was cancelled or never happened, or no ticket was issued |
| Refund not received, not as described, general | the refund policy as the buyer's version showed it, how it was shown and accepted, that no refund was due — or that it was made; the night as listed | a refund was owed and not made |
| Charged twice | the other order, its own charge and its own tickets | there is only one order |

Compelling Evidence 3.0 needs two earlier undisputed payments on the same card,
120 to 365 days before the dispute, matching on the account and the internet
address. **Today no dispute can qualify.** The checkout reads no sign-in, so
no order records the account it was placed on — even a buyer with an account
checks out without one — and no device is identified, on purpose. The page
says so and the ordinary evidence goes. The account a buyer's tickets are
kept in is made from the address typed at checkout, and is not offered to
Visa as the account the order was placed on.

### What is kept, and for how long

- On the dispute, for good like the order: the processor's status and the
  network's reason code, whether and when it was answered and by whom, when
  staff were told and reminded.
- `dispute_evidence`: the processor's account of the dispute (never the
  card's first six digits Paystack sends), the fields, the checklist, and what
  was sent. Deleted by `disputes:prune-evidence` 18 months after the night,
  once the dispute has closed.
- The documents that were sent: on the private disk under `disputes/<id>/`,
  deleted with their row. Previews are made on the spot and not stored. The
  private disk has to be the one volume every API container shares
  (`api-storage`), as it already is for exports.
- The audit trail, for good, without the words or the files.

## Paying later (Klarna and Affirm)

Buyers can pay with Klarna or Affirm on Stripe's page for a night when three
things are true: it is switched on in **Configuration → Platform settings →
Pay later**, the organizer turned on "Pay over time" in the event's Settings,
and the night is in Canadian dollars and starts within the number of days set
there (110 unless changed, and at most 110: Affirm takes a refund back for
only 120 days, and the ten between leave room for a late cancellation or a
refund after the night). Afterpay is left off on purpose: its terms rule out
selling alcohol.

### Setting it up in Stripe

The checkout names one of two payment method configurations, so Klarna and
Affirm appear only where they are offered. Make both in Stripe's dashboard,
in live mode and again in test mode:

1. **Settings → Payments → Payment methods.** Add a configuration (the
   configuration menu at the top of the page) called `myFiesta standard`:
   cards, Apple Pay, Google Pay and Link on, everything else off.
2. Add a second one called `myFiesta pay later`: the same, plus **Klarna**
   and **Affirm** on. Leave Afterpay / Clearpay off.
3. Copy each configuration's id (it starts `pmc_`) into the API's environment:
   `STRIPE_PMC_STANDARD` and `STRIPE_PMC_PAY_LATER`. Then run
   `php artisan config:cache` (the Docker containers do it when they
   restart).
4. On the webhook endpoint, add `checkout.session.async_payment_succeeded`
   and `checkout.session.async_payment_failed` to the events it sends. A
   payment that clears after the page closes completes the session unpaid
   and then sends one of these. Without them the money arrives and no
   tickets are issued.
5. Turn on **Offer Klarna and Affirm** in Platform settings.

With both ids blank, a checkout names no configuration and Stripe offers
whatever the account's default has on, which is how it worked before. With
only `STRIPE_PMC_STANDARD` set, nobody is offered paying later, and nothing
says they can: the event page, the checkout and the console's opt-in all
read `STRIPE_PMC_PAY_LATER` as well as the switch.

### What it costs, and who pays

The lenders charge about twice what a card does (Klarna 5.99% + $0.30,
Affirm 6% + $0.30, against 2.9% + $0.30). Once `disputes:collect-evidence`
has asked Stripe about the payment:

- the admin order page shows **Paid with** (Card, Klarna (paid later), and so on);
- the order's processor fee becomes what Stripe actually took;
- for an order paid later on a night the organizer opted in, the difference
  over a card is taken off their balance as an adjustment, "Paid later with
  Klarna on order …", and the order's processor fee is only the platform's
  part (a card's worth). The order page says what the lender took in all and
  how much of it the organizer paid. The organizer was shown these rates
  before turning it on, and the console's money summary shows the total as
  **Adjustments**.

The organizer keeps paying that difference if the money goes back later,
by a refund or by a lost chargeback: Stripe keeps its fee either way.

### Refunds after the lender's window

Affirm takes money back for 120 days after the payment and Klarna for 180.
After that a refund through Stripe is refused before it is tried, and
nothing moves. Orders get there when a night is moved later after it sold,
or refunded long after it. Tickets paid this way cannot be handed back for
resale once the lender's window closes before the night.

Each refused refund is written on the order's audit trail as
`refund.left_for_support` (who asked, when, and the method), and logged as
an alert. The organizer is told to write to support with the order's
reference; cancelling an event says how many orders were left this way, and
its preview says so beforehand.

To give the money back:

1. Send it to the buyer another way (an Interac e-Transfer, for example).
2. In the admin, open the order. **Refund** is not offered on it; use
   **Record a refund made outside Stripe** instead (Admin and Finance). Enter
   the amount sent (everything left is filled in), the e-Transfer or bank
   reference, and a note, and tick that the money has been sent.
3. That records the refund on the order, takes it off the organizer's
   balance, emails the organizer, and, when it covers everything left, stops
   the order's tickets working. Recording part of it leaves the tickets
   working; the organizer is asked which tickets it was for, as with a
   partial refund made in Stripe's dashboard.

## Errors

Each app reports to its own Sentry project, and only when its DSN is set:

| App | Set | Notes |
| --- | --- | ----- |
| API, worker, scheduler | `SENTRY_LARAVEL_DSN` | every reported exception, plus scheduled-task check-ins |
| Site | `SITE_SENTRY_DSN` | stamped into the page at render; the policy allows the DSN's ingest host and nothing else |
| Console | `CONSOLE_SENTRY_DSN` | stamped at container start, and written into its policy the same way |
| Phone app | `MOBILE_SENTRY_DSN` when building | stamped before `cap sync` (`tools/stamp-mobile-sentry.cjs`) |

With a DSN empty, that app sends nothing — the browser apps do not even
download the reporter. `SENTRY_ENVIRONMENT` (production or staging) labels
them all; `SENTRY_TRACES_SAMPLE_RATE` samples API performance traces. The
browser apps send errors only: no tracing, no session replay.

**Nothing personal is sent.** `send_default_pii` is off and cannot be turned
on from the environment, and no request body is ever attached
(`max_request_body_size` is `never`, also not a setting). The API image keeps
function arguments out of stack traces (`zend.exception_ignore_args` in
`ops/docker/php.ini`). Before anything leaves — an error or a sampled trace,
which goes through the same scrubber — it removes values under names like
password, token, cookie, account number, name, address, email or phone; the
path segment after `/tickets/`, `/unsubscribe/`, `/door-passes/`,
`/door-pass/`, `/join/` and the other links that are credentials; query
strings' secrets; the fragment a staff session arrives in; email addresses, IP
addresses, runs of seven or more digits and card-shaped numbers in any
message, breadcrumb, cache key or span; and the values and host a database
error repeats. A failed query is reported with its statement as written,
placeholders and all, and none of the values it was given. SQL bindings are
never recorded. The API's rules are in
`app/Support/Observability/SentryScrubber.php`, the browser apps' in
`packages/shared/src/error-reporting.ts`, and both have tests — including
ones that walk every route and fail when one taking a credential is not
covered.

**Releases** are the image tag: `TAG` is passed as the `RELEASE` build
argument and baked into each image, so an error names the build that raised
it, and after a rollback Sentry shows the older release again. The phone app's
release is `mobile@<version>+<buildNumber>` from `apps/mobile/package.json`.

**Known gaps.** The phone app uses the browser SDK inside the WebView, not
`@sentry/capacitor`: that one links a native SDK into both projects, and this
project links plugins through SPM, where a plugin that cannot be linked is
silently left out (`tools/check-native-plugins.cjs`). So crashes in the native
shell are not reported; everything the app itself does is. The phone app's
reports come from `https://localhost` (Android) and `capacitor://localhost`
(iOS): if the mobile project's Allowed Domains in Sentry is narrowed from `*`,
keep both. On the site, an error while rendering on the server goes to the
`site` container's log, not to Sentry — only the browser reports. Source maps
are not uploaded, so browser stack traces point into minified bundles. All of
these are worth doing once the projects exist.

**In Sentry, set up:** an alert on every new issue in production; the
`database-backup` cron monitor's alert (it is created by the first run); and
a spike alert on the API project.

## Time zones

An event's start is stored in UTC with the event's zone, and turned into a
clock time in four places: the API's PHP (the admin, emails, tickets), the
database (which day an event or an order falls on), the site's Node (every
page it renders, and the preview a shared link unfurls into), and the
visitor's browser. Each carries its own copy of IANA's time-zone database, in
the edition that was current when it was released. When a province changes
its clocks, a copy from before the change and one from after it put the same
night an hour apart. In 2026 British Columbia
stopped falling back, and a Vancouver night after 1 November came out at
8 p.m. from PHP (2026a) and 9 p.m. from the site's server (2026b). The code
was right both times; the copies disagreed.

So the images read one edition, the one named in `ops/docker/tzdata-edition`,
whichever PHP or Node they run:

- **API** (and the worker and the scheduler, which run its image): the
  timezonedb extension for that edition, in place of the copy compiled into
  PHP.
- **Site**: ICU's zone files for that edition, in `ICU_TIMEZONE_FILES_DIR`,
  which Node reads in place of its own copy. Alpine's `tzdata` package does
  nothing here: Node never reads it. The files come from one commit of ICU's
  icu-data repository and must match the hashes in `ops/docker/icu-timezones`.
- **Console**: nothing. Its image works out no times; the browser does.

Each build stops if its runtime then reads any other edition.

**The database is the fourth copy, and no image sets it.** Postgres works out
local days itself: the site's today, this weekend, later and this month, the
sales report's days, and the dashboards' days, weeks and months. An event it
puts on the other side of midnight is on the wrong shelf, and a night's orders
are counted on the wrong day. A managed Postgres takes its copy from the
provider's minor release. The pgdg packages read the host's `tzdata`. Postgres
cannot say which edition it has, so the API's check asks it for the same
Vancouver night and fails if its answer differs from PHP's. One night only
tells apart editions that differ on it; the provider's release notes say
which edition a minor release carries. If the answers differ, the fix is on
the database side: the provider's newest minor release, or the host's `tzdata`
package, then a restart.

**After a deploy**, ask both, from the release's checkout. They name the same
edition, the one in its `ops/docker/tzdata-edition`, and print the same
Vancouver line. The API's check adds the database's line:

```sh
edition=$(grep -v '^#' ops/docker/tzdata-edition | tr -d '[:space:]')
fiesta exec api php artisan app:time-zones --expect="$edition"
fiesta exec site node tz-version.mjs "$edition"
```

Either fails on any other edition. A mismatch means a container is running
an image built before this, or from another release: check `.env.release`
and `docker compose ps`. The API's check also fails when the database puts
the night elsewhere or does not answer.

**When IANA publishes an edition** (the tz-announce list), change the file
once PECL's timezonedb and ICU's icu-data have both published it. PECL numbers
the editions, so 2026e is timezonedb 2026.5. In `ops/docker/icu-timezones`,
name the icu-data commit that added the edition's folder and the four files'
new hashes (the file says where they come from). Then build and deploy as
usual, and check the database's line. Until PECL and ICU both have it, or
while the hashes are the old edition's, the build stops. That is better than
the API and the site disagreeing.

Browsers carry their own copies and update with the browser. The site draws a
page on the server and the browser then works the same times out again, so a
visitor on an out-of-date browser sees the time their browser works out.
Nothing on the server can change that.

## Going on sale at a set time

An organizer can set the time a night goes on sale (`events.publish_at`, the
venue's time in the console). A repeating night can also put each of its
dates on sale by itself, a set number of days before the night. That setting
is under **How it repeats** on the event. `events:go-live` runs every minute.
It sends each draft whose time has come the way the Submit button would, as
the member who set the time. For a series date, that is the member who turned
the setting on for the series.

- **Approved and unchanged:** it goes on sale. This also covers a series
  date that is the approved night on a new date. An event approved before its
  time stays a draft until that time.
- **Changed since approval, or never approved:** it goes to the review queue,
  and goes on sale once a reviewer approves it.
- **Not sent:** the member who set the time no longer has the right to put
  events on sale, has left the team, or has not confirmed their email. The
  time is dropped. For a series, the series stops putting its dates on sale
  until somebody turns that on again.

Three checks are made again when the time comes, not when it was set:
- **A suspended organization:** its nights wait, with their time kept, and go
  when the suspension is lifted.
- **A night taken down by staff:** it is never sent.
- **The member's permission:** asked again, as above.

Whatever the outcome, everyone at the organization who can put events on sale
gets one email about it (`EventScheduledSale`). When several dates of one
series go in the same run, that is one email that lists every date. The audit
log records who set or cleared each time (`event.sale_time_set`,
`event.sale_time_cleared`), each night sent on time (`on_schedule` on
`event.published` or `event.submitted`) and each night not sent
(`event.scheduled_sale_not_sent`, with the reasons).

**When a time is dropped.** A time is dropped, so that nobody's earlier
decision puts the night on sale later, in three cases. The audit entry for
each keeps the time that was dropped (`sale_time_dropped`).
- **Staff send it back.** Rejecting a night drops its time, so it cannot go
  straight back into the queue unchanged (`event.rejected`).
- **Staff take it down.** Lifting the takedown later leaves the night a
  draft for the organizer to send, and never puts it on sale on an old time
  (`event.taken_down`).
- **The organizer takes it back from review after its time.** It stays a
  draft to change and send again (`event.withdrawn_from_review`). Taken back
  before its time, it keeps the time.

**Staff acting as an organization.** They cannot set a time, turn on a
series' dates going on sale by themselves, or change a series' end, which
can remove dates. At the time, the night goes on sale as the member who set
it, and a staff session is not a member. They can clear a time and turn the
series setting off.

**Running twice.** A second run, or two runs at once, sends nothing twice.
Each night is locked while it is sent, and its time is read again under the
lock, so a time the organizer cleared or moved after the run started is
honoured. A time is cleared once it is used.
If the scheduler stops, nights pile up as drafts past their times. The first
run after it starts sends them all, in the order they were due, 200 at a
time.

**A series.** Shortening a series (its end, a count or a last day) removes
the future dates past the new end that nobody holds a ticket for, counting
tickets listed for resale. Dates people hold tickets for are kept. How often
a series repeats cannot be changed. The organizer stops the series and starts
a new one. Turning on dates going on sale by themselves gives a time only to
dates never on sale and not sent back by staff. A date the organizer took off
sale, or one staff rejected, stays for the organizer to send. The days before
are counted on the venue's calendar, so the time keeps its wall clock across
a clock change.

## Tickets sent on to somebody else

A holder can send a ticket on from the phone ("Send to somebody else") or
from the link in their email ("Send to someone"). Support moves one from the
admin panel ("Reissue to another email"). All three go through the same
handover (`TicketHandover`), so they all behave the same way:

- **The ticket gets a new code.** The old one is refused at the door
  straight away. Support may keep the old code when reissuing, for a holder
  who has it and cannot receive email. The new holder is emailed the ticket with a link of their
  own. That link opens that one ticket and nothing else of the order: no
  receipt, no add-ons, no order reference.
- **The buyer's link stops showing it.** The order's page lists only the
  tickets still at the buyer's address. When a ticket is sent on again, the
  last holder's link stops opening it.
- **Some tickets cannot go.** A used ticket, a table some of whose people are
  already in, or a ticket given back for resale cannot be sent on. Neither
  can a ticket going to the address that already holds it.
- **Holders can send tickets until the event starts.** Support can still
  reissue after that, because someone at the front of the queue with the
  wrong address on their ticket is who reissuing is for.

**One window to know about, with two sides.** A door phone working with no
signal checks codes against the list it last downloaded. If a ticket was sent
on after that download:

- **The person it was sent to is turned away.** The phone has no record of
  the new code, and says "Not on this phone's list". Ask door staff to
  refresh their lists just before the doors open. When somebody at the door
  says a ticket was sent to them, scan it again on a phone with signal; it
  goes through there.
- **The old code can get somebody in once at that door.** The scan is
  flagged as a conflict when the phone syncs. If a holder says their ticket
  was used before they arrived, look in the ticket's history for a transfer,
  and in that door's sync for a conflict around the same time.

Closing transfers at the start of the night keeps both to the time before
the doors open.

Each handover is in the audit log as `ticket.transferred` (by a holder, with
`via` set to `app` or `link`) or `ticket.reissued` (by support). Each is also
in the ticket's history as a transfer from one address to the other. Neither
record contains a code. In the admin panel, a ticket's Transfers list names
who sent it: the account, a member of staff, or "The holder, from their
ticket link" for a send from the email link, which has no account behind it.

## Surveys after an event

The morning after a night, the people who came are emailed a few questions.
`surveys:send-due` runs hourly and sends a night's survey when all of these
hold:

- **The door has been written down.** The night has an `event_completions`
  row, which waits for the last offline door phones to send their scans:
  `disputes:record-completions` writes it on the first hourly run 15 hours
  after the night ends (the door's 3-hour grace plus
  `disputes.completion.after_door_closes_hours`, 12).
- **Its delay has passed.** The delay is 18 hours after the night ends by
  default (or after it starts plus 12 hours, when it lists no end). The
  organizer can set 16 hours to a week on the event's Feedback tab. 16 is
  the shortest because it is an hour past the door's final count, so the run
  that writes the count down always comes before the one that sends. The
  time the Feedback tab shows is the hourly run that really sends it.
- **It is less than a week past that time.** Older nights are left alone, so
  the first run after a deploy does not survey the last year and a half. The
  Feedback tab says so, and "Send it now" is refused for them too.
- **Nothing has switched it off.** Surveys are on unless the organization
  switched them off (Surveys in the console) or the event did (its Feedback
  tab). A cancelled or taken-down event is never surveyed.

**Who is asked.** Every address holding a ticket the door let somebody in on,
leaving out refunded and voided tickets. If the door scanned nobody (a night
run from a printed list), every live holder is asked instead. An address that
turned off marketing emails is never asked. Every survey email has a link to
stop them, and the `List-Unsubscribe` headers mail apps use for their own
button. Stopping these emails does not stop ticket or order emails.

**Each person is asked once.** One invitation per address per night is
enforced by a unique index. Each invitation is claimed before its email is
queued. A run that died halfway is finished by the next run within a day. It
is always safe to run `php artisan surveys:send-due` by hand. An organizer
can also send a night's survey early ("Send it now", which asks first), but
only once the door's final count is in, since before then it would ask the
people who never came. It still goes only once. Staff acting as an
organization cannot send it. Switching a finished night's survey back on, for
the event or the whole organization, sends it on the next hourly run, so the
console asks first there too.

**The questions.** myFiesta's own survey (recommend 0–10, sound, venue,
door, value, and "What should we change?") is installed by a migration
(`…_080200_myfiestas_own_survey`). It is the default for every night.
Organizations can write their own, of up to 12 questions. When a survey goes
out, the night keeps the questions it was sent with, so editing or removing a
survey later changes nothing that was asked.

**What organizers see.** Counts, scores, how answers spread, and what was
written. They never see who answered or whose address it was. Below 5
answers, nothing is broken down, and no question with fewer than 5 answers
shows its figures: the organizer knows who came and could tell whose answer
was whose. The insights are rules, not guesses: the lowest-rated question,
what moved since the last night sent the same survey, and words at least 3
people used, one for each thing they said.

**Personal data.** An export includes a person's invitations and answers,
never the link's token. An erasure deletes both, and the night's results are
worked out again without them.

**When an organizer says nobody was asked**, check in this order:
1. The event's Feedback tab: its first line says when the survey goes, or why
   it will not (switched off, too long ago, waiting for the door's final
   count, nobody held a ticket).
2. The night has an `event_completions` row.
3. `organizations.surveys_enabled` is true for the organization.
4. The night's `event_surveys` row, if it has one, has `enabled` true. Its
   `sent_at` says when the survey went, if it did.
5. The queue worker is running: invitations with `sent_at` set and no email
   received mean the mail is stuck in the queue.

The audit log has `survey.updated`, `survey.sent`, `surveys.switched_on` and
`surveys.switched_off`, and `survey_template.created`, `.updated` and
`.archived`.

## How-to videos

The site's help/videos page shows short videos for buyers ("Buying tickets")
and organizers ("Running your events"). The help page links to it, and so
does Settings in the phone app. Admins and support manage them in
**Configuration → How-to videos**:

1. Upload the video to the myFiesta YouTube channel. Public or unlisted both
   work; private does not play.
2. **Add a video**: a title, the address from YouTube's Share button (or the
   11-character id; only the id is kept), a sentence or two, who it is for,
   and a number for its place (lower comes first).
3. It is saved as a draft. Check it, then press **Publish**. It is on the
   page straight away. **Take down** turns it back into a draft.

Publishing, taking down and deleting each ask first, and each is in the audit
log (`help_video.created`, `.edited`, `.published`, `.unpublished`,
`.deleted`). Finance can see the list but not change it.

The page shows each video's YouTube thumbnail and loads the player only when
somebody presses play, from youtube-nocookie.com. The site's
Content-Security-Policy allows frames from that domain on `/help/videos`
only (`apps/web/src/security-headers.ts`); every other page frames nothing.
The sitemap lists the page once at least one video is published.

## Where else to find an organizer

An organizer page links to the organizer's Instagram, TikTok, X, Facebook
page and website. Owners set these under **How you appear** in the console or
the phone. Each is kept as the account name (a website, and a Facebook page
with no username, as an https address), and the API builds the link itself.
A Facebook page named with accents is kept with them encoded, as a browser
copies it. The legacy import left Instagram, Facebook
and X as whatever the old platform held. Those are read the same way, and a
value that cannot be read as an account on that network is left off the page
rather than shown as a broken link. The organizer sees an empty box for it.

## Friend discounts ("friend buys, both save")

An organizer can offer a friend discount on a night, from the event's
Overview in the console. It needs the codes permission. Each buyer gets a
link of their own: in the tickets email, on the tickets page, and on the
phone's ticket screen. Somebody a ticket was passed on to can ask for theirs
on the phone. The link is the event page with `?ref=`.

- **The friend saves.** A friend who buys through the link gets the night's
  percentage off. It works through a hidden code of the event's own
  (`codes.purpose = share_friend`) that nobody can type. A code the buyer
  types replaces it, because an order takes one code. Somebody using their
  own link is refused at checkout; the site then takes the link off their
  basket and shows the full price, so they can pay.
- **The buyer saves.** Once the friend has paid, the buyer is emailed a
  single-use code for the percentage the friend's order was priced at, off
  any of the organizer's nights (`purpose = share_reward`). It is good for
  12 months. Each link earns up to the night's limit of rewards (5 unless the
  organizer changes it). Friends still save after that. A replayed payment
  webhook rewards once. A free ticket taken through a link saves nobody
  anything, so it earns no reward.
- **When a link stops.** A link works only while its holder still has a
  ticket to the night that gets them in. Refunded, or passed on, and the
  link takes nothing off. Nobody is asked to share once the night's online
  sales are over.
- **Refunds.** A friend's order refunded in full takes back its reward if the
  code has not been spent, even when it is part-way through a checkout at
  that moment (that checkout keeps its price). A spent one stands.
- **Who pays.** The organizer pays for both discounts out of the night's
  proceeds, the same as any code. The console asks them to confirm this
  before an offer starts or changes. The sales report shows a "Friend's
  discount" row and a "Friend rewards" row.

**The cap.** **Configuration → Platform settings → Friend discounts →
Largest friend discount** is the most any night may offer (20% by
default). If you lower it, offers already running above it are held to the
new figure from the next sale. Set
to 0, it switches every friend discount off. Links then take nothing off,
and rewards already sent keep working.

**Finding them.** The organizer's codes list hides friend codes and rewards
unless the "Friend's discount" or "Friend rewards" kind is chosen. The hidden
code can only be changed through the offer. Links are in `share_links`, and
rewards are in `share_rewards`, one per friend's order. The audit log has
`share_offer.set` and `share_offer.ended`. An erasure takes the person's
address and account off their links rather than deleting them, so the
rewards they earned can still be taken back by a refund; the links stop
working. Their orders keep the discount they were given. In the console's
Insights, orders through a friend's link are counted as "Friends' links",
not as promoter links.

**Promoter slugs.** A friend's link rides `?ref=` like a promoter's, and
looks like `f` and ten letters or numbers. The console refuses new promoter
slugs of that shape. Older ones keep working, but the event page asks the
API before greeting anybody, so they are never greeted with a discount. To
list them:

```sql
select organization_id, code, ref_slug from codes
where purpose = 'promo' and ref_slug ~* '^f[a-z2-7]{10}$';
```

## Deploys and rolling back

### Before a deploy

Build the new release's images first, under a tag that identifies the commit
— the short git hash, or the date and a counter — and ask them, not the
running ones, about the release. `TAG=` on the command line applies to that
one command; nothing is switched over yet:

```sh
NEW=$(git rev-parse --short HEAD)
TAG=$NEW fiesta build
TAG=$NEW fiesta run --rm --no-deps api php artisan app:preflight
TAG=$NEW fiesta run --rm --no-deps api php artisan migrate:status
```

- [ ] CI is green for the commit being deployed, and `npm run check` passes.
- [ ] `app:preflight` passes against `.env.production` as the new release will
  read it. `api-migrate` runs it again and stops the deploy if anything is
  missing.
- [ ] Read the migrations `migrate:status` lists as pending — the new release's,
  which the running image does not know about. They must be additive — new
  tables, new nullable or defaulted columns, new indexes — so the release
  before this one can keep running against the new schema. A migration that
  renames or drops something the running release uses is two releases, not one.
- [ ] The newest backup is less than a day old:
  `fiesta exec scheduler php artisan app:health --only=backup`. If the release
  has migrations, take one now as well: `fiesta exec scheduler php artisan backup:run`.
- [ ] The running release's images are still on the host or in the registry,
  under their tag (`cat .env.release`; `docker image ls myfiesta/api`). That is
  the rollback.
- [ ] Nothing is on sale that cannot wait five minutes: not during a big on-sale
  or while doors are open somewhere.

Then the release itself, as in [DEPLOYMENT.md](DEPLOYMENT.md#a-release): write
the new tag into `.env.release`, so that this and every later command runs it,
then replace the containers:

```sh
echo "TAG=$NEW" > .env.release
fiesta up -d
fiesta exec worker php artisan queue:restart
```

After it: `/api/health/ready` is 200, Sentry shows the new release with no new
issues, one real page on the site and the console loads, and the API and the
site read the same time-zone edition, with the database agreeing
([Time zones](#time-zones)).

### Rolling back

The code, not the schema. Every image is tagged with its release, and the
schema only ever grows, so the previous release runs against today's database:

```sh
echo "TAG=<previous tag>" > .env.release
fiesta up -d --no-build api worker scheduler api-web site console
fiesta exec worker php artisan queue:restart
```

Written into `.env.release`, not given on the command line, so the next
`fiesta up -d`, drill or switch-over does not quietly roll forward again.
`--no-build` so compose uses the images already built for that tag.
`api-migrate` runs first as it always does, and finds nothing to do: the older
release carries no migration the database has not already run. Check
`/api/health/ready` and Sentry's release view.

**Migrations are not rolled back to roll back a release.** Every migration
here has a `down()`, and running it drops what the migration added — a
column, a table — with every row written into it since. The few that only add
data have an empty `down()`, and rolling those back changes nothing.

A migration that fails part way usually needs nothing: on Postgres each one
runs in a transaction, so a failure leaves no trace of it and it is not
recorded as run. `api-migrate` stops, nothing new serves, and the fix is a new
release. The exception is a migration that builds indexes concurrently, which
cannot run in a transaction (`$withinTransaction = false`; two do so far): a
failure there can leave an index behind marked INVALID (`\d <table>` in psql
says so). Drop it with `DROP INDEX CONCURRENTLY`, then migrate again.
`migrate:rollback --step=1` is for one situation only — a migration that ran
and turned out to be wrong before the release served anybody. Take a backup,
read that migration's `down()`, then roll back the one step. Anything worse
than that is a restore (above), not a rollback.

**Turning something off without a deploy.** There are no feature flags. What
there is:

- **Maintenance mode**: `fiesta exec api php artisan down` stops everything,
  webhooks included (they are retried). `up` brings it back.
- **Texts**: empty `SMS_COUNTRIES` and recreate the API containers; nothing is
  sent and nothing is claimed.
- **One queue job misbehaving**: `fiesta exec worker php artisan queue:pause redis:default`
  holds the queue while you look; `queue:resume redis:default` lets it go
  again. Jobs wait rather than fail — and the readiness check goes red after
  ten minutes, which is right: nothing is being sent.
- **Platform settings** an administrator can change in the admin
  (Configuration → Platform settings), each audited.

## Still for the operator to decide or set

- A Sentry organization, a project per app, and the four DSNs.
- The backup bucket (in a different provider or region from the database), its
  keys, `BACKUP_S3_SSE` if AWS, and `BACKUP_ENCRYPTION_KEY` — kept in the
  password manager.
- Point-in-time recovery on the managed database.
- Versioning on the media bucket, or a volume snapshot of `api-media` if the
  pictures stay on the host; and whether `api-storage` (identity documents) is
  snapshotted.
- An uptime monitor account, the monitors above, and who is paged.
- The first restore drill on a production-sized copy, and the RTO it measured,
  written into this file.
