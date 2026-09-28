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
  admin; owners are told to write in. Decide who handles that and how. One
  that owes myFiesta money back cannot be closed until it is recovered or repaid.
- **Advances.** Administrators and finance can pay a payout request beyond the
  balance, with a written reason; it comes back from the organization's next
  sales, and the admin's Money → Overdrafts lists who owes what and for how
  long. Nothing caps an advance or asks a second person to approve a large one.
  Decide whether either is wanted.
- **Who reviews events, and how fast.** Nothing goes on sale until an
  administrator or support approves it in the admin (Support → Review queue,
  oldest first, with a count beside it). Somebody has to look at that queue
  every working day: organizers are told a review usually takes
  `EVENT_REVIEW_TYPICAL_WAIT` ("one working day" unless changed). New
  submissions are emailed to every administrator and support member, or to
  the addresses in `EVENT_REVIEW_NOTIFY` when a team inbox should get them
  instead. Events already on sale when this shipped were approved as they
  stood ([DECISIONS.md](DECISIONS.md)).
- **"Almost sold out" and "Only 4 left".** Set in the admin under
  Configuration → Platform settings → Almost sold out. A ticket reads almost
  sold out once something has sold and at most the larger of 5 places and
  10% of its capacity is left; an exact count is named at 10 or fewer, and
  never above (0 names none). Decide whether those suit launch — the count is
  public, and shows how an organizer's night is selling.
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
  evidence of acceptance under Canadian and Nigerian consumer law. The
  address and browser an online order came from are now kept on the order,
  with the ticket history and the processor's payment record, to answer a
  disputed payment, and deleted 18 months after the event — the privacy page
  says so (terms version `2026-09-27.2`). The lawyer should confirm that
  retention, and that keeping it through an erasure request until then is
  allowed.
- The one-line refund summary shown beside the terms box and on Stripe's
  pay button (`apps/api/resources/legal/<version>/refund-summary.txt`),
  alongside the refund policy it summarises.
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
- In Stripe's dashboard (Settings → Business → Public details): the
  shortened descriptor, the prefix every card charge starts with, set to
  the same value as `STRIPE_STATEMENT_DESCRIPTOR_PREFIX` (default
  `MYFIESTA`, 2–10 characters). Each charge adds the night's name after it
  so buyers recognise it on their statement; a dashboard prefix longer than
  the setting makes Stripe refuse checkouts for long night names.
- Also there: the terms of service URL (`https://myfiesta.ca/terms`). Until
  it is set, leave `STRIPE_COLLECT_TERMS_CONSENT` off — Stripe refuses every
  checkout that asks for its own terms box without one. With it set, switch
  the setting on and Stripe keeps its own record that the buyer ticked it.
- 3D Secure is requested as Stripe judges (`STRIPE_REQUEST_THREE_D_SECURE=automatic`).
  `any` asks on every card that supports it — fewer fraud chargebacks, one
  more step at checkout; decide once the first month's disputes are in. The
  Radar rules for 3D Secure in Stripe's dashboard apply either way.
- A test-mode payment on each processor, then `php artisan
  disputes:collect-evidence`, and a look at the `payment_evidence` row: the
  field names follow the processors' documentation and have not been read
  from a live account.
- A test-mode dispute on each processor, answered from **Money →
  Chargebacks** (docs/OPERATIONS.md, "Chargebacks"): Stripe's test card that
  opens a dispute, and Paystack's test dispute. The dispute, evidence, upload
  and resolve calls follow each processor's API reference and have not been
  tried against a live account — Paystack's in particular, whose upload
  address and resolve fields are the least documented.
- Radar rules in Stripe's dashboard, to consider once there is a month of
  sales to judge them by: ask for 3D Secure when Radar's risk is elevated
  (a payment the bank authenticated is one the bank answers for as fraud),
  block when it is highest, block a failed CVC check, and review several
  charges from one email address within an hour. None of this is in the code;
  Radar applies it before a payment reaches us.
- Stripe's own dispute tools, where the account offers them: Smart Disputes
  (Stripe answering disputes itself) and dispute prevention (Visa's Rapid
  Dispute Resolution and Order Insight, Mastercard's Ethoca alerts, which
  refund or answer an enquiry before it becomes a chargeback). Decide before
  launch whether to use them. If Stripe answers a dispute itself, the
  dispute's page shows it under "What the processor says", and Stripe will
  refuse a second answer from Submit.
- Paystack's dashboard: the statement line for the business (Paystack takes
  none per payment), and who there is emailed about disputes — Paystack's
  deadlines are days, not weeks.
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
- The store links on the website: `APP_STORE_URL` and `PLAY_STORE_URL` for the
  site container, each the app's https listing. Until one is set its button is
  hidden, and with neither the whole "myFiesta app" section on the front page
  is. The two buttons are drawn in the site's own style, because Apple's and
  Google's badges are their artwork and may not be copied from the internet.
  Once the app is listed, download the official badges from Apple's App Store
  marketing guidelines and Google Play's badge page, put them in
  `apps/web/public/badges/` (`app-store.svg`, `google-play.svg`), and swap
  each into its button in `apps/web/src/app/shared/app-promo.ts` as an image
  with the store's name as its alt text. Both companies require the badge
  unaltered and at their minimum size.
- Store review accounts with a confirmed address that are not the only owner of
  any organization, remade after each review, since reviewers may delete them.
- Privacy label (App Store) and Data safety (Play) answers, which must match
  the privacy manifest in the app; STORE.md has the answers to give. Since
  those answers were written, the API keeps the address and browser each time
  the app shows a ticket, as dispute evidence for 18 months; decide whether
  the answers should say so (nothing is worked out from the address, so no
  location is collected).

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
