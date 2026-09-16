# myFiesta on a phone

Angular 21 and Ionic 9 in a Capacitor 8 shell, shipping under the existing
bundle id `myfiesta.os.ca`.

One app, three modes — attendee, organizer, door — decided by the scope the
server granted, never by a build flavour. The app hides what a scope should not
see; the API refuses it. Only the second is a boundary.

## Running it

```bash
npm install                      # from the repository root: one lockfile for every app
npm start --workspace mobile     # http://localhost:4330 in a browser
```

The API it talks to is read at runtime from `<meta name="api-base">` in
`src/index.html`, so one build serves staging and production. Empty in
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

`android/` and `ios/` are committed. They carry the icons, the splash screen
and the signing configuration, which are release assets rather than build
output.

## What is in here

```
src/app/ui/        every control the app draws. Ours, not Ionic's.
src/app/core/      the API client, the session, the theme, money and time
src/app/features/  one folder per mode: auth, tickets, organizer, door, settings
```

Features may not import each other — `tools/check-feature-boundaries.sh` fails
the build if they do. Shared code goes in `core/` or `ui/`.

### The components

Ionic supplies the shell (`ion-app`, the router outlet, page transitions, safe
areas) and nothing a person looks at. Everything else is in `src/app/ui`:

| Component      | Why it is ours                                                         |
| -------------- | ---------------------------------------------------------------------- |
| `mfButton`     | finger-sized, answers a press by scaling, keeps its width while loading |
| `mf-field`     | solid rather than outlined — a hairline border disappears in a dark venue |
| `mf-sheet`     | bottom sheet: drag to dismiss, back closes it before it leaves a screen |
| `mf-select`    | a sheet that can be searched; the native select cannot be typed into    |
| `mf-screen`    | the header/scroll frame, without platform chrome                        |
| `mf-qr`        | the ticket code, drawn on the phone so it works with no signal          |
| `mf-segmented`, `mf-card`, `mf-badge`, `mf-empty`, `mf-skeleton`, `mf-toasts` | the rest of the kit |

Colours, spacing, radii and type all come from `packages/tokens`, the same
generated CSS the two web apps read. Light and dark follow the phone by
default; an explicit choice in Settings overrides it and is remembered.

Run the app at `/ui` in development to see every control in both themes.

## Tests

```bash
npm test --workspace mobile -- --watch=false
```
