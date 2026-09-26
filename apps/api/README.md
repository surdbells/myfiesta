# The API

Laravel 13 on PHP 8.3 and PostgreSQL 17. Everything the three clients read and
write, plus the admin panel platform staff work in.

## Running it

```bash
docker compose up -d                    # from the repository root: Postgres on 15432
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan storage:link                # or uploaded images 404 on a page that otherwise works
php artisan serve                       # http://127.0.0.1:8000
```

Two more processes have to run alongside it. Neither announces itself when it
is missing, which is the point of saying so here.

```bash
php artisan queue:work                  # everything the app sends
php artisan schedule:work               # everything the app does on its own
```

### The worker

`QUEUE_CONNECTION=database`, and nearly every email in this system is queued:
the ticket somebody just bought, a reminder before doors, a waitlist opening,
an announcement to an organizer's followers, the link behind a privacy request.

With no worker running, none of that fails. The rows sit in the `jobs` table
and nothing is ever sent — which is a platform that takes money and goes quiet.
A development database left running without a worker will show them piling up:

```bash
php artisan tinker --execute="echo DB::table('jobs')->count();"
```

A few go out while the request is answered instead: a password reset, a team
invitation, and the link that moves an account to a new address. Each carries
a token that exists nowhere else, and a queued email is a copy of it sitting in
`jobs`. The other emails about moving an account go with that link, so the
owner's warning never waits on a worker the link did not. That makes these the
wrong ones to test the worker with — they arrive without it — and it means the
API process itself has to reach the mail server, not only the worker.
`MailQueueingTest` fails if an email changes sides.

### Counting without watching

Event page views are a number per event per day, sent by the browser once a
visit (`POST /api/events/{slug}/views`). There is no visitor id, no cookie and
no address anywhere in `event_views` — the table has four columns and two of
them are counts. It answers "how many looked", which is a question about the
page, and it cannot answer anything about a person, which is the point.

`Services\Events\Insights` turns that, the tickets and the door into what the
console shows: conversion, attendance, where orders came from, and this night
against the organizer's last one.

## Text messages

`config/sms.php` picks one driver. With no credentials that is `log`, which
writes what it would have sent and reports success — the path runs in
development and in tests exactly as it will in production, and an account is
the only missing piece. Termii covers Nigeria, Twilio the rest.

Two messages, both asked for by buying a ticket: the link when the payment
settles, and the last reminder before the doors (anything further out stays
email-only — see `ReminderDispatcher::TEXT_WITHIN_MINUTES`). `SMS_COUNTRIES`
limits texting to where it is worth its cost. A reply of STOP posts to
`/webhooks/sms/{secret}` and suppresses that number for everything, including
its own ticket.

## The scheduler

Seven commands, and one of them touches stock:

| Command | When | What happens without it |
| ------- | ---- | ----------------------- |
| `reminders:send` | every 15 minutes | scheduled reminders never go out |
| `checkouts:expire` | every 15 minutes | abandoned baskets keep holding tickets, so an event can read as sold out that nobody bought |
| `series:extend` | 03:30 daily | a repeating event stops appearing on new dates |
| `webhooks:retry` | every minute | a delivery that failed once is never tried again |
| `webhooks:prune` | 04:00 daily | copies of what was sent to organizers' systems — buyers' names inside — pile up past their thirty days |
| `campaigns:send` | every 5 minutes | a scheduled campaign never goes out |
| `privacy:prune` | 04:30 daily | data exports sit on disk past their week, and unproved requests stay open |

In production that is one cron entry running `schedule:run` every minute, the
standard Laravel arrangement. In development `schedule:work` does the same
thing in the foreground.

## Configuration

Everything comes from the environment; nothing is compiled in.

| Key | What it decides |
| --- | --------------- |
| `DB_*` | PostgreSQL. `compose.yaml` puts it on 15432 as `myfiesta_dev`, and `.env.example` matches |
| `CORS_ALLOWED_ORIGINS` | which clients may call it — every dev port, and `https://localhost` / `capacitor://localhost` for the phone app |
| `PUBLIC_URL`, `CONSOLE_URL` | where links in emails point |
| `MAIL_*` | `log` in development: mail lands in `storage/logs` rather than anywhere real |
| `QUEUE_CONNECTION` | `database`, which means the worker above |

## What is in here

```
app/Http/Controllers/Api/      the public, attendee and door surface
app/Http/Controllers/Api/Organizer/   everything behind an organizer token
app/Services/                  the decisions: check-in, checkout, payouts, discovery
app/Filament/                  the admin panel, at /admin
app/Enums/Permission.php       the authority on what a role may do
```

**Abilities and permissions are decided by the server**, always, from what an
account actually is. A token carries `attendee`, `organizer`, or
`door:{event_id}`; `Permission` resolves what a role may do inside an
organization, and the clients are handed that list rather than deriving it.
`PermissionMirrorTest` fails if the console's copy of the list drifts.

**Money is a pair** — an amount in minor units and a currency — and prices come
from the database. Clients send quantities, never amounts.

**An order line is one of two things**: a ticket type, or an add-on sold beside
it. The database refuses both and refuses neither, and everything that counts
tickets filters to ticket lines — an add-on admits nobody, so a bottle is money
but never a person.

**An order knows where it was sold.** Online, or at a door for cash, a card or a
transfer — and a door sale carries who took it and on which pass. The platform
charges nothing on money it never touched, and the ledger records the sale and
then removes the organizer's share with a `collected` entry, because they are
holding it already.

## Tests

```bash
php artisan test
```

They run against a real PostgreSQL database, because half of what is worth
testing here is a constraint, a partial index, or a transaction.
