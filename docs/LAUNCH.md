# Before launch: what only the operator can supply

The code is finished to the point where what is left is not code. It is
accounts, keys, numbers, decisions and a lawyer, and none of it can be guessed
without being wrong in a way that costs money or trust. Each item below says
what is needed, where it goes, and which document explains it in full.

Nothing here has a placeholder that looks real. Where a value is missing the
product says so out loud: the contact and legal pages show a "still to be
filled in" notice, the association files carry names like `APPLE_TEAM_ID`, and
production refuses to start without its payment secrets
(`php artisan app:preflight`).

## Decisions

- **The iOS bundle id.** The live App Store app is `com.myfiestaos.myfiesta`
  (version 16), not `myfiesta.os.ca`, which is what Google Play and this
  project use. Shipping under the wrong id publishes a second app instead of
  updating the one people have. The two stores are also held by different
  names (App Store: Oluwaseyi Adekoya; Play: Kodek Innovations), so a release
  needs both accounts. Steps: [STORE.md](STORE.md#what-only-the-operator-can-supply).
- **Seller of record.** Organizer (the default) or platform, set in the admin
  under Configuration → Platform settings. It decides whose registration
  numbers go on receipts and whether the service charge is always taxed.
  With the organizer as seller, ticket tax is currently held back from their
  payout; decide how it reaches them or is filed on their behalf.
- **Tax on the service charge.** Off by default.
- **Quebec QST.** Off by default, so Quebec events charge 5% GST only. Switch it
  on once registered with Revenu Québec.
- **Closing an organization.** There is no button for it in the app or the
  admin; owners are told to write in. Decide who handles that and how.
- **Media and identity-document backups.** The database is backed up nightly;
  uploaded pictures and identity documents are not yet. Choose bucket
  versioning or volume snapshots ([OPERATIONS.md](OPERATIONS.md#backups)).

## Numbers and names

Set in the admin's Platform settings or in `.env.production`
([DEPLOYMENT.md](DEPLOYMENT.md#who-runs-this),
[DEPLOYMENT.md](DEPLOYMENT.md#tax-the-service-charge-and-receipts)):

- The registered legal name, company number, support and privacy inboxes,
  phone number if any, and the Canadian and Nigerian postal addresses
  (`CONTACT_*`). `MAIL_SUPPORT_ADDRESS` must be an inbox somebody reads: every
  customer email's Reply-To goes there, and so do suspended organizers.
- GST/HST, QST and Nigerian VAT (TIN) registration numbers, and the legal name
  and addresses printed on receipts.
- An accountant's confirmation of the launch tax rates
  (`TaxRateSeeder::RATES`, installed by migration) against current CRA and
  FIRS guidance.

## Legal

- A lawyer's review of the terms, privacy policy and refund policy. Every page
  says "Not yet reviewed by a lawyer" until then, deliberately.
- Whether an unticked checkbox plus a stored version and date is enough
  evidence of acceptance under Canadian and Nigerian consumer law, and whether
  IP address or browser should be kept too (the privacy page must say so
  first; today it says they are not).
- When the text of any of the three pages changes in substance, bump
  `version` in `apps/api/config/terms.php` in the same commit, so people are
  asked again.

## Payments

- Stripe and Paystack live keys and webhook secrets. Production will not start
  with any of them blank.
- The Stripe webhook endpoint subscribed to `checkout.session.completed`,
  `checkout.session.expired`, `payment_intent.payment_failed`,
  `charge.refunded`, `refund.created`, `refund.updated`, `refund.failed`,
  `charge.refund.updated`, `charge.dispute.created` and
  `charge.dispute.closed`.
- A test-mode run of a refund and a dispute on both processors before going
  live. The Stripe refund reason and the Paystack refund and dispute payloads
  follow the processors' documentation and have not been tried against live
  accounts.
- `TRUSTED_PROXIES`: the addresses the load balancer connects from
  ([DEPLOYMENT.md](DEPLOYMENT.md#behind-the-load-balancer)).

## Moving off the old platform

All in [CUTOVER.md](CUTOVER.md):

- Which Stripe account the old platform charged through, and a restricted,
  read-only key for it (`LEGACY_STRIPE_KEY`), deleted after sign-off.
- How long the old app's Checkout pages stayed open, to plan the freeze.
- A first `php artisan legacy:reconcile --limit=50` dry run. The import
  assumes the old total was the ticket price plus an 8% service charge; if
  every order comes back as an amount mismatch, that assumption is wrong and
  the import has to be redone before anything else.
- A manual comparison of the payouts the old platform already made against the
  imported settlements, before the first payout here.

## Running it

All in [OPERATIONS.md](OPERATIONS.md):

- Sentry projects and their DSNs (`SENTRY_LARAVEL_DSN`, `SITE_SENTRY_DSN`,
  `CONSOLE_SENTRY_DSN`, `MOBILE_SENTRY_DSN`), with alert rules, including a
  cron monitor for the nightly backup.
- A backup bucket with a different provider or region from the database, its
  `BACKUP_S3_*` keys, and `BACKUP_ENCRYPTION_KEY` (from
  `php artisan backup:key`), kept in a password manager — a backup nobody can
  decrypt is not a backup.
- Point-in-time recovery switched on for the managed Postgres. Nightly dumps
  alone can lose up to a day.
- An uptime monitor on the URLs listed there.
- A restore drill on a production-sized copy before launch, with the time it
  took written down.
- Where uploads live: `MEDIA_DISK=local` on the persistent volume, or `s3` for
  any S3-compatible bucket ([DEPLOYMENT.md](DEPLOYMENT.md#uploaded-pictures)).
- The first administrator: `php artisan staff:grant you@example.com admin`.
  Staff sign in to `/admin` with their password and a code sent by email.

## The phone app

All in [STORE.md](STORE.md):

- The Apple Team ID in `apps/web/public/.well-known/apple-app-site-association`
  and Play's app-signing SHA-256 fingerprint in `assetlinks.json`, without
  which links open the browser instead of the app.
- The live versions on both stores confirmed below 18.0.0 (build 1800).
- The Play upload key, store screenshots (including iPad, or the app made
  iPhone-only), Play's 1024×500 feature graphic, and the support URL, email and
  phone for the listings. A designer's master icon if the current one — the
  brand mark on white — is not what the store should show.
- Store review accounts with a confirmed address that are not the only owner of
  any organization, remade after each review, since reviewers may delete them.
- Privacy label (App Store) and Data safety (Play) answers, which must match
  the privacy manifest in the app; STORE.md has the answers to give.

## Known and chosen

Behaviour that was decided rather than left open, written down so nobody
mistakes it for a bug:

- Suspending an organization unpublishes its past events too, and lifting it
  puts back only events still to come, as asked. Its public page disappears
  while suspended.
- A payment that lands after checkout has closed is honoured while there is
  room; otherwise, or when the event is cancelled, over, taken down or its
  organizer suspended, nothing is issued and all of it goes back.
- Sales at the door do not ask for terms acceptance: the buyer is paying the
  organizer's staff in person and has no screen of ours to read.
- Staff see the last four characters of a ticket's code (••••7KQ2) in the admin,
  so they can match what a caller reads out. Organizer exports, guest lists and
  the offline door list never carry codes.
- Staff stay signed in until they sign out, change their password or lose
  their role; browsers cap the cookie at 400 days.
- On iPhone the door scans in the app's own view (ZXing), because the ML Kit
  plugin cannot be linked through Swift Package Manager yet. Android uses
  ML Kit ([DECISIONS.md](DECISIONS.md)).
