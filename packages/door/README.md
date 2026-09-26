# The door, with no signal

A venue's wifi is a rumour. Both clients that scan tickets — the organizer
console and the phone app — have to be able to decide at the door and send what
they decided once the signal comes back, and they have to decide the *same*
thing. A door that answers differently depending on which app is holding it is
a door whose staff stop trusting either answer.

So the decision lives here, once.

```
src/types.ts    the shapes the API sends and the phone keeps
src/rules.ts    what a door decides, and the hash that finds the ticket
src/store.ts    IndexedDB: the saved list, and the queue of scans to send
src/camera.ts   the camera inside the page, and the decoder it reads with
src/reads.ts    which of the codes a camera reads the door acts on
src/party.ts    what "How many" may hold
src/sync.ts     which queued scans to give up on when the server refuses a batch,
                and what a synced batch got wrong
src/testing/    IndexedDB in memory, for both apps' specs; never in either app
```

## The rules

`decideOffline` follows the API's `CheckInService` line for line: refunded or
void is cancelled, nothing left is a duplicate, more people than places is
refused rather than quietly rounded down. `admittedAfter` says what to write
down — a phone that admits three and records two has let somebody in twice.

Both are pure functions over a ticket, which is what makes them testable
without a browser. They are tested in `apps/organizer-web`.

## The hash

The list carries hashed codes, never codes: enough to recognise a ticket
somebody shows, never enough to mint one. A door phone gets lent out for a
night, and a list of codes is a book of tickets.

The server hashes in PHP and the clients hash in WebCrypto. If those two ever
disagreed, every offline scan would read as a ticket nobody recognises — at a
door, with a queue behind it, and no signal to check with. Neither side can run
the other's runtime, so both are pinned to `packages/contract/fixtures/door-hash.json`
rather than to each other: `DoorHashFixtureTest` on the API, `door-rules.spec.ts`
in the console.

WebCrypto exists only in a secure context — https, localhost, or a Capacitor
WebView. A console opened over plain http on a venue's wifi has none, and
`DoorOfflineStore.supported` says so rather than promising an offline door that
silently never matches anything.

## Storage

IndexedDB, so the list and the queue survive a reload, a locked screen, and the
app being swiped away and reopened — all of which happen on a door phone over
one night.

The store is a plain class. Each app provides it to its own injector; this
package stays free of any framework so that both can.

## The camera

`PageCamera` is the camera inside the page: `getUserMedia` for the rear camera
into a `<video>` the screen already has, and a `BarcodeDetector` reading frames
off it five times a second at most. The browser's own detector where it reads
QR codes; everywhere else the `barcode-detector` ponyfill, which is ZXing
compiled to WebAssembly — every Safari, so every iPhone, and Firefox, and Chrome
on Windows, whose detector reads nothing.

It is here rather than in either app because an iPhone at a door is the same
Safari whichever app it opened. The console's door reads with it in any
browser; the phone app reads with it on iPhone, where ML Kit cannot be linked,
and keeps ML Kit for Android in its own `Scanner`.

The `.wasm` is imported by path with `with { loader: 'file' }`, so each app's
build copies it into its own output (`media/zxing_reader.wasm`) and the decoder
loads it from the app's own origin. Left alone, the library fetches it from
jsDelivr — and a door is where the signal goes. The console's service worker
keeps `media/` only once it has been asked for, so its door fetches the file
when the screen opens (`fetchDecoderAhead`) rather than when the camera first
starts, which may be after the signal has gone.

Tested in `apps/organizer-web` (`door-camera.spec.ts`, with a real QR code
through the real WebAssembly) and through the phone's `Scanner` in
`apps/mobile`. Both check that the file the build ships is the release the
ponyfill was built against.

## What the camera reads

The camera hands over every code it sees, several times a second. The door acts
on fewer:

- `ticketCode` lets through only what is shaped like a ticket code — letters,
  digits and dashes, 32 at most, which is all the API takes. A poster behind the
  guest is not a scan, and nor is a code made to jam the door: a door with no
  signal queues every scan, the server refuses a batch whole if one scan in it
  does not validate, and one bad scan would hold the rest on the phone all night.
- `RepeatReads` makes one ticket held up one scan. The same code is acted on
  again only after it has been out of sight for four seconds, counted from the
  last time it was seen and from the answer arriving — not from the first read,
  which let a ticket held up through a slow answer or an ID check scan twice.

Both doors read through these, the console's and the phone's: the phone's used
to act on any QR in view, and to start its four seconds from the first read.
A code the camera sees that is not a ticket's is not a scan; the phone says so
by the preview, since a door staring at a camera that does nothing learns
nothing, and the console stays quiet.

Tested in `apps/organizer-web` (`door-reads.spec.ts`, and through the door
screen in `door.spec.ts`) and through the phone's door in `apps/mobile`
(`features/door/door.spec.ts`).

## How many

Beside the code on both doors is "How many", for a table arriving in two
groups. `partySize` reads it: blank is everyone still outstanding, and anything
else is a whole number from 1 to 50 (`MOST_AT_ONCE`, the API's own limit) or a
sentence saying so. Forms here are novalidate, so the box's min and max never
stopped 0 — sent as blank, which let a whole table in — or 1.5, which a door
with no signal decided like any other number. `partyKey` keeps the decimal
point, the minus sign and the exponent a number box allows out of it as they
are typed.

Tested in `apps/organizer-web` (`door-party.spec.ts`), and through both doors'
screens.

## When the server refuses a batch

The API refuses a sync batch whole if one scan in it does not validate. One
scan it can never take would hold every scan behind it on the phone all night,
and the ticket list with them. `DoorOfflineStore.dropUnsendable` takes off the
queue only the scans whose code or party the server named — what a person typed
or a camera read — and leaves everything queued for any other complaint, which
is the app's mistake rather than the door's. The rest go with the next sync.

A scan dropped that way which let somebody in is not forgotten. The server has
no record that anybody went in on that ticket, so the app says so on the door's
screen, with the code and what puts it right (`unrecordedAdmission`). And the
scan stays on the phone, marked never to be sent, until door staff dismiss it:
a screen does not outlive a reload, a tab the browser discarded or an app that
was killed, and then the admission would be on nobody's record at all. Each
door reads them back when it opens (`unrecordedAdmissions`), and a refreshed
list still counts them against their tickets.

## What a synced batch got wrong

The server names where the door, deciding offline, and it disagree. Except for
one case: a scan sent online whose answer was lost after the server had acted,
then decided from the phone's list and queued under the same id so a table is
not let in twice. The server answers that copy with what it decided online and
compares nothing, so a guest let in from the list on a ticket the server had
just refused went through the sync unremarked. `conflictsIn` compares those on
the door's side, and adds nothing once the server says so itself.

Tested in `apps/organizer-web` (`door-sync.spec.ts`, and through the door
screen in `door.spec.ts`) and in `apps/mobile` (`core/door-offline.spec.ts`),
on the in-memory IndexedDB in `src/testing/`, so what survives a reload is
tested by reloading.
