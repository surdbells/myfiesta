# myFiesta

Event ticketing for Canada and Nigeria. Organizers publish events and sell
tickets through shareable links; guests buy without an account; staff scan at
the door; settlement runs against a platform commission.

This repository is the rebuild. It shares no code with the platform it replaces.

## Layout

```
apps/api             Laravel + Filament — the API and the admin console
apps/web             Angular, server-rendered — public site and guest checkout
apps/organizer-web   Angular — organizer console (the desk work)
apps/mobile          Angular + Ionic in a Capacitor shell — one app, three modes
packages/contract    OpenAPI spec — the source of truth for every client
packages/tokens      Design tokens — emitted as CSS custom properties
tools/               Repository-wide checks
docs/                Decisions and reference
```

Two structural notes, because neither is obvious from the tree:

**`packages/door` is shared on purpose.** The console and the phone app both
scan tickets, and both have to keep working when a venue's wifi does not. What
a door decides with no signal lives in one place so that two apps cannot become
two answers.

**`packages/contract` is load-bearing.** The OpenAPI document is what keeps
the clients honest about what the API actually promises, and it is where a new
endpoint is described before anything calls it. Endpoints that are not in the
contract cannot be called.

**The mobile app is one bundle with three modes.** Which mode a person gets is
decided by their token scope, not by the build — so a door-staff phone has the
organizer screens compiled in, hidden. Hiding them is a convenience; the access
boundary is enforced server-side. `tools/check-feature-boundaries.sh` keeps the
three feature directories from importing each other.

## Prerequisites

| Tool     | Version   |
| -------- | --------- |
| PHP      | 8.3+      |
| Composer | 2.10+     |
| Node     | 24+       |
| Postgres | 17        |
| Redis    | 7         |

## Running it

```bash
# API — http://127.0.0.1:8000
cd apps/api && composer install && cp .env.example .env && php artisan key:generate
php artisan serve

# …and two more processes beside it. Neither says anything when it is missing:
# with no worker every email queues and is never sent, and with no scheduler
# abandoned baskets keep holding tickets an event could have sold.
php artisan queue:work
php artisan schedule:work

# Public site — http://localhost:4320
cd apps/web && npm install && npm start

# Organizer console — http://localhost:4310
cd apps/organizer-web && npm install && npm start

# Phone app in a browser — http://localhost:4330
# (see apps/mobile/README.md for running it on a device)
npm install && npm start --workspace mobile

# Design tokens — after editing packages/tokens/tokens.json
node packages/tokens/build.mjs
```

Each app serves on a port of its own, set in its `angular.json`, so all three
run at once. Every one of those origins has to be in the API's
`CORS_ALLOWED_ORIGINS`.

## Conventions

**No credentials in the repository, ever.** Configuration comes from the
environment. A secret scan runs on every push over the full history, and it is
not advisory. The platform this replaces shipped a live Stripe secret key inside
its mobile app; that is the mistake this rule exists to prevent.

**Money is always a pair.** An amount in minor units and a currency, never a
bare number. Currency is scoped per event. Balances are per-currency and are
never summed across currencies.

**Prices come from the database.** Clients send quantities. Any endpoint that
accepts an amount from a client is a bug.

**Nothing assumes a single server.** No session state on local disk, no cache on
local disk, nothing written to the filesystem during a request that another
server would need to read. Storage is local for now and moves to Cloudflare R2
before the application scales horizontally — that must stay a config change.

**Store paths, not URLs.** A full URL in a column hardcodes the storage driver.

**Addresses are stamped at run time, not compiled in.** The site reads the API
host and the console host out of meta tags its own server fills in, so one
build serves staging and production. `npm run check` holds the two files to the
same shape — a tag the server does not fill in is a link pointing at localhost
in production, and it fails silently.

**Generated files are committed and verified.** `packages/tokens/dist` is
output; edit `tokens.json` and rerun the build. CI fails if it drifts.

## Documentation

- [`docs/DECISIONS.md`](docs/DECISIONS.md) — what was decided, and what it cost
