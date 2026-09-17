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
