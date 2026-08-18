# Contract

`openapi.yaml` is the source of truth for every call any client makes.

Flutter cannot consume a TypeScript package, so there is no shared client
library between the Angular apps and the mobile app. This document is what
replaces it — both client languages are generated from it, which is the only
thing that keeps their understanding of the API identical. An endpoint that is
not described here cannot be called.

## Generating clients

Generated output is gitignored and rebuilt on demand; never edit it, and never
commit it.

```bash
# TypeScript — for apps/web and apps/organizer-web
npx --yes @hey-api/openapi-ts \
  -i packages/contract/openapi.yaml \
  -o packages/contract/generated/ts

# Dart — for apps/mobile
dart pub global activate openapi_generator_cli
openapi-generator generate \
  -i packages/contract/openapi.yaml \
  -g dart-dio \
  -o packages/contract/generated/dart
```

## Linting

```bash
npx --yes @redocly/cli lint packages/contract/openapi.yaml
```

CI runs this on any change under `packages/contract`.

## What the shared components encode

The components block is not boilerplate — it carries decisions that are
expensive to reverse once data exists.

**`Money`** is always a pair: integer minor units plus a currency. There is no
bare amount anywhere in the API, and no floats. Currency is scoped per event,
and balances are never summed across currencies.

**`Problem`** is RFC 7807, returned with real HTTP status codes. The previous
platform answered every request with HTTP 200 and buried the outcome in the
body, which made interceptors, retries, and error boundaries useless.

**`Quote`** is the only place a total is calculated. Clients send quantities and
an optional code; prices are read from the database. Any endpoint accepting an
amount from a client is a bug — that is how the previous checkout let buyers set
their own price.

**Token scope** is described on the security scheme because it is the real
access boundary. The mobile app compiles all three modes into one bundle, so the
client cannot enforce anything; a `door:{event_id}` token must be rejected by
sales, guest-list, payout, and profile endpoints server-side.

## Status

The shared components are complete and settled. The paths are representative
rather than exhaustive — they establish the shape the remaining resources follow
as they are built out.
