import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  DoorOfflineStore,
  OfflineScan,
  SyncResult,
  conflictsIn,
  hashCode,
  unrecordedAdmission,
  unsendableScans,
} from '@myfiesta/door';
import { memoryIndexedDB } from '../../../../../../packages/door/src/testing/memory-indexeddb';

const good: OfflineScan = {
  client_id: '6f1c2a4e-3b7d-4c55-9a0e-2d8f1b3c4a5e',
  event_id: 'evt_1',
  code: 'WFY7-F77K4EJW',
  party: null,
  offline_result: 'accepted',
  scanned_at: '2026-09-26T21:00:00Z',
};

// Read before the door checked what it read: longer than any ticket code.
const tooLong: OfflineScan = {
  ...good,
  client_id: '0b9e8d7c-6a5f-4e3d-8c2b-1a0f9e8d7c6b',
  code: `HTTPS://EXAMPLE.COM/${'X'.repeat(40)}`,
  offline_result: 'not_found',
  scanned_at: '2026-09-26T21:01:00Z',
};

// Typed before the door checked the number: two of a table let in, written down as one and a half.
const halfAPerson: OfflineScan = {
  ...good,
  client_id: '3c2b1a0f-9e8d-4c6b-8a5f-4e3d2c1b0a9f',
  code: 'MFST-9K2L4XQ7',
  party: 1.5,
  offline_result: 'accepted',
  scanned_at: '2026-09-26T21:02:00Z',
};

/** A store on this phone's IndexedDB, as the door opens it. */
function openStore(): DoorOfflineStore {
  const store = new DoorOfflineStore();

  Object.defineProperty(store, 'supported', { value: true });

  return store;
}

/**
 * When the server refuses a batch of scans a door made with no signal, from
 * `@myfiesta/door`, for the console and the phone alike.
 *
 * The server refuses the batch whole. Sent again unchanged, it is refused
 * again, every fifteen seconds for the rest of the night, with every scan
 * behind the bad one.
 */
describe('a batch the server refused', () => {
  beforeEach(() => {
    const db = memoryIndexedDB();

    vi.stubGlobal('indexedDB', db.indexedDB);
    vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('picks out the scans whose code or party the server named, by their place in the batch', () => {
    const batch = [good, tooLong, halfAPerson];

    expect(unsendableScans(batch, { 'scans.1.code': ['Too long.'] })).toEqual([tooLong]);
    expect(unsendableScans(batch, { 'scans.2.party': ['Must be a whole number.'] })).toEqual([halfAPerson]);
    expect(
      unsendableScans(batch, { 'scans.2.party': ['No.'], 'scans.1.code': ['No.'], 'scans.1.party': ['No.'] }),
    ).toEqual([tooLong, halfAPerson]);
  });

  it('picks out nothing for a complaint that is the app’s mistake rather than what somebody typed or read', () => {
    const batch = [good, tooLong];

    for (const errors of [
      { 'scans.0.client_id': ['Not a uuid.'] },
      { 'scans.1.offline_result': ['Not one of them.'] },
      { 'scans.0.scanned_at': ['Not a date.'] },
      { scans: ['Too many.'] },
      { 'scans.7.code': ['Not in this batch.'] },
      {},
      null,
      undefined,
    ]) {
      expect(unsendableScans(batch, errors), JSON.stringify(errors)).toEqual([]);
    }
  });

  it('stops sending those, forgets the refusal, and hands back the one that let somebody in', async () => {
    const store = openStore();

    for (const scan of [good, tooLong, halfAPerson]) await store.enqueue(scan);

    const admissions = await store.dropUnsendable([good, tooLong, halfAPerson], {
      'scans.1.code': ['Too long.'],
      'scans.2.party': ['Must be a whole number.'],
    });

    // The door has to hear that nobody else knows about the admission.
    expect(admissions).toEqual([halfAPerson]);
    expect(await store.pending('evt_1')).toEqual([good]);
    // The refusal is gone quietly; the admission is not gone at all.
    expect(await store.unrecordedAdmissions('evt_1')).toEqual([halfAPerson]);
  });

  it('keeps the admission on the phone through a reload, until door staff have read it', async () => {
    const before = openStore();

    for (const scan of [good, halfAPerson]) await before.enqueue(scan);
    await before.dropUnsendable([good, halfAPerson], { 'scans.1.party': ['Must be a whole number.'] });

    // The tab reloaded, or the app was killed, before anybody looked.
    const after = openStore();

    expect(await after.unrecordedAdmissions('evt_1')).toEqual([halfAPerson]);
    // Never sent again, and not holding up what can be.
    expect(await after.pending('evt_1')).toEqual([good]);

    await after.forget([halfAPerson.client_id]);

    expect(await openStore().unrecordedAdmissions('evt_1')).toEqual([]);
    expect(await openStore().pending('evt_1')).toEqual([good]);
  });

  it('still counts the admission against its ticket when the saved list is refreshed', async () => {
    const store = openStore();

    await store.enqueue(halfAPerson);
    await store.dropUnsendable([halfAPerson], { 'scans.0.party': ['Must be a whole number.'] });

    // The server's list, which has never heard that anybody went in on it.
    await store.save({
      event_id: 'evt_1',
      salt: 'salt',
      iterations: 1,
      generated_at: '2026-09-26T21:05:00Z',
      tickets: [
        {
          hash: await hashCode(halfAPerson.code, 'salt', 1),
          status: 'valid',
          admits: 1,
          admitted_count: 0,
          holder_name: 'Ada Okoro',
          type: 'General',
        },
      ],
    });

    expect((await store.decide(halfAPerson.code, null)).result).toBe('duplicate');
  });

  it('forgets nothing when nothing can be picked out', async () => {
    const store = openStore();
    const forget = vi.spyOn(store, 'forget');

    await store.enqueue(good);
    await store.enqueue(tooLong);

    expect(await store.dropUnsendable([good, tooLong], { 'scans.0.client_id': ['Not a uuid.'] })).toEqual([]);
    expect(forget).not.toHaveBeenCalled();
    expect(await store.pending('evt_1')).toEqual([good, tooLong]);
  });

  it('tells the door which ticket went unrecorded, and what puts it right', () => {
    const said = unrecordedAdmission(halfAPerson);

    expect(said).toContain('MFST-9K2L4XQ7');
    expect(said).toContain('still reads as unused');
    expect(said).toContain('Check it in again');
  });
});

/**
 * What the door got wrong while it had no signal, read out of the server's
 * answer to a batch.
 *
 * A scan that times out online is queued under the id it was sent with, so
 * that if it did arrive the server knows the queued copy for the same scan.
 * The server answers that copy with what it decided online, and compares
 * nothing.
 */
describe('what a synced batch got wrong', () => {
  const ticket = { holder_name: 'Ada Okoro', type: 'General', admits: 1, admitted_count: 1 };

  /** The server's answer to a scan it had already recorded online under this id. */
  function recordedOnline(scan: OfflineScan, result: string, admitted: number) {
    return {
      client_id: scan.client_id,
      result,
      accepted: admitted > 0,
      admitted,
      remaining: 0,
      message: 'Already recorded.',
      offline_result: null,
      conflict: null,
      ticket,
    };
  }

  it('says so when the server had already turned away a ticket this door then let in', () => {
    // Used at another door since this phone last fetched its list.
    const result: SyncResult = { data: [recordedOnline(good, 'duplicate', 0)], conflicts: [] };

    const [clash, ...rest] = conflictsIn([good], result);

    expect(rest).toEqual([]);
    expect(clash).toMatchObject({ client_id: good.client_id, conflict: 'admitted_invalid', offline_result: 'accepted' });
    expect(clash.message).toBe(
      'Ada Okoro was let in with no signal, but the scan had already reached the server, which turned the ticket away: it had already been used.',
    );
  });

  it('says so when the server had already let in somebody this door then turned away', () => {
    const turnedAway: OfflineScan = { ...good, offline_result: 'not_found' };
    const result: SyncResult = { data: [recordedOnline(turnedAway, 'accepted', 1)], conflicts: [] };

    const [clash] = conflictsIn([turnedAway], result);

    expect(clash).toMatchObject({ conflict: 'refused_valid', offline_result: 'not_found' });
    expect(clash.message).toContain('They can come in.');
  });

  it('adds nothing where they agree, or where the server compared them itself', () => {
    // Turned away offline on a ticket that was fine, and compared as such.
    const named = {
      ...recordedOnline(tooLong, 'accepted', 0),
      offline_result: 'not_found',
      conflict: 'refused_valid' as const,
      message: 'Admitted.',
    };
    const result: SyncResult = {
      data: [
        recordedOnline(good, 'accepted', 1),
        { ...recordedOnline(halfAPerson, 'accepted', 1), offline_result: 'accepted' },
        named,
      ],
      conflicts: [named],
    };

    expect(conflictsIn([good, halfAPerson, tooLong], result)).toEqual([named]);
  });
});
