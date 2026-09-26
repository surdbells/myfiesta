# Deploying this

Four things run: the API, the queue worker, the scheduler, and two front ends.
Two of them are invisible when missing, which is why they are named first.

`ops/docker/compose.prod.yml` is one working arrangement of all of it. It is
not the only one — this reads as a statement of what any arrangement has to
provide, and a platform-as-a-service or Kubernetes deployment needs the same
list.

## The two nobody remembers

**The queue worker.** Every email this platform sends is queued: tickets,
reminders, campaigns, announcements, password resets, the link behind a
privacy request. Without a worker an order is paid, the tickets are minted, the
ledger is written — and the buyer is told nothing at all. Nothing errors.

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

Both are in the compose file. `ops/systemd/` has the same two for a host
without containers.

## The processes

| Process | What it is | Notes |
| ------- | ---------- | ----- |
| `api` | php-fpm behind nginx | the only thing that touches the database |
| `worker` | `queue:work` | give it 90 seconds to stop: it must finish the job in its hands |
| `scheduler` | `schedule:work`, or cron running `schedule:run` | one minute |
| `site` | Node, server-rendered | it renders on a server so a shared link unfurls; that is the sales channel |
| `console` | static files behind nginx | no rendering: every page needs a session and none is ever shared |

The API image runs the first three. That is deliberate — a worker running last
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
| Phone app | stamped into the build before packaging | `API_BASE_URL` |

Two of these fail silently if forgotten, so both now refuse instead. The
console's entrypoint will not start without its addresses, and the site
refuses to render for a Host that is not on `ALLOWED_HOSTS` — the second used
to fall back to client rendering, which looks fine to a person and arrives
empty at a crawler.

`CORS_ALLOWED_ORIGINS` has to name every front end, including the two origins a
Capacitor app reports (`https://localhost` on Android, `capacitor://localhost`
on iOS). An origin missing there is a browser refusing every request with an
error that says nothing about why.

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

## A release

1. Build the three images from the repository root.
2. Run migrations once, before anything serves the new code
   (`api-migrate` in the compose file does this and then caches config, routes
   and events).
3. Start or replace the containers.
4. `php artisan queue:restart`, so workers pick up the new code between jobs
   rather than mid-job.

Migrations are additive and are written to be safe to run while the previous
release is still serving. The one thing to know: `config:cache` means a change
to `.env` does nothing until the cache is rebuilt.

## What has to exist around it

- **Postgres 17.** Not negotiable: the schema uses generated columns, partial
  unique indexes, check constraints and an append-only trigger on the ledger.
  A managed instance with backups somebody else tests is worth more than a
  container beside the application.
- **Redis**, for queues and cache.
- **Object storage** for private files — identity documents, data exports —
  or a volume that survives a redeploy. `storage/app` in the image is not it.
- **An SMTP sender** with SPF and DKIM for the sending domain. Everything this
  platform does ends in an email; a domain that fails authentication delivers
  tickets into spam folders.
- **TLS**, terminated wherever it suits, forwarding `X-Forwarded-Proto` so
  Laravel builds https URLs and rate limits by the real address.

## Staging

Staging is the same three images with different addresses. There is nothing to
build differently and nothing to rebuild when promoting — that is what "no host
is compiled in" buys, and it is worth actually using rather than rebuilding for
production and hoping the result matches what was tested.

Two things must differ, and both are about not touching real people:

- **A different database.** Never a copy of production with real addresses in
  it: an accidental campaign send from staging writes to real buyers.
- **`MAIL_MAILER=log`, or a mailbox nobody else can reach.** With
  `SMS_DRIVER=log` for the same reason.

Everything else — gateway keys in test mode, a separate Sentry environment —
follows from those two.

## When something is wrong

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
