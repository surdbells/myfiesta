# myFiesta on a phone

Angular 21 and Ionic 9 in a Capacitor 8 shell, shipping under the existing
bundle id `myfiesta.os.ca`.

One app, three modes — attendee, organizer, door — decided by the scope the
server granted, never by a build flavour. The app hides what a scope should not
see; the API refuses it. Only the second is a boundary.

Signing up here makes an attendee account — no organization name asked for.
Putting on an event happens in the console, on a screen wide enough to build
one; somebody signing up on a phone is going out.

Browsing needs no account. Guest checkout is the primary path on this platform,
so what's on, an event's page and its ticket prices are all open; signing in is
asked for where it is genuinely needed — the tickets somebody already holds,
and the organizer screens.

## Running it

```bash
npm install                      # from the repository root: one lockfile for every app
npm start --workspace mobile     # http://localhost:4330 in a browser
```

The API it talks to is read at runtime from `<meta name="api-base">` in
`src/index.html`, so one build serves staging and production. Nothing but the
packaging step can write it — there is no server here to stamp it while
rendering — so `npm run sync` fills it in from `API_BASE_URL`:

```bash
API_BASE_URL=https://api.myfiesta.ca npm run sync --workspace mobile
```

Without it the tag ships empty and the app falls back to the address that means
"the machine this emulator is running on", which is right for development and
is an app in a store talking to a laptop. Empty in
development, where it falls back to `http://127.0.0.1:8000` in a browser and
`http://10.0.2.2:8000` on the Android emulator — the address an emulator uses
for the machine it runs on.

The API has to allow the app's origin. In development that means adding
`http://localhost:4330` to `CORS_ALLOWED_ORIGINS`; on a device, Capacitor's
origins are `https://localhost` (Android) and `capacitor://localhost` (iOS).

## On a device

```bash
npm run sync --workspace mobile      # build, then copy into the native shells
npm run android --workspace mobile   # opens Android Studio
npm run ios --workspace mobile       # opens Xcode, on a Mac
```

Camera scanning works on Android and not on iPhone: the ML Kit plugin ships a
CocoaPods podspec and no `Package.swift`, and this project links its plugins
through SPM, so `cap sync` leaves it out without saying anything. The door says
"This phone cannot scan" and offers the code box. `npm run check` keeps the gap
visible; `docs/DECISIONS.md` has the two ways out.

`android/` and `ios/` are committed. They carry the icons, the splash screen
and the signing configuration, which are release assets rather than build
output.

## What is in here

```
src/app/ui/        every control the app draws. Ours, not Ionic's.
src/app/core/      the API client, discovery, the session, the theme, money, time
src/app/features/  browse, tickets, organizer, door, auth, settings
```

### The screens

| Screen | Route | What it is |
| ------ | ----- | ---------- |
| What's on | `/` | a swipeable carousel of featured nights, what is coming up, and what happened recently |
| Find something on | `/browse` | search with city, category and free-entry filters, paged |
| An event | `/e/:slug` | poster, when and where, ticket tiers with prices and sold-out states, gallery, organizer, a waitlist for a sold-out night, and a buy bar that hands off to the web checkout |
| An organizer | `/o/:slug` | who they are, what is on, what has been, and following them — the same address the site uses |
| Saved | `/saved` | nights kept for later, soonest first |
| Following | `/following` | organizers you hear from: opening one, and letting one go |
| Your tickets | `/tickets` | soonest first |
| A ticket | `/tickets/:id` | the QR full screen, what it admits, and sending it to somebody else |
| Your events | `/events` | organizer: upcoming and past, with arrivals |
| One night | `/events/:id` | organizer: what it took, and the guest list |
| Door | `/door` | the scanner — camera or typed code, working with or without signal — plus selling to walk-ups, opened by a door-pass link |
| You | `/settings` | name, theme, reminders, your lists, sign out |
| Getting in | `/sign-in`, `/join`, `/forgotten-password` | signing in, making an attendee account, asking for a reset link |

Buying is not rebuilt in the app: **Get tickets** opens the web checkout in the
system browser. Tickets are physical goods, so store purchase rules do not
apply, and the checkout that exists already handles both gateways, the holds,
the codes and the receipts — a second implementation would be a second set of
money bugs.

Features may not import each other — `tools/check-feature-boundaries.sh` fails
the build if they do. Shared code goes in `core/` or `ui/`.

### The components

Ionic supplies the shell (`ion-app`, the router outlet, page transitions, safe
areas) and nothing a person looks at. Everything else is in `src/app/ui`:

| Component      | Why it is ours                                                         |
| -------------- | ---------------------------------------------------------------------- |
| `mfButton`     | finger-sized, answers a press by scaling, keeps its width while loading |
| `mf-field`     | solid rather than outlined — a hairline border disappears in a dark venue |
| `mf-sheet`     | bottom sheet: drag to dismiss, back closes it before it leaves a screen, and it keeps the focus it claims |
| `mf-select`    | a sheet that can be searched, and arrowed through; the native select cannot be typed into |
| `mf-screen`    | the header/scroll frame, without platform chrome                        |
| `mf-qr`        | the ticket code, drawn on the phone so it works with no signal          |
| `mf-switch`    | on or off, drawn the same on both platforms — the native switch is the control iOS and Android differ on most |
| `mf-segmented`, `mf-card`, `mf-badge`, `mf-empty`, `mf-skeleton`, `mf-toasts` | the rest of the kit |

The door keeps working when the venue's wifi does not. The list it decides
from and the scans it makes meanwhile live in IndexedDB, and the deciding
itself is `@myfiesta/door`, shared with the console — two apps admitting people
through the same doors must not be two ideas of when to admit them. The list
carries hashed codes, never codes: enough to recognise a ticket somebody
shows, never enough to mint one, because a door phone gets lent out.

Only a lost connection falls back. A refusal is the server speaking, and a
pass that has been taken back must stop working rather than carry on deciding
for itself.

The tickets somebody holds are kept on the phone as well as fetched, because
the app promises they work with no signal and most venues are basements. A
reachable server is always the truth — a ticket transferred away has to stop
working on the phone that sent it — so the saved copy is used only when the
server cannot be reached, and never when it refuses. The screen says which of
the two you are looking at.

Reminders are scheduled on the phone rather than pushed from a server: three
hours before a night somebody holds a ticket for, with no certificates to
manage, no device token to keep in sync, and nothing needed at the moment it
fires — somebody on a bus with one bar still gets told. They are off until
switched on in Settings, and the schedule is rebuilt from the tickets on every
load, so a ticket handed to a friend stops reminding this phone.

They are not exact alarms. Firing at a precise moment regardless of Doze needs
`SCHEDULE_EXACT_ALARM`, which Google Play restricts to apps where exact alarms
are the point; a reminder three hours ahead that arrives a few minutes either
side is the same reminder.

Push notifications — "the organizer you follow announced a night" — need FCM
and APNs credentials, which are not in the repository.

A phone asking for less motion gets less: the stylesheet flattens every CSS
animation, and Ionic's page transitions — which it drives in JavaScript, where
a media query cannot reach them — are turned off at startup.

Colours, spacing, radii and type all come from `packages/tokens`, the same
generated CSS the two web apps read. Light and dark follow the phone by
default; an explicit choice in Settings overrides it and is remembered.

Run the app at `/ui` in development to see every control in both themes.

## Tests

```bash
npm test --workspace mobile -- --watch=false
```
