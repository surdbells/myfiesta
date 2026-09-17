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

`QUEUE_CONNECTION=database`, and every email in this system is queued: the
ticket somebody just bought, a reminder before doors, a waitlist opening, an
announcement to an organizer's followers, a password reset.

With no worker running, none of that fails. The rows sit in the `jobs` table
and nothing is ever sent — which is a platform that takes money and goes quiet.
A development database left running without a worker will show them piling up:

```bash
php artisan tinker --execute="echo DB::table('jobs')->count();"
```

### The scheduler

Three commands, and one of them touches stock:

| Command | When | What happens without it |
| ------- | ---- | ----------------------- |
| `reminders:send` | every 15 minutes | scheduled reminders never go out |
| `checkouts:expire` | every 15 minutes | abandoned baskets keep holding tickets, so an event can read as sold out that nobody bought |
| `series:extend` | 03:30 daily | a repeating event stops appearing on new dates |

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

## Tests

```bash
php artisan test
```

They run against a real PostgreSQL database, because half of what is worth
testing here is a constraint, a partial index, or a transaction.
