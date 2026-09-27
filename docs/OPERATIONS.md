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
issues, and one real page on the site and the console loads.

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
