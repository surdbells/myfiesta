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
apps/mobile          Flutter — one app, three modes: attendee, organizer, door
packages/contract    OpenAPI spec — the source of truth for both client languages
packages/tokens      Design tokens — emitted as CSS custom properties and Dart
tools/               Repository-wide checks
docs/                Decisions and reference
```

Two structural notes, because neither is obvious from the tree:

**`packages/contract` is load-bearing.** Flutter cannot consume a TypeScript
package, so there is no shared client library. The OpenAPI document is the only
thing keeping the Dart and TypeScript clients honest, and both are generated
from it. Endpoints that are not in the contract cannot be called.

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
| Flutter  | 3.47+     |
| Postgres | 17        |
| Redis    | 7         |

## Running it

```bash
# API
cd apps/api && composer install && cp .env.example .env && php artisan key:generate
php artisan serve

# Public site
cd apps/web && npm install && npm start

# Organizer console
cd apps/organizer-web && npm install && npm start

# Mobile
cd apps/mobile && flutter pub get && flutter run

# Design tokens — after editing packages/tokens/tokens.json
node packages/tokens/build.mjs
```

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

**Generated files are committed and verified.** `packages/tokens/dist` and
`apps/mobile/lib/design/tokens.dart` are outputs; edit `tokens.json` and rerun
the build. CI fails if they drift.

## Documentation

- [`docs/DECISIONS.md`](docs/DECISIONS.md) — what was decided, and what it cost
