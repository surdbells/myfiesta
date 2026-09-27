# Deploying this

Four things run: the API, the queue worker, the scheduler, and two front ends.
Two of them are invisible when missing, which is why they are named first.

`ops/docker/compose.prod.yml` is one working arrangement of all of it. It is
not the only one — this reads as a statement of what any arrangement has to
provide, and a platform-as-a-service or Kubernetes deployment needs the same
list.

## The two nobody remembers

**The queue worker.** Nearly every email this platform sends is queued:
tickets, reminders, campaigns, announcements, the link behind a privacy
request. Without a worker an order is paid, the tickets are minted, the ledger
is written — and the buyer is told nothing at all. Nothing errors.

The exceptions carry a link whose token exists nowhere else — a password reset,
a team invitation, the link that finishes a sign-up or proves an address, the
link that moves an account to a new address — and go out while the request is
answered, because a queued email is a copy of its token sitting in the jobs
table. (A sign-up for an address that already has an account goes the same
way, so the two take the same time to answer.) The other emails about moving an account go
with them, so the owner's warning never waits on a worker that the link did
not. So the API itself has to reach the mail server, not only the worker.

**The scheduler.** One minute of cron, running `schedule:run`. Without it:

| Not running | What happens |
| ----------- | ------------ |
| `checkouts:expire` | abandoned baskets hold stock until the event reads as sold out that nobody bought |
| `reminders:send` | nobody is reminded of the night they bought for |
| `webhooks:retry` | a delivery that failed once is never tried again |
| `webhooks:prune` | copies of what was sent to organizers' systems, buyers' names inside, pile up past their thirty days |
| `campaigns:send` | a scheduled campaign never goes out |
| `series:extend` | a repeating event stops appearing on new dates |
| `privacy:prune` | data exports sit on disk past the week they are allowed |
| `app:heartbeat` | the readiness check reports the scheduler and the worker as stopped, whether they are or not |
| `backup:run` | no nightly database backup — see [OPERATIONS.md](OPERATIONS.md#backups) |
| `queue:prune-failed`, `sanctum:prune-expired`, `auth:clear-resets`, `app:prune-expired` | failed jobs, expired tokens and dead links pile up, each holding somebody's details |

Both are in the compose file. `ops/systemd/` has the same two for a host
without containers.

## The processes

| Process | What it is | Notes |
| ------- | ---------- | ----- |
| `api` | php-fpm behind nginx | the only thing that touches the database |
| `api-web` | nginx in front of `api` | serves the admin panel's files and `/storage/`; believes forwarded headers only from `TRUSTED_PROXIES` |
| `worker` | `queue:work` | give it 90 seconds to stop: it must finish the job in its hands |
| `scheduler` | `schedule:work`, or cron running `schedule:run` | one minute |
| `site` | Node, server-rendered | it renders on a server so a shared link unfurls; that is the sales channel |
| `console` | static files behind nginx | no rendering: every page needs a session and none is ever shared |

The API image runs `api`, `worker` and `scheduler`. That is deliberate — a worker running last
week's release while the site runs this week's is how a queued job
deserialises into a class that has changed underneath it.

## Addresses are given at run time

No host is compiled into any bundle, so one artifact serves staging and
production. Promoting a staging build cannot carry a staging address with it,
and `npm run check` holds the pieces to the same shape.

| App | How it is told | Must be set |
| --- | -------------- | ----------- |
| API | `.env` | `APP_URL`, `PUBLIC_URL`, `CONSOLE_URL`, `CORS_ALLOWED_ORIGINS` |
| Site | environment, stamped into the page as it renders | `ALLOWED_HOSTS`, `API_BASE_URL`, `CONSOLE_URL`, `PUBLIC_URL` |
| Console | environment, stamped into `index.html` at start-up | `API_BASE_URL`, `PUBLIC_URL` |
| Phone app | stamped into the build before packaging | `API_BASE_URL`, `PUBLIC_URL` |

Two of these fail silently if forgotten, so both now refuse instead. The
console's entrypoint will not start without its addresses, and the site
refuses to render for a Host that is not on `ALLOWED_HOSTS` — the second used
to fall back to client rendering, which looks fine to a person and arrives
empty at a crawler.

`CORS_ALLOWED_ORIGINS` has to name every front end, including the two origins a
Capacitor app reports (`https://localhost` on Android, `capacitor://localhost`
on iOS). An origin missing there is a browser refusing every request with an
error that says nothing about why.

## It will not start half-configured

Some settings fail quietly when they are wrong, and each of those looks like a
working platform until a buyer is at a door with no ticket. So in production
the API refuses to run with any of them wrong, and says which:

| Variable | Refused when | Because |
| -------- | ------------ | ------- |
| `APP_KEY` | empty | payout details, identity documents and every signed link depend on it |
| `APP_DEBUG` | true | an error page would show a stranger the configuration |
| `STRIPE_SECRET_KEY`, `PAYSTACK_SECRET_KEY` | empty | no checkout opens in that currency; Paystack also signs its webhooks with its key |
| `STRIPE_WEBHOOK_SECRET` | empty | every Stripe webhook is refused, so nobody who pays is ever marked paid |
| `MAIL_MAILER` | `log` or `array`, or SMTP with no host | tickets and links reported sent and delivered to nobody |
| `SMS_DRIVER` | `log`, or a provider without its credentials and `SMS_INBOUND_SECRET`, while `SMS_COUNTRIES` names a country | every text reported sent and none arriving; STOP not working |
| `TRUSTED_PROXIES` | empty, or a wildcard | see the next section |
| `BACKUP_ENCRYPTION_KEY` | empty, or not a key `php artisan backup:key` made | every nightly backup would hold every buyer's details in the clear, or fail every night |

`php artisan app:preflight` prints the whole list at once. `api-migrate` runs
it before it migrates, so a deployment that is not ready fails there and
nothing is migrated or served. The API image runs it again as php-fpm, the
worker and the scheduler start (`ops/docker/api-entrypoint.sh`). The worker
and the scheduler would also refuse to start on their own, and a web request
that reaches php-fpm without the check having run is answered with an error
rather than served.

Every other command runs. Build-time commands — `package:discover`,
`config:cache` and the rest — work with no secrets at all, and so does anything
an operator needs to put a box right:
`fiesta run --rm --no-deps api php artisan key:generate --show` for the
missing `APP_KEY` the list names, `tinker`, or `app:preflight` to check again
(`fiesta` is the compose command in [OPERATIONS.md](OPERATIONS.md), which
reads `.env.production` and the release to run from `.env.release`).

To send no texts until a provider account exists, empty `SMS_COUNTRIES`: then
nothing is sent and nothing is claimed. Outside production the same list is
printed and nothing is refused; `--strict` fails on it anyway.

Underneath that, whatever the environment, a gateway whose webhook secret is
blank refuses every webhook. A signature keyed with nothing is one anybody can
make.

## Behind the load balancer

`TRUSTED_PROXIES` is the addresses the load balancer connects from — addresses
or CIDR ranges, comma-separated, as `api-web` sees them.

| Load balancer | `TRUSTED_PROXIES` | `API_BIND` |
| ------------- | ----------------- | ---------- |
| on the same host as the containers | the compose network's gateway (`docker network inspect myfiesta_default`) | `127.0.0.1`, the default |
| on another machine | that machine's addresses | `0.0.0.0` |

The two go together. A balancer on the same host reaches `api-web` through
Docker's proxy, and so arrives from the gateway — but so does anything else
that comes in through that proxy, a visitor over IPv6 when the network has
only IPv4 among them. With the port public and the gateway trusted, that
visitor could name its own address and claim https. So the compose file
publishes the API on loopback unless told otherwise, and the gateway is never
trusted on a public port.

Only those addresses may say who the client is (`X-Forwarded-For`) or that it
came over https (`X-Forwarded-Proto`). From anybody else both are ignored. The
API's nginx used to believe them from everybody, which let a client name its
own address and walk past every per-address limit, the one on guessing
passwords included — and made any request carrying the header, even
`X-Forwarded-Proto: http`, count as https.

The same variable is read twice: by `api-web`, which works out the client's
address and whether it was https before PHP sees the request, and by Laravel
(`config/trustedproxy.php`) for any arrangement without that nginx. Neither
starts without it or with a wildcard. `X-Forwarded-Host` is never believed:
the Host header arrives intact, and a second copy is only a way to put another
site's name into links.

Left empty on purpose it would mean every visitor shares the load balancer's
address, and so one rate limit — the sixth sign-up in an hour from anywhere
would be refused.

## Security headers

| Serves | Set by | Policy |
| ------ | ------ | ------ |
| API, admin panel, the pages behind emailed links | `SecurityHeaders` middleware | nothing framed, ever; JSON may load nothing; the emailed-link pages only their own form |
| API files on disk (the panel's scripts, styles and fonts) | `ops/docker/api.nginx.conf` | the same, for what nginx serves without PHP |
| Site | `apps/web/src/security-headers.ts`, from `server.ts` | its own scripts plus the inline ones Angular writes, each carrying a per-response nonce; calls only `API_BASE_URL` |
| Console | `ops/docker/console.nginx.conf`, the API origin written in at start-up | its own scripts plus WebAssembly for the door's QR decoder; calls only `API_BASE_URL` |

When `SITE_SENTRY_DSN` or `CONSOLE_SENTRY_DSN` is set, that app's policy also
lets it send to the ingest host the DSN names (its origin only, never the key),
and to nothing else of Sentry's: the reporter itself is served from the app's
own origin.

All of them send `X-Content-Type-Options: nosniff`, a `Referrer-Policy` that
tells other sites no more than the origin, and a `Permissions-Policy` that
turns off sensors and payment sheets. Only the console keeps the camera, for
the door. HSTS is sent when the request came over https.

Only the site's embedded checkout (`/embed/…`, and the pages it leads to) can
be framed, by any site: an organizer's venue site is anywhere. Everything else
refuses to be framed. The console build does not inline critical CSS
(`angular.json`), because that loads the rest of the stylesheet with an inline
`onload` the console's policy refuses; the site keeps it, and Angular loads the
rest with a script that carries the nonce.

Pictures may come from any https address, because posters are on the API's
disk or in a bucket only the API knows about. Adding anything else — a
third-party script, a font service, an iframe — means adding it to the policy
that serves it, or the browser will refuse it and say so in the console.
`ng serve` sends the same policies, plus what the dev server itself needs.

## Where replies go

`MAIL_SUPPORT_ADDRESS` is an inbox a person reads. The security emails — an
account moved to a new address, an organization's payouts pointed somewhere
new — tell somebody who did not do it to reply straight away, because by then a
reset link may go to the wrong person. This is the Reply-To on those emails.

Left unset, replies go to `MAIL_FROM_ADDRESS` instead. That is at least an
inbox, but it is chosen for deliverability: in `.env.production.example` it is
the address tickets are sent from, and in `apps/api/.env.example` a
placeholder. Set `MAIL_SUPPORT_ADDRESS` in `.env.production` — or make sure
somebody reads the from address — before anybody real signs up; a reply to a
warning about a stolen account that lands nowhere is worse than no advice at
all.

## Uploaded pictures

Posters, organizer logos and gallery pictures go on the API's `public` disk.
`MEDIA_DISK` chooses where that is; nothing else changes between the two,
because the database holds paths and every address is asked of the disk.

**`MEDIA_DISK=local`** (the default). Files live under `storage/app/public`
and are served at `APP_URL/storage/…`.

- With the compose file: they are on the `api-media` volume. The API
  containers write it; `api-web` mounts it read-only and serves `/storage/`
  straight from it (`ops/docker/api.nginx.conf`). It is a volume of its own so
  nginx never sees `api-storage`, where identity documents and exports live.
  Back it up with the database — a poster is not reproducible.
- On a host without containers: `php artisan storage:link` once, and an nginx
  `location ^~ /storage/ { alias <api>/storage/app/public/; }` — the `^~`
  keeps any `.php` under it from reaching PHP. `storage/` must survive a
  deploy, so keep it outside the release directory.
- Before `api-media` existed the pictures were written inside `api-storage`.
  A deployment that uploaded anything then copies `storage/app/public` from the
  old volume into `api-media` once.

**`MEDIA_DISK=s3`**: any S3-compatible bucket, from the `AWS_*` variables.
The bucket is public by design — never the one private files go in.

| Provider | `AWS_ENDPOINT` | `AWS_URL` | `AWS_DEFAULT_REGION` | `MEDIA_VISIBILITY` |
| -------- | -------------- | --------- | -------------------- | ------------------ |
| AWS S3 | empty | the CDN or bucket address, or empty for `https://<bucket>.s3.<region>.amazonaws.com` | the bucket's region | `private`, with Block Public Access off for the bucket and a policy allowing public `s3:GetObject` |
| Cloudflare R2 | `https://<account>.r2.cloudflarestorage.com` | **required**: the bucket's public URL or custom domain | `auto` | `private`; public access is switched on for the bucket |
| DigitalOcean Spaces | `https://<region>.digitaloceanspaces.com` | the Spaces CDN or custom domain | the region, e.g. `nyc3` | `public` |
| MinIO | its address | its public address | anything | as its policy needs, with `AWS_USE_PATH_STYLE_ENDPOINT=true` |

`private` sends no ACL; new AWS buckets and R2 refuse a public one outright,
so there the bucket's own setting is what makes files readable. Spaces has no
bucket-wide switch, so each file is written `public`. Every file is written
with a year's `Cache-Control`: files are named afresh on every upload and never
overwritten.

Moving an existing deployment from local to a bucket is a copy, then the
switch: sync `storage/app/public` to the bucket with the same paths, set
`MEDIA_DISK=s3`, and recreate the API's containers (`up -d`), which rebuild
their config caches as they start. No rows change.

If pictures 404: with `local`, `api-web` is missing the `api-media` mount or
`APP_URL` is not the address people reach the API on; with `s3`, `AWS_URL` is
empty on R2, or the bucket is not public.

## Who runs this

The site's contact, terms, privacy and refund pages show the operator's
registered name, support inbox, postal addresses and, if there is one, a phone
number. They come from `CONTACT_*` in `.env` (`config/myfiesta.php`, served at
`GET /api/contact`), because none of it is ours to write and staging must not
show production's. Until a name, an inbox and one address are set, those pages
carry a notice saying the details are still to be filled in — both markets
require a reachable operator, and Stripe and Paystack read these pages before an
account goes live.

## Tax, the service charge and receipts

The tax rates the platform launches with (every Canadian province, and Nigerian
VAT) are installed by a migration, so `migrate` on an empty production database
is enough — nothing has to be seeded. It adds a rate only for a place that has
none and never changes one that exists, so it is safe on a database that was
seeded already. After that, rates change by being superseded in the admin
(Configuration → Tax rates), never edited.

The rest is a setting an administrator changes in the admin (Configuration →
Platform settings): the service charge in each currency, whether the organizer
or the platform is the seller of record, whether the service charge is taxed,
whether Quebec's QST is collected beside GST, the registration numbers printed
on receipts, and the legal name and addresses receipts show. `TAX_*` in `.env`
(`config/tax.php`), `PLATFORM_SERVICE_CHARGE_BPS` and `CONTACT_*` are the
defaults until somebody saves something different there. Every change is in the
audit log with what it replaced, and every order keeps its own copy of what
applied to it, so a receipt never changes after it is sent.

Before launch, the business has to decide and fill in: the seller of record,
whether the service charge is taxed, whether it is registered to collect QST,
and the GST/HST, QST and Nigerian VAT (TIN) numbers. None of them have values
here, because none of them are ours to know.

## A release

1. Build the images from the repository root: the API, its nginx
   (`api-web`), the site and the console.
2. Run migrations once, before anything serves the new code
   (`api-migrate` in the compose file does this). It checks the configuration
   first (`app:preflight`) and stops there if production is not ready, and
   after migrating it builds the config, route and event caches once, so a
   release that cannot cache stops there too, before anything is replaced.
3. Start or replace the containers.
4. `php artisan queue:restart`, so workers pick up the new code between jobs
   rather than mid-job.

Migrations are additive and are written to be safe to run while the previous
release is still serving.

### The config, route and event caches

Each container has a filesystem of its own, so the caches `api-migrate` builds
go when it does. `api`, `worker` and `scheduler` each build their own as they
start, after the check and before php-fpm, `queue:work` or `schedule:work`
takes over (`ops/docker/api-entrypoint.sh`), from the environment that
container was started with. One-off commands (`fiesta run --rm api php artisan
…`) build none and read the configuration fresh.

So a change to `.env.production` reaches a container when it is recreated —
`up -d` recreates each one whose environment changed — and never half-way.
`restart` is not enough: a container keeps the environment it was created
with, and rebuilds its caches from that. On a host without containers
(`ops/systemd/`), run `php artisan config:cache`, `route:cache` and
`event:cache` after each deploy and each change to `.env`, then restart php-fpm
and `queue:restart`.

Every release has a tag (the short git hash will do): the images are tagged
with it and it is baked into each as the release Sentry reports. The one that
is running is written in `.env.release`, beside `.env.production`, as
`TAG=<tag>` — before the containers are replaced, and again on a rollback — and
every compose command reads it from there (`--env-file .env.release`, after
`.env.production`). So the previous release is always one edit of that file
and an `up -d` away, and nothing started later quietly runs a different build.
The compose file refuses to run without it; on the first deploy, write it
before anything else. The checklist to run before a deploy, the commands, and
how to roll one back are in
[OPERATIONS.md](OPERATIONS.md#deploys-and-rolling-back).

## Keeping it running

[OPERATIONS.md](OPERATIONS.md) has the rest: what the uptime monitor watches
(`/up` for the load balancer, `/api/health/ready` for paging somebody), the
nightly backup and the restore drill, what is deleted on a schedule, and where
errors are reported. Each container has a healthcheck for its own part, so
`docker ps` says which one is unwell.

## What has to exist around it

- **Postgres 17.** Not negotiable: the schema uses generated columns, partial
  unique indexes, check constraints, an append-only trigger on the ledger, and
  an exclusion constraint that keeps tax-rate dates from overlapping. That one
  needs the `btree_gist` extension, which the migration creates; it ships with
  Postgres and is trusted, so the database's owner can, but check a managed
  provider allows it before the first deploy.
  A managed instance with backups somebody else tests is worth more than a
  container beside the application — with point-in-time recovery switched on,
  and this platform's own nightly backup beside it (OPERATIONS.md). The backup
  connects directly, not through a transaction-pooling proxy, and its
  `pg_dump` is 17: move the image's client (`ops/docker/api.Dockerfile`) up with
  the server, never behind it.
- **Redis**, for queues and cache.
- **Object storage** for private files — identity documents, data exports —
  or a volume that survives a redeploy. `storage/app` in the image is not it.
- **An SMTP sender** with SPF and DKIM for the sending domain. Everything this
  platform does ends in an email; a domain that fails authentication delivers
  tickets into spam folders.
- **TLS**, terminated wherever it suits, forwarding `X-Forwarded-For` and
  `X-Forwarded-Proto`, with its addresses in `TRUSTED_PROXIES` so both are
  believed — Laravel then builds https URLs and rate limits by the real
  address. See "Behind the load balancer".

## The phone app's links

The site serves `/.well-known/apple-app-site-association` and
`/.well-known/assetlinks.json`, which is how iOS and Android decide that an
event link may open the app. Both are fetched by Apple and Google from the
exact host the app names (`myfiesta.ca`) and refused if they redirect — so
whatever terminates TLS must pass those two paths straight to the site, with
no bare-to-`www.` redirect (or the reverse) in front of them. `docs/STORE.md`
has the rest, including the two values in them the operator fills in.

## Staging

Staging is the same three images with different addresses. There is nothing to
build differently and nothing to rebuild when promoting — that is what "no host
is compiled in" buys, and it is worth actually using rather than rebuilding for
production and hoping the result matches what was tested.

Two things must differ, and both are about not touching real people:

- **A different database.** Never a copy of production with real addresses in
  it: an accidental campaign send from staging writes to real buyers.
- **`MAIL_MAILER=log`, or a mailbox nobody else can reach.** With
  `SMS_DRIVER=log` for the same reason. Both need `APP_ENV=staging`:
  production refuses to start with either, which is the point.

Everything else — gateway keys in test mode, a separate Sentry environment —
follows from those two.

## Moving off the old platform

Once, on the day the old app is switched off: `legacy:import` brings its
database across and `legacy:reconcile` checks the imported money against
Stripe. The order, how to re-run either safely, and what to do with each line
of the report are in [CUTOVER.md](CUTOVER.md).

## When something is wrong

- **`api-migrate` fails, or the API containers exit as they start**, with
  "Not ready" (the migration and php-fpm) or "Refusing to run in production
  until these are fixed" (the worker and the scheduler). The lines after it
  name each variable; see "It will not start half-configured".
- **Everybody is rate limited together**, or "Too many sign-ups from here"
  for people who have not signed up. `TRUSTED_PROXIES` does not name the
  address the load balancer connects from, so every visitor looks like it.
- **A page is unstyled, or something on it does not load**, with a Content
  Security Policy error in the browser's console. Something new is being
  loaded from somewhere the policy does not name; see "Security headers".
- **No emails.** The worker. Check `jobs` is not growing and `failed_jobs` is
  not filling.
- **An event that says sold out but sold nothing.** The scheduler:
  `checkouts:expire` is not running, and holds from abandoned baskets are
  counting against stock.
- **The console calls `127.0.0.1:8000`.** Its `index.html` was served without
  being stamped. The entrypoint refuses to start for exactly this, so it means
  something is serving the files around the image.
- **A shared link unfurls as nothing.** `ALLOWED_HOSTS` does not include the
  host the site was asked for, so it fell back to client rendering.
- **Webhooks to organizers stop.** `webhooks:retry` is not running, or the
  endpoint has been switched off after 25 consecutive failures — which the
  console says on the Integrations screen.
- **`/api/health/ready` answers 503.** Its answer names the part that failed;
  what each means and what to do first is in
  [OPERATIONS.md](OPERATIONS.md#what-to-watch). The reason is in the API's log.
- **The console will not start, saying SENTRY_DSN is not a Sentry DSN.**
  `CONSOLE_SENTRY_DSN` is set to something that is not one. Correct it, or
  empty it to report nothing.
