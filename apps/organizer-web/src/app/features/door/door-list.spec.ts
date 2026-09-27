import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DoorList, DoorOfflineStore, OfflineScan, hashCode } from '@myfiesta/door';
import { memoryIndexedDB } from '../../../../../../packages/door/src/testing/memory-indexeddb';

/** A ticket for tonight's event, evt_1, and one for tomorrow's, evt_2. */
const TONIGHT = 'WFY7-F77K4EJW';
const TOMORROW = 'MFST-9K2L4XQ7';

/** A store on this phone's IndexedDB, as a door opens it. */
function openStore(): DoorOfflineStore {
  const store = new DoorOfflineStore();

  Object.defineProperty(store, 'supported', { value: true });

  return store;
}

/** An event's list as the server sends it: one ticket, hashed with the event's own salt. */
async function listFor(eventId: string, code: string, generatedAt: string): Promise<DoorList> {
  const salt = `salt-for-${eventId}`;

  return {
    event_id: eventId,
    salt,
    iterations: 1,
    generated_at: generatedAt,
    tickets: [
      {
        hash: await hashCode(code, salt, 1),
        status: 'valid',
        admits: 1,
        admitted_count: 0,
        holder_name: 'Ada Okoro',
        type: 'General',
      },
    ],
  };
}

/**
 * Which event the list a door decides from is for.
 *
 * Each app holds one store for every door it opens, and a door screen can be
 * left, and another event's opened, while something it asked for is still on
 * its way. Tonight's list arriving after tomorrow's door had opened used to
 * replace tomorrow's, and that door then decided against the wrong event's
 * tickets — every one of its own reading as not on the list — until the next
 * refresh.
 */
describe('the saved list a door decides from', () => {
  beforeEach(() => {
    const db = memoryIndexedDB();

    vi.stubGlobal('indexedDB', db.indexedDB);
    vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('is not replaced by a list that arrives after its door was left for another event’s', async () => {
    // Tomorrow's door has been worked on this phone before.
    await openStore().save(await listFor('evt_2', TOMORROW, '2026-09-26T18:00:00Z'));

    const store = openStore();

    // Tonight's door opened, and asked the server for a fresh list...
    await store.load('evt_1');
    const tonight = await listFor('evt_1', TONIGHT, '2026-09-26T21:05:00Z');

    // ...and was left for tomorrow's before it came back.
    await store.load('evt_2');
    await store.save(tonight);

    expect(store.summary).toMatchObject({ event_id: 'evt_2', generated_at: '2026-09-26T18:00:00Z' });
    expect((await store.decide(TOMORROW, null)).result).toBe('accepted');

    // Still saved, for tonight's door, which has it when it opens again.
    expect(await store.load('evt_1')).toMatchObject({ event_id: 'evt_1', generated_at: '2026-09-26T21:05:00Z' });
    expect((await store.decide(TONIGHT, null)).result).toBe('accepted');
  });

  it('still counts a scan waiting to be sent against a list saved for a door already left', async () => {
    const store = openStore();
    const letIn: OfflineScan = {
      client_id: '6f1c2a4e-3b7d-4c55-9a0e-2d8f1b3c4a5e',
      event_id: 'evt_1',
      code: TONIGHT,
      party: null,
      offline_result: 'accepted',
      scanned_at: '2026-09-26T21:00:00Z',
    };

    await store.load('evt_1');
    await store.enqueue(letIn);
    await store.load('evt_2');

    // The server's list, fetched before it heard about the scan.
    await store.save(await listFor('evt_1', TONIGHT, '2026-09-26T21:05:00Z'));

    // Opened again, and the same ticket shown again.
    await store.load('evt_1');

    expect((await store.decide(TONIGHT, null)).result).toBe('duplicate');
  });

  it('is not the last door’s while the next door, with no list saved, is being opened', async () => {
    await openStore().save(await listFor('evt_1', TONIGHT, '2026-09-26T21:05:00Z'));

    const store = openStore();

    // Tonight's door opened and left again before its list had been read, for
    // tomorrow's, which has never had one: the read that has no list to find
    // answers first.
    await Promise.all([store.load('evt_1'), store.load('evt_2')]);

    expect(store.ready).toBe(false);
    expect(store.summary).toBeNull();
    expect((await store.decide(TONIGHT, null)).result).toBe('not_found');
  });
});

/**
 * A table's ticket, decided from the saved list with no signal.
 *
 * Scanned with no number it used to count the whole table in, so the first of
 * four holding it up let the other three in later unscanned. The list carries
 * how many a ticket admits and how many are in, and the door asks.
 */
describe('a table on the saved list', () => {
  const TABLE = 'TBLE-ACDEFHJK';

  beforeEach(() => {
    const db = memoryIndexedDB();

    vi.stubGlobal('indexedDB', db.indexedDB);
    vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  async function withTable(): Promise<DoorOfflineStore> {
    const store = openStore();
    const list = await listFor('evt_1', TABLE, '2026-09-26T21:05:00Z');

    list.tickets[0] = { ...list.tickets[0], admits: 4, type: 'Table of 4' };
    await store.save(list);

    return store;
  }

  it('counts nobody in on the question, and exactly the number the door gives after it', async () => {
    const store = await withTable();

    const asked = await store.decide(TABLE, null);

    expect(asked).toMatchObject({ result: 'choose_party', accepted: false, admitted: 0, remaining: 4 });
    // Asked again, it is still four: the question counted nobody.
    expect((await store.decide(TABLE, null)).remaining).toBe(4);

    // One of them now.
    expect(await store.decide(TABLE, 1)).toMatchObject({ accepted: true, admitted: 1, remaining: 3 });

    // The other three later: asked about again, and let in together.
    expect(await store.decide(TABLE, null)).toMatchObject({ result: 'choose_party', remaining: 3 });
    expect(await store.decide(TABLE, 3)).toMatchObject({ accepted: true, admitted: 3, remaining: 0 });
    expect((await store.decide(TABLE, null)).result).toBe('duplicate');
  });

  it('never queues the question, which the server would refuse with every scan beside it', async () => {
    const store = await withTable();

    await store.enqueue({
      client_id: '6f1c2a4e-3b7d-4c55-9a0e-2d8f1b3c4a5e',
      event_id: 'evt_1',
      code: TABLE,
      party: null,
      offline_result: 'choose_party',
      scanned_at: '2026-09-26T21:10:00Z',
    });

    expect(await store.pending('evt_1')).toEqual([]);
  });
});
