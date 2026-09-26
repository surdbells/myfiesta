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

Tested in `apps/organizer-web` (`door-reads.spec.ts`, and through the door
screen in `door.spec.ts`).
