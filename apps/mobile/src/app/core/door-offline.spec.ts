import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { memoryIndexedDB } from '../../../../../packages/door/src/testing/memory-indexeddb';
import { Api, ApiError, OfflineScan, ScanResult, SyncResult } from './api';
import { DoorOffline } from './door-offline';

const admitted: ScanResult = {
  result: 'accepted',
  accepted: true,
  admitted: 1,
  remaining: 0,
  message: 'Admitted.',
  ticket: { holder_name: 'Ada Okoro', type: 'General', admits: 1, admitted_count: 1 },
};

const good: OfflineScan = {
  client_id: '6f1c2a4e-3b7d-4c55-9a0e-2d8f1b3c4a5e',
  event_id: 'evt_1',
  code: 'WFY7-F77K4EJW',
  party: null,
  offline_result: 'accepted',
  scanned_at: '2026-09-26T21:00:00Z',
};

// Read before this door checked what it read: longer than any ticket code.
const tooLong: OfflineScan = {
  ...good,
  client_id: '0b9e8d7c-6a5f-4e3d-8c2b-1a0f9e8d7c6b',
  code: `HTTPS://EXAMPLE.COM/${'X'.repeat(40)}`,
  offline_result: 'not_found',
  scanned_at: '2026-09-26T21:01:00Z',
};

const later: OfflineScan = {
  ...good,
  client_id: '9a8b7c6d-5e4f-4a3b-9c2d-1e0f9a8b7c6d',
  code: 'MFST-9K2L4XQ7',
  scanned_at: '2026-09-26T21:02:00Z',
};

/** The API refusing a batch whole, naming the field it would not take. */
function refusal(field: string): ApiError {
  return new ApiError('The given data was invalid.', 422, { [field]: ['Not valid.'] });
}

/**
 * The phone's door when the signal comes back.
 *
 * The server refuses a batch of offline scans whole if one scan in it does not
 * validate. This phone used to send the same batch again every fifteen seconds
 * all night: a single code read off a poster held every scan behind it, and
 * the ticket list waited on them. It gives up on the scans the server can
 * never take now, by the console's rules, out of `@myfiesta/door`.
 *
 * The queue is on the phone's own IndexedDB, in memory here, rather than the
 * store's methods mocked: what matters is what the phone still holds once the
 * app has been killed and opened again.
 */
describe('DoorOffline, sending what was scanned without signal', () => {
  let offline: DoorOffline;
  let sent: OfflineScan[][];
  let replies: (() => Promise<SyncResult>)[];

  beforeEach(() => {
    sent = [];
    replies = [];

    const db = memoryIndexedDB();

    vi.stubGlobal('indexedDB', db.indexedDB);
    vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);

    TestBed.configureTestingModule({
      providers: [
        {
          provide: Api,
          useValue: {
            syncScans: async (_event: string, scans: OfflineScan[]) => {
              sent.push(scans);

              return replies.shift()!();
            },
          },
        },
      ],
    });

    offline = onThisPhone(TestBed.inject(DoorOffline));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  /** A door on this phone's storage — the one open now, or a new one after a restart. */
  function onThisPhone(door: DoorOffline): DoorOffline {
    Object.defineProperty(door, 'supported', { value: true });

    return door;
  }

  /** The app killed and opened again: nothing in memory, only what the phone kept. */
  async function restarted(): Promise<DoorOffline> {
    const door = onThisPhone(TestBed.runInInjectionContext(() => new DoorOffline()));

    await door.prepare('evt_1');

    return door;
  }

  async function queueOnThisPhone(rows: OfflineScan[]): Promise<void> {
    for (const row of rows) await offline.enqueue(row);
  }

  const queued = () => offline.pending('evt_1');

  const accepted = (...scans: OfflineScan[]): SyncResult => ({
    data: scans.map((scan) => ({ ...admitted, client_id: scan.client_id, conflict: null })),
    conflicts: [],
  });

  it('drops only the scan the server can never take, and the rest go with the next sync', async () => {
    await queueOnThisPhone([good, tooLong, later]);
    replies.push(
      async () => {
        throw refusal('scans.1.code');
      },
      async () => accepted(good, later),
    );

    expect(await offline.sync('evt_1')).toBe(false);
    expect(await queued()).toEqual([good, later]);
    expect(offline.pendingCount()).toBe(2);
    // The server answered: this is not a lost connection.
    expect(offline.connectionLost()).toBe(false);

    expect(await offline.sync('evt_1')).toBe(true);
    expect(sent[1].map((scan) => scan.client_id)).toEqual([good.client_id, later.client_id]);
    expect(await queued()).toEqual([]);
    expect(offline.pendingCount()).toBe(0);
    // A refusal the server never heard about is not worth a word at the door.
    expect(offline.unrecorded()).toEqual([]);
    expect(await offline.unrecordedAdmissions('evt_1')).toEqual([]);
  });

  it('keeps every scan when what the server refused is not something a person typed or a camera read', async () => {
    await queueOnThisPhone([good, tooLong]);
    replies.push(async () => {
      throw refusal('scans.0.client_id');
    });

    expect(await offline.sync('evt_1')).toBe(false);
    // This app's own mistake; the scans wait for it to be fixed.
    expect(await queued()).toEqual([good, tooLong]);
  });

  it('keeps every scan when the connection went', async () => {
    await queueOnThisPhone([good, tooLong]);
    replies.push(async () => {
      throw new ApiError('No connection. Check signal and try again.', 0);
    });

    expect(await offline.sync('evt_1')).toBe(false);
    expect(await queued()).toEqual([good, tooLong]);
    expect(offline.connectionLost()).toBe(true);
  });

  describe('a scan it has to drop that had let somebody in', () => {
    // Two of a table let in before this door checked the number, written down as one and a half.
    const halfAPerson: OfflineScan = { ...later, party: 1.5 };

    it('says so, rather than losing them quietly', async () => {
      await queueOnThisPhone([good, halfAPerson]);
      replies.push(async () => {
        throw refusal('scans.1.party');
      });

      await offline.sync('evt_1');

      // Never sent again, and not holding up what can be.
      expect(await queued()).toEqual([good]);
      expect(offline.pendingCount()).toBe(1);
      expect(offline.unrecorded()).toEqual([halfAPerson]);

      await offline.dismissUnrecorded();

      expect(offline.unrecorded()).toEqual([]);
    });

    it('still says so after the app is killed before anybody looked, until door staff have read it', async () => {
      await queueOnThisPhone([good, halfAPerson]);
      replies.push(async () => {
        throw refusal('scans.1.party');
      });

      await offline.sync('evt_1');

      // Evicted in the background, or swiped away, with the panel unread.
      const reopened = await restarted();

      expect(reopened.unrecorded()).toEqual([halfAPerson]);
      expect(reopened.pendingCount()).toBe(1);

      await reopened.dismissUnrecorded();

      expect((await restarted()).unrecorded()).toEqual([]);
      // Only the admission went; the scan still to send is still there.
      expect(await queued()).toEqual([good]);
    });
  });

  it('says so when the server had already turned away a ticket this phone then let in', async () => {
    await queueOnThisPhone([good]);
    // The scan reached the server before the signal went, and was refused —
    // used at another door since this phone fetched its list — but the answer
    // never came back, so the phone let the guest in from its list and queued
    // the scan under the same id. The server answers it with what it decided
    // the first time, and compares nothing.
    replies.push(async () => ({
      data: [
        {
          ...admitted,
          result: 'duplicate',
          accepted: false,
          admitted: 0,
          message: 'Already recorded.',
          offline_result: null,
          conflict: null,
          client_id: good.client_id,
        },
      ],
      conflicts: [],
    }));

    expect(await offline.sync('evt_1')).toBe(true);
    expect(offline.conflicts()).toMatchObject([{ client_id: good.client_id, conflict: 'admitted_invalid' }]);
    expect(offline.conflicts()[0].message).toContain('Ada Okoro was let in with no signal');
    expect(offline.conflicts()[0].message).toContain('it had already been used');
  });

  it('queues an offline decision under the id the scan was first sent with', async () => {
    const saved: OfflineScan[] = [];

    vi.spyOn(offline, 'decide').mockResolvedValue({ ...admitted, admitted: 2, remaining: 2, offline: true });
    vi.spyOn(offline, 'enqueue').mockImplementation(async (scan) => {
      saved.push(scan);
    });

    await offline.decideAndQueue('evt_1', 'WFY7-F77K4EJW', 2, good.client_id);

    // A request that timed out may still have arrived; the same id makes the
    // queued copy the same scan rather than a second person.
    expect(saved).toMatchObject([{ client_id: good.client_id, code: 'WFY7-F77K4EJW', party: 2 }]);
  });
});
