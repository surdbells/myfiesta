# Shipping the phone app

The phone app replaces a live one on both stores, so the release has to arrive
as an update to that app: the same identity, a higher number, and store
answers that match what the build actually does. This is what is already set,
what only the operator can supply, and how a release is built.

The short version, in order:

1. **Decide the iPhone bundle id.** The live iPhone app is not under the id
   this project uses. Nothing else on iOS can be finished before this.
2. **Set up the reviewers' account** (see [Deleting an account](#deleting-an-account)).
   The app can delete accounts now, and App Review will try it.
3. Fill in the two placeholders the site serves (Apple Team ID, Android
   signing fingerprint) and deploy the site.
4. Confirm the live version numbers are below ours.
5. Build, sign, and upload to a test track on each store first.
6. Answer the privacy questions the way the sections below say — iOS and
   Android differ, because only Android links Google ML Kit — and add the
   listing assets.

## Deleting an account

App Store guideline 5.1.1(v) requires an app that makes accounts to start
deleting one inside the app; Play's Data safety form asks for the same, and
for a web link to it.

- **In the app:** You → **Delete my account**, at the bottom of Settings. In
  the console: the account page, **Delete your account**. Both first show
  what goes (the sign-in on every device; name, email address and phone
  number; saved nights, follows, waitlist and guest-list places) and what
  stays (orders, tickets and payments for seven years, with nobody's name on
  them, for tax and accounting; the do-not-email and do-not-text lists; the
  record of the request itself; and, for anybody who has worked on a team,
  what they did there, in its audit trail under their name, because that
  trail is append-only and nobody can edit it), then ask for the password.
- **It is the privacy page's erasure**, not a second one: the same request,
  recorded in the admin panel's privacy requests, with the same email
  afterwards saying what was kept (`GET`/`POST /api/auth/erasure`,
  `Requests::openForAccount`). A proved address is erased at once and every
  token revoked, so the phone forgets its session. An address never proved is
  sent the page's link first, and nothing happens until it is opened: guest
  orders are found by address, and an account opened under somebody else's
  must not be able to erase theirs.
- **The only owner of an organization is refused** before anything starts,
  and shown a button to that organization's team to make somebody else an
  owner. There is no way for an owner to close an organization themselves
  yet, so the screen says to write to us (the site's `/contact`) when there
  is nobody to hand it to.
- **A myFiesta staff account is refused** too, from the app and from the
  privacy page's link alike: its admin sign-in codes go to its address, so a
  password alone must not shut it. Another administrator removes the staff
  access first, and then it is deleted like any other account.
- **Play's deletion URL** is `https://myfiesta.ca/privacy` — the erasure form
  there works for anybody, with or without the app or an account. On Android
  12 and up, with the app installed, that link opens the app first, which
  hands it straight to the browser (see
  [Links that open the app](#links-that-open-the-app)).
- **App Review signs in and may delete.** Give each store a demo account that
  is not the only owner of any organization (or it will be refused, which a
  reviewer may read as broken), with a proved address, and expect to make a
  new one after each review. Only the operator can make and keep those.

## Not in the app yet

**The session on iOS is in backups.** The session token, the tickets held on
the phone (with their codes) and an open door pass are kept through
`@capacitor/preferences`, which on iOS is `UserDefaults`. iOS copies that into
iCloud backups, into Finder backups (readable when the backup is not
encrypted) and to a new iPhone set up with Quick Start, so a restored phone
comes up signed in and a door pass outlives its night — the reasons Android's
backup is off (below). iOS has no app-wide switch for this. The fix is to keep
the token and the door pass in the Keychain with a `ThisDeviceOnly`
accessibility class (a secure-storage plugin), which is neither backed up nor
transferred, and leave only the theme and the reminders switch in
Preferences.

## What only the operator can supply

| Needed | Where it goes | Where to find it |
| ------ | ------------- | ---------------- |
| **The iPhone bundle id** — see below | `PRODUCT_BUNDLE_IDENTIFIER` in the Xcode project, and `appIDs` in `apps/web/public/.well-known/apple-app-site-association` | App Store Connect → the live app → App Information |
| **Apple Team ID** | replace `APPLE_TEAM_ID` in `apple-app-site-association` | developer.apple.com → Account → Membership details |
| **Android app signing certificate SHA-256** | replace `PLAY_APP_SIGNING_SHA256_FINGERPRINT` in `apps/web/public/.well-known/assetlinks.json` | Play Console → the app → Test and release → App integrity → App signing key certificate |
| The upload key for `myfiesta.os.ca` | signing the bundle (never in the repository) | whoever uploaded the live app; Play Console can reset a lost upload key |
| Confirmation of the live versions | `apps/mobile/package.json` if they are higher than found | Play Console → App bundle explorer (every versionCode ever uploaded, all tracks); App Store Connect → the app's builds |
| Store screenshots, the Play feature graphic (1024×500), promotional text | the store consoles | — |
| Support URL, marketing URL, support email and phone | the store consoles | the site has `/help` and `/contact`; the privacy policy is `/privacy` |
| A designed master icon, if wanted | `brand/`, then `npm run assets --workspace mobile` | the icons now are the brand mark drawn onto white, not a designer's app icon |

### The iPhone bundle id

The project ships as `myfiesta.os.ca` on both platforms, because that is the
live Android app's id. The live iPhone app is not under it. Apple's lookup
(`itunes.apple.com/lookup?id=1670401725&country=ca`, read 2026-09-26) lists
it as:

- **myFiesta**, `com.myfiestaos.myfiesta`, version **16**, released
  2026-06-02, seller *Oluwaseyi Adekoya*, minimum iOS 15.6.
- A lookup for `myfiesta.os.ca` on the App Store finds nothing.

An App Store update must carry the bundle id of the app it updates. Built as
it stands, this project would be a second, new app, and every current iPhone
user would stay on the old one. If the listing above is the operator's app:

1. Set `PRODUCT_BUNDLE_IDENTIFIER = com.myfiestaos.myfiesta;` in both App
   target configurations in `apps/mobile/ios/App/App.xcodeproj/project.pbxproj`
   (or in Xcode, Signing & Capabilities).
2. Put the same id in `appIDs` in `apple-app-site-association`:
   `"<TEAMID>.com.myfiestaos.myfiesta"`.
3. Leave `appId` in `capacitor.config.ts` and Android alone —
   `myfiesta.os.ca` is right for Play.

Capacitor does not rewrite the iOS bundle id on `cap sync`, so this stays set.
Note that the App Store listing's seller and the Play listing's developer
(*Kodek Innovations*) are different names; the release needs access to both
accounts.

## Versions

One place: `apps/mobile/package.json`.

- `version` — what people see: **18.0.0**
- `buildNumber` — what the stores compare: **1800**

`tools/stamp-mobile-version.cjs` writes them into `android/app/build.gradle`
(`versionName`, `versionCode`) and the Xcode project (`MARKETING_VERSION`,
`CURRENT_PROJECT_VERSION`), and `npm run sync` runs it. `--check` fails if
either project disagrees; `npm run check` and CI's agreements job run it.

Why these numbers, read 2026-09-26:

- **App Store:** the live app is version **16** (Apple's lookup, above).
  Apple requires a new version to be higher, compared number by number, so
  2.0.0 would be refused. 18.0.0 is above it.
- **Google Play:** the Play listing does not show a version to a script. Two
  mirrors do: APKCombo shows version **17**, versionCode **17**, 2026-06-01
  (`apkcombo.com/myfiesta/myfiesta.os.ca/old-versions/`); APKPure shows 16.
  Not authoritative. Play refuses any versionCode it has ever seen on any
  track, including internal tests no listing shows — 1800 leaves room, but
  **confirm in the App bundle explorer** before the first upload.

For every upload after that: raise `buildNumber` by one, whichever store and
whatever the version, and raise `version` when it is a release people should
notice. Then `npm run sync`.

Android's live listing also says "Android 6.0+"; this app needs Android 7
(`minSdkVersion` 24), so phones still on 6 keep the old app.

## Icons and the launch screen

`npm run assets --workspace mobile` draws every icon and launch image from
`brand/mark-source.png` (3200px) and writes them into both native projects,
plus `apps/mobile/resources/store/play-icon-512.png` for the Play listing.
Commit what it writes; builds never run it.

- The microphone alone, on white. The master's cable runs off its edge,
  which is a composition for a page, not a fingertip-sized square.
- iOS: every iPhone and iPad slot and the 1024px App Store icon, all without
  an alpha channel (App Store Connect rejects one). Launch image: the mark on
  #0b0f0c, shown aspect-fill by `LaunchScreen.storyboard`, whose own
  background is the same colour.
- Android: adaptive icons (foreground inside the 66dp safe circle, on white),
  legacy square and round icons for Android 7, all five densities. The launch
  screen is the platform's own SplashScreen API: `res/values/styles.xml`
  gives the launch theme `Theme.SplashScreen` with #0b0f0c as
  `windowSplashScreenBackground` and `drawable-*/splash_icon.png` — the mark
  alone, inside the 192dp circle Android 12 keeps of a 288dp icon with no
  background — as `windowSplashScreenAnimatedIcon`, handing over to
  `AppTheme.NoActionBar` (`postSplashScreenTheme`). Android 11 and older get
  the same picture from `core-splashscreen`, drawn as the window background
  and then held by the plugin. `AppTheme.NoActionBar` carries the same icon,
  ground and icon size, because Capacitor has already switched to it when the
  plugin installs the compat splash, and the library reads the picture from
  the theme that is on: without them it has nothing to hold the splash with,
  and the mark goes at the app's first frame. The app's own window is
  #0b0f0c too, so no version shows white between the splash and the first
  screen. The template's splash images are gone — on
  Android 12 they appeared after the system's splash, as a second one — and
  so is the Android-robot default the theme used to show.

Both phones open on the same picture: the mic on #0b0f0c. The app's first
screen replaces it only once it has rendered. `@capacitor/splash-screen`
(linked through SPM on iOS — it ships a `Package.swift` — and through Gradle
on Android; `npm run check` confirms both) holds the launch screen, and
`core/launch-screen.ts` takes it down after the first navigation's screen has
rendered, or after four seconds whatever happened. The plugin's own timer
(`launchShowDuration`, 6 seconds, in `capacitor.config.ts`) is only a
backstop, for a start where none of the app's code runs — a WebView too old
for the bundle — which would otherwise be a splash that never leaves, over a
screen that takes no touches. On iPhone the plugin lays
`LaunchScreen.storyboard` over the WebView, so the operating system's launch
screen and the plugin's are the same image and the hand-over does not show.

A first launch then opens on the introduction (`/welcome`, four pages: what
the app does for somebody going out and for somebody running events, ending
on **Get started** or **I run events**), once; You → **Show the
introduction** opens it again, and **What is myFiesta?** on the sign-in
screen does the same for somebody without an account. A launch from a link
skips it, and it waits for the next launch. A phone updating with an account
already signed in is not a first launch, and does not see it. Nothing on it
needs a network, so App Review sees it the same on any connection.

It is not `@capacitor/assets`: that pins `@capacitor/cli` 5 and `sharp` 0.32,
and installing it into this workspace brought a critical advisory (node-tar)
and an unfixable high one (libvips) into the shared lockfile. The script
reads and writes PNG with nothing but Node, and the same mark gives the same
bytes every time.

## Links that open the app

With the app installed, a tap on an event or organizer link from a chat,
Instagram or an email opens it in the app rather than the browser.

| Site address | In the app | iOS | Android 12+ | Android 7–11 |
| ------------ | ---------- | --- | ----------- | ------------ |
| `/{slug}` | `/e/{slug}` | yes | yes | browser |
| `/{slug}?ref=…` | `/e/{slug}?ref=…` | yes | yes | browser |
| `/o/{slug}` | `/o/{slug}` | yes | yes | yes |
| `/events` | Browse | yes | yes | yes |
| `/privacy`, `/help`, `/terms`, `/refunds`, … | nothing | browser | the app, which hands it to the browser | browser |
| `/tickets/{token}`, `/order/{ref}`, `/{slug}/tickets`, `/{slug}/checkout`, `/embed/…` | nothing | browser | browser | browser |

The pieces, all of which must agree:

- **The site** serves `/.well-known/apple-app-site-association` and
  `/.well-known/assetlinks.json` from `apps/web/public/.well-known/`, as
  JSON with no redirect (`apps/web/src/server.ts` names them, because the
  static handler skips dot-directories). Whatever terminates TLS in front of
  it must pass `/.well-known/` through untouched — no redirect from the bare
  domain to `www.` or back for these two paths.
- **iOS** has the Associated Domains entitlement
  (`ios/App/App/App.entitlements`), `applinks:$(APP_LINK_DOMAIN)`, where
  `APP_LINK_DOMAIN` is a build setting on the App target: `myfiesta.ca`. The
  App ID needs the Associated Domains capability; with automatic signing
  Xcode adds it.
- **Android** has an `autoVerify` intent filter on `MainActivity` for the host
  in `android/gradle.properties` (`appLinkHost=myfiesta.ca`). A staging build
  passes `-PappLinkHost=…`.
- **The app** decides in `apps/mobile/src/app/core/deep-links.ts`, which knows
  the site's own pages. A link it has a screen for opens there; any other
  address on the site goes on to the system browser; a link to anywhere else
  is ignored. It takes the site's host from the `site-base` meta tag, which
  `npm run sync` stamps from `PUBLIC_URL` (`tools/stamp-mobile-api-base.cjs`);
  without it, it guesses from the API address with its `api.` dropped, and
  the stamp refuses an https API that is not `api.<site>` with no
  `PUBLIC_URL` to say where the site is.
- **A promoter's ref** rides through: `/{slug}?ref=…` opens `/e/{slug}?ref=…`,
  whose Get tickets button opens the site's `/{slug}/tickets?ref=…`, and the
  ticket page keeps the ref for the order as the site's event page does. It
  is what credits the promoter, gives their discount and opens their presale
  tiers.

What the Android manifest cannot say:

- An event slug is "one path segment", which only Android 12's advanced
  pattern can express; older phones open event links in the browser.
- It cannot leave out the site's one-word pages (`/privacy`, `/help`,
  `/refunds`), so on Android 12 and up those open the app, which passes them
  straight to the browser — the deletion link reaches the form. iOS excludes
  them in `apple-app-site-association` and never opens the app.
  Android 15's `uri-relative-filter-group` blocks do not help: they are tried
  only when no ordinary path in the filter matched, so they cannot take
  anything out of the one-word pattern, and moving every path into groups
  would leave Android 14 and older, which ignore groups, claiming the whole
  site, the checkout included.
- When the browser has not connected its Custom Tabs service yet, or has
  none, the hand-off goes out as an ordinary link and Android sends it back
  to the app. The app opens a given page in the browser once in a few
  seconds, so that ends with the app open where it was rather than a loop.
  Check with the `/privacy` link on the test track, and that a promoter link
  opens the event screen and its Get tickets page still shows `?ref=`.

Also true: a door-pass link lives on the console's domain
(`/door-pass/{secret}`), not the site's, so it opens the console in the
browser. The app has a screen for it; making it open there needs the same two
files on the console's domain and that domain added to both platforms. On
Android 11 and older every autoVerify host must verify or none do, so do not
add the console's host until it serves `assetlinks.json`.

The checkout does not come back to the app. The app opens it in the system
browser on purpose and it ends there, on the site's order page, which the
buyer closes. There is no custom URL scheme, and the checkout, ticket and
order pages are deliberately not claimed — on Android, a claimed checkout
page could open the app on top of its own checkout.

### Checking it

After the site is deployed with the real values:

```bash
curl -sI https://myfiesta.ca/.well-known/apple-app-site-association   # 200, application/json, no Location
curl -s  https://myfiesta.ca/.well-known/assetlinks.json

# What Apple's CDN has cached (it is what phones read, and it lags):
curl -s https://app-site-association.cdn-apple.com/a/v1/myfiesta.ca

# Android, with a release-signed build installed:
adb shell pm verify-app-links --re-verify myfiesta.os.ca
adb shell pm get-app-links myfiesta.os.ca      # myfiesta.ca: verified
```

Google's Statement List tester
(`developers.google.com/digital-asset-links/tools/generator`) checks the
fingerprint against the package. Locally signed builds verify only if their
certificate's SHA-256 is in `assetlinks.json` too; add the upload or debug
key's fingerprint beside Play's while testing, and take it out after.

## iOS privacy

`ios/App/App/PrivacyInfo.xcprivacy` is in the App target's resources. The
App Store Connect privacy answers must match it:

| Data type | Collected | Linked to the person | Tracking | Purpose |
| --------- | --------- | -------------------- | -------- | ------- |
| Name | yes | yes | no | App Functionality |
| Email Address | yes | yes | no | App Functionality |
| Phone Number | yes (optional) | yes | no | App Functionality |
| User ID | yes | yes | no | App Functionality |
| Purchase History | yes | yes | no | App Functionality |
| Photos or Videos | yes (a profile photo, if somebody adds one; organizers' event pictures) | yes | no | App Functionality |
| Emails or Text Messages | yes (organizers' messages to guests) | yes | no | App Functionality |
| Other User Content | yes | yes | no | App Functionality |
| Other Financial Info | yes (organizers' payout account) | yes | no | App Functionality |

Not collected on iOS: location, contacts, browsing or search history,
identifiers for advertising, device ID, crash or performance data, payment
card details (checkout is on the website). Tracking: **No**. The iOS build
links no analytics, crash reporting or advertising SDK and no SDK that
collects anything; if one is added, this table and the manifest change with
it. **This is not true of Android** — its door scanner links Google ML Kit
(see the Data safety answers under Android). ML Kit is not in
`ios/App/CapApp-SPM/Package.swift`; if it is ever added there, Device ID and
Performance Data join this table and the manifest.

Required-reason APIs: UserDefaults, `CA92.1`, through `@capacitor/preferences`,
which ships no manifest of its own. Capacitor's own manifest declares none,
and no other linked plugin calls a listed API.

`Info.plist`:

- `NSCameraUsageDescription` — the door scanner, and Take Photo when somebody
  sets their profile photo (Settings) or an organizer adds an event picture.
  App Review reads this against what the app does, so a new use of the camera
  is named here and in the string. Choosing from the library goes through the
  system picker and needs no permission.
- `ITSAppUsesNonExemptEncryption` is **false**: HTTPS through the system, and
  PBKDF2 hashing of ticket codes for the offline door through the WebView's
  own WebCrypto. Neither is encryption the export rules ask about, and the
  key saves answering the question on every upload.

No other plugin needs a usage string: local notifications ask through the
system prompt, and sharing sends CSV text, never an image to the photo
library.

## Android

- **Backup: off.** `allowBackup="false"`, with `dataExtractionRules`
  (Android 12+) and `fullBackupContent` (older) both excluding everything.
  The session token lives in SharedPreferences (`CapacitorStorage`, written by
  `@capacitor/preferences`) beside the held tickets with their codes and any
  open door pass; the door's offline list is in the WebView's storage.
  Restored onto a new phone, the token is a signed-in account nobody signed
  in to and a door pass outlives its night. Nothing else the app keeps is
  worth a backup — the theme and the reminders switch are in the same file.
  On Android 12 and up `allowBackup` alone no longer stops the phone-to-phone
  transfer; the extraction rules do. iOS has no such switch and is still
  open (see [Not in the app yet](#not-in-the-app-yet)).
- **Cleartext: off**, written out in the manifest (it is already the default
  for this targetSdk) so a plugin cannot merge it back on.
- **SDK levels:** compile and target **36**. Play requires 36 for new apps and
  updates from 31 August 2026 (extension possible to 1 November 2026).
- **Permissions**, as merged: `INTERNET`, `CAMERA` (door scanner and Take
  Photo; the camera and autofocus features are declared not required, so a
  tablet without one can still install), `POST_NOTIFICATIONS` (asked for when
  reminders are switched on), and from plugins `VIBRATE`, `WAKE_LOCK`,
  `RECEIVE_BOOT_COMPLETED` (reminders survive a restart), `ACCESS_NETWORK_STATE`.
  `SCHEDULE_EXACT_ALARM`, which `@capacitor/local-notifications` asks for, is
  removed: reminders are not exact alarms and the plugin falls back without it.
- **Data safety form.** Not the iOS table as it stands: the Android build
  also carries Google's ML Kit, and Play holds the app responsible for what
  its SDKs collect.
  - The account data: the data types in the iOS table, all "collected", none
    "shared", all encrypted in transit, none optional except phone.
  - ML Kit: the door scanner reads codes with it
    (`com.google.mlkit:barcode-scanning`, linked by
    `@capacitor-mlkit/barcode-scanning`, which also links Google's code
    scanner). Google's disclosure for its Android SDKs
    (`developers.google.com/ml-kit/android-data-disclosure`) says they send
    Google device information, app information, per-installation
    identifiers, performance metrics and error codes, for diagnostics and
    usage analytics, encrypted in transit and not passed to third parties.
    Add:

    | Data type | Collected | Shared | Purpose | Optional |
    | --------- | --------- | ------ | ------- | -------- |
    | Device or other IDs | yes | no | Analytics | no |
    | App info and performance → Diagnostics | yes | no | Analytics | no |

    Only door staff scanning codes cause it, but nobody can turn it off, so
    it is not optional. Re-read Google's page before each submission; it
    lists only the SDK's latest version.
  - Deletion: yes, on request — in the app (You → Delete my account), and
    for anybody without it, the erasure request on the site's `/privacy`
    page, which is the URL to give. That erases what the API keeps, less what
    the law keeps without a name on it; what ML Kit sent is Google's.

## Building a release

Everything starts from the repository root with the production API and site
addresses:

```bash
npm install
API_BASE_URL=https://api.myfiesta.ca PUBLIC_URL=https://myfiesta.ca npm run sync --workspace mobile
```

`sync` builds the app, writes the API and site addresses and the version into
the native projects, and copies the build in. Without `API_BASE_URL` it says so
and the app talks to a developer's laptop. Without `PUBLIC_URL` the app guesses
the site from the API address by dropping `api.`, and the stamp refuses an
https API that guess cannot work for; the site address is where links open,
where the checkout is and what every shared link points at.

**Android** (Android Studio, JDK 21):

1. `npm run android --workspace mobile` opens the project.
2. Build → Generate Signed App Bundle → the upload key for `myfiesta.os.ca`.
3. Upload the `.aab` to the internal testing track, install from there, and
   check the links above before promoting it.

**iOS** (a Mac with Xcode):

1. Settle the bundle id (above).
2. `npm run ios --workspace mobile` opens the project. Signing & Capabilities:
   the team, and Associated Domains showing `applinks:myfiesta.ca`.
3. Product → Archive → Distribute App → App Store Connect. Test through
   TestFlight before submitting.
4. The project targets iPhone and iPad (`TARGETED_DEVICE_FAMILY = 1,2`), so the
   App Store wants iPad screenshots as well; making it iPhone-only is one
   build setting if the operator prefers.

Before the first submission on each store: the privacy answers (above), the
age rating questionnaire, the support and privacy policy URLs, the account
deletion link, and the listing text and screenshots. The category and the
rest of the listing carry over from the live app.
