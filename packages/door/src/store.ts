import { DoorList, DoorListTicket, OfflineScan, ScanResult, StoredList } from './types';
import { admittedAfter, decideOffline, hashCode } from './rules';
import { unsendableScans } from './sync';

const DB_NAME = 'myfiesta-door';
const DB_VERSION = 1;

/** One ticket in the saved list, keyed by event and hash. */
interface StoredTicket extends DoorListTicket {
  key: string;
  event_id: string;
}

/** A scan in the queue: waiting to be sent, or kept back as one that never can be. */
interface QueuedScan extends OfflineScan {
  /**
   * Set on an admission the server refused to take — see `dropUnsendable`.
   * Never sent again, and kept on the phone until door staff dismiss it.
   */
  unrecorded?: true;
}

/**
 * The door's memory for when the signal goes.
 *
 * Two things are kept on the phone, both in IndexedDB so they survive a
 * reload, a locked screen, and the browser being swiped away and reopened:
 *
 * - the event's ticket list, as hashes — enough to recognise a code somebody
 *   shows, never enough to show one (see DoorList on the server);
 * - the scans made while offline, waiting to be sent — and, beside them,
 *   admissions the server could not take, until door staff have been told.
 *
 * The deciding itself is in rules.ts, and is shared with the phone app: two
 * apps admitting people through the same doors must not be two ideas of when
 * to admit them.
 *
 * A plain class, with no framework in it. Each app provides it to its own
 * injector, which is what lets an Angular console and an Ionic phone app hold
 * the same one.
 */
export class DoorOfflineStore {
  private db: Promise<IDBDatabase> | null = null;

  /** The list for the event this door is working, loaded into memory. */
  private list: StoredList | null = null;
  private tickets = new Map<string, DoorListTicket>();

  /**
   * Whether this browser can hash at all.
   *
   * WebCrypto only exists in a secure context: https, or localhost. A console
   * opened over plain http on a venue's wifi has no crypto.subtle, and saying
   * so beats a list that silently never matches anything.
   */
  readonly supported = typeof indexedDB !== 'undefined' && !!globalThis.crypto?.subtle;

  // --- the list --------------------------------------------------------------

  /** Load whatever this phone already saved for the event. */
  async load(eventId: string): Promise<StoredList | null> {
    if (!this.supported) return null;

    const db = await this.open();
    const list = await request<StoredList | undefined>(
      db.transaction('lists').objectStore('lists').get(eventId),
    );

    if (!list) {
      this.list = null;
      this.tickets.clear();

      return null;
    }

    const rows = await request<StoredTicket[]>(
      db.transaction('tickets').objectStore('tickets').index('event_id').getAll(eventId),
    );

    this.list = list;
    this.tickets = new Map(rows.map((row) => [row.hash, strip(row)]));

    return list;
  }

  /**
   * Replace the saved list with a fresh one from the server.
   *
   * Replaced whole rather than merged: the server's list is the truth, and
   * scans still waiting to be sent are applied on top afterwards, so an
   * offline admission is not forgotten by a refresh that raced the sync. So
   * are admissions the server could not take: the server's list says those
   * tickets are unused, and they are not.
   */
  async save(list: DoorList): Promise<StoredList> {
    const db = await this.open();
    const tx = db.transaction(['lists', 'tickets'], 'readwrite');
    const tickets = tx.objectStore('tickets');

    // Every request queued in one synchronous burst, with no await between
    // them. An IndexedDB transaction commits as soon as it has nothing pending
    // when control returns to the event loop, and whether it survives an
    // await in between is down to each browser's microtask timing — the kind
    // of bug that passes on a laptop and fails on one phone at one door. Keys
    // are "event:hash", so one range covers this event's whole list.
    tickets.delete(IDBKeyRange.bound(`${list.event_id}:`, `${list.event_id}:￿`));

    for (const ticket of list.tickets) {
      tickets.put({ ...ticket, key: `${list.event_id}:${ticket.hash}`, event_id: list.event_id });
    }

    const stored: StoredList = {
      event_id: list.event_id,
      salt: list.salt,
      iterations: list.iterations,
      generated_at: list.generated_at,
      count: list.tickets.length,
    };

    tx.objectStore('lists').put(stored);
    await done(tx);

    this.list = stored;
    this.tickets = new Map(list.tickets.map((ticket) => [ticket.hash, { ...ticket }]));

    for (const scan of await this.queued(list.event_id)) {
      if (scan.offline_result === 'accepted') {
        await this.admitLocally(scan.code, scan.party);
      }
    }

    return stored;
  }

  get ready(): boolean {
    return this.list !== null;
  }

  get summary(): StoredList | null {
    return this.list;
  }

  // --- deciding --------------------------------------------------------------

  /**
   * Decide a scan from the saved list, the way the server would.
   *
   * Wrong event cannot be told apart offline — the list only holds this
   * event — so a ticket for another night reads as not recognised. That is
   * the one answer that differs, and the message says why.
   */
  async decide(code: string, party: number | null): Promise<ScanResult> {
    const hash = await this.hash(code);
    const ticket = hash ? (this.tickets.get(hash) ?? null) : null;
    const outcome = decideOffline(ticket, party);

    // Counted only when somebody actually went in. The rules decide; this
    // writes down what they decided.
    if (outcome.accepted && ticket) await this.admitLocally(code, party);

    return outcome;
  }

  /**
   * Count people in on the saved list.
   *
   * Also called after an online scan, so a phone that loses signal a minute
   * later does not think a ticket it just admitted is still unused.
   */
  async admitLocally(code: string, party: number | null, admittedCount?: number): Promise<void> {
    const hash = await this.hash(code);
    const ticket = hash ? this.tickets.get(hash) : undefined;

    if (!ticket || !this.list) return;

    const next = admittedCount ?? admittedAfter(ticket, party);

    ticket.admitted_count = next;
    ticket.status = next >= ticket.admits ? 'checked_in' : ticket.status;

    const db = await this.open();
    const tx = db.transaction('tickets', 'readwrite');
    tx.objectStore('tickets').put({ ...ticket, key: `${this.list.event_id}:${hash}`, event_id: this.list.event_id });
    await done(tx);
  }

  // --- the queue -------------------------------------------------------------

  async enqueue(scan: OfflineScan): Promise<void> {
    const db = await this.open();
    const tx = db.transaction('queue', 'readwrite');
    tx.objectStore('queue').put(scan);
    await done(tx);
  }

  /** The scans waiting to be sent, oldest first. */
  async pending(eventId: string): Promise<OfflineScan[]> {
    return (await this.queued(eventId)).filter((scan) => !scan.unrecorded);
  }

  /**
   * People let in with no signal on scans the server could not take, until
   * door staff dismiss them — see `dropUnsendable`. Oldest first.
   */
  async unrecordedAdmissions(eventId: string): Promise<OfflineScan[]> {
    return (await this.queued(eventId))
      .filter((scan) => scan.unrecorded)
      .map(({ unrecorded: _unrecorded, ...scan }) => scan);
  }

  /** Off the phone for good: sent and answered for, or read by door staff. */
  async forget(clientIds: string[]): Promise<void> {
    const db = await this.open();
    const tx = db.transaction('queue', 'readwrite');
    const queue = tx.objectStore('queue');
    clientIds.forEach((id) => queue.delete(id));
    await done(tx);
  }

  /**
   * Stop sending the scans in a refused batch that the server can never take,
   * so the rest go with the next sync — see `unsendableScans` for which.
   *
   * A refusal is forgotten. One that let somebody in is kept, unsent, and
   * returned: the server has no record that anybody went in on that ticket,
   * so the app says so on the door's screen. Kept on the phone rather than
   * only on the screen, because the screen does not outlive a reload, the tab
   * being discarded in the background or the app being killed, and the
   * admission would then be on nobody's record at all. It stays until door
   * staff dismiss it (`forget`), is read back with `unrecordedAdmissions`
   * when the door opens again, and still counts against its ticket when the
   * saved list is refreshed.
   */
  async dropUnsendable(
    batch: readonly OfflineScan[],
    errors: Record<string, unknown> | null | undefined,
  ): Promise<OfflineScan[]> {
    const unsendable = unsendableScans(batch, errors);
    const admissions = unsendable.filter((scan) => scan.offline_result === 'accepted');
    const refusals = unsendable.filter((scan) => scan.offline_result !== 'accepted');

    if (admissions.length > 0) await this.keepUnrecorded(admissions);
    if (refusals.length > 0) await this.forget(refusals.map((scan) => scan.client_id));

    return admissions;
  }

  /** Everything queued for the event, oldest first, so the server records scans in the order they happened. */
  private async queued(eventId: string): Promise<QueuedScan[]> {
    if (!this.supported) return [];

    const db = await this.open();
    const rows = await request<QueuedScan[]>(
      db.transaction('queue').objectStore('queue').index('event_id').getAll(eventId),
    );

    return rows.sort((a, b) => a.scanned_at.localeCompare(b.scanned_at));
  }

  /** Marked in place, so there is no moment when the admission is on the phone nowhere. */
  private async keepUnrecorded(scans: readonly OfflineScan[]): Promise<void> {
    const db = await this.open();
    const tx = db.transaction('queue', 'readwrite');
    const queue = tx.objectStore('queue');
    scans.forEach((scan) => queue.put({ ...scan, unrecorded: true } satisfies QueuedScan));
    await done(tx);
  }

  // --- plumbing --------------------------------------------------------------

  /**
   * A code hashed the way the server hashed the list: trimmed, upper-cased,
   * PBKDF2-SHA256 with the event's salt. Checked against PHP's hash_pbkdf2
   * byte for byte; if the two ever disagreed, every offline scan would read
   * as not recognised.
   */
  private async hash(code: string): Promise<string | null> {
    if (!this.list || !this.supported) return null;

    return hashCode(code, this.list.salt, this.list.iterations);
  }

  private open(): Promise<IDBDatabase> {
    this.db ??= new Promise((resolve, reject) => {
      const opening = indexedDB.open(DB_NAME, DB_VERSION);

      opening.onupgradeneeded = () => {
        const db = opening.result;

        db.createObjectStore('lists', { keyPath: 'event_id' });
        db.createObjectStore('tickets', { keyPath: 'key' }).createIndex('event_id', 'event_id');
        db.createObjectStore('queue', { keyPath: 'client_id' }).createIndex('event_id', 'event_id');
      };

      opening.onsuccess = () => resolve(opening.result);
      opening.onerror = () => reject(opening.error);
    });

    return this.db;
  }
}

/**
 * An id for a scan, minted on the phone.
 *
 * crypto.randomUUID only exists in a secure context; getRandomValues exists
 * everywhere, so a v4 id is built from it by hand rather than the scan going
 * without one.
 */
export function scanId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();

  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function strip({ key: _key, event_id: _event, ...ticket }: StoredTicket): DoorListTicket {
  return ticket;
}

function request<T>(req: IDBRequest): Promise<T> {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result as T);
    req.onerror = () => reject(req.error);
  });
}

function done(tx: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}
