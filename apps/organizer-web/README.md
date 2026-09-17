# The organizer console

Angular 21 with signals, Tailwind v4, and `@myfiesta/ui` for the controls. A
single-page app: organizers work in it for hours at a time, and nothing here is
shared to a stranger, so there is no server rendering and nothing to unfurl.

## Running it

```bash
npm install                             # from the repository root: one lockfile
npm start --workspace organizer-web     # http://localhost:4310
```

The API address is read at runtime from `<meta name="api-base">` in
`src/index.html`, so one build serves staging and production. Empty in
development, where it falls back to `http://127.0.0.1:8000` — and that origin
has to be in the API's `CORS_ALLOWED_ORIGINS`.

## What it is

Everything an organizer does between deciding to put a night on and being paid
for it. The screens are scoped to one organization at a time, chosen in the
sidebar and sent as `X-Organization` on every request; the API checks it against
membership rather than trusting it.

| Screen | Route | What it is |
| ------ | ----- | ---------- |
| Dashboard | `/` | what is selling, what needs attention, the month's takings |
| Events | `/events` | everything upcoming and past; `new` to start one |
| One event | `/events/:id` | its numbers, and tabs for tickets, extras sold beside them, what the checkout asks, guests, the door, orders, messages, codes and pictures |
| Discount codes | `/codes` | codes across every event, and who they are attributed to |
| Orders | `/orders` | every order, with refunds |
| Payouts | `/payouts` | what is owed, payout details, and asking to be paid |
| Team | `/team` | who is on it, in what role, and invitations |
| How you appear | `/brand` | the organization's name, mark and description — what a buyer sees, and the address of your public page |
| Your account | `/account` | your own name and password |
| Door | `/scan/:id` | the scanner, for an organizer working their own door |

Getting in: `/sign-in`, `/register`, `/forgot-password`, `/reset-password`, and
`/join/:token` for somebody accepting an invitation by making an account.

## Permissions

What a role may do is decided by the API and sent with the session. The console
reads that list; it never derives it — the two drifted once already, and a
Manager saw no revenue on an event they were entitled to see.

`Permission` in `core/api.types.ts` mirrors `App\Enums\Permission` so a typo at
a call site is a compile error. `PermissionMirrorTest` on the API side reads
that file and fails if the two lists disagree.

A link that could only ever produce a 403 is not shown. Hidden rather than
disabled: a disabled "Payouts" tells door staff there is a money screen they
cannot reach, which is information they did not need.

## Tests

```bash
npm test --workspace organizer-web -- --watch=false
```
