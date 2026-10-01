import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DoorOfflineStore, hashCode } from '@myfiesta/door';
import { memoryIndexedDB } from '../../../../../packages/door/src/testing/memory-indexeddb';
import { Api, ApiError, DoorList, OfflineScan, ScanResult, SyncResult } from './api';
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

/** The door list as the server sends it: hashes and a name, never a code. */
const list: DoorList = {
  event_id: 'evt_1',
  salt: 'c2FsdC1mb3ItZXZ0XzE',
  iterations: 1000,
  generated_at: '2026-09-26T21:05:00Z',
  tickets: [
    { hash: 'aGFzaC1vZi1vbmU', status: 'valid', admits: 1, admitted_count: 1, holder_name: 'Ada Okoro', type: 'General' },
  ],
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
  let listsFetched: string[];
  let listReplies: (() => Promise<DoorList>)[];

  beforeEach(async () => {
    sent = [];
    replies = [];
    listsFetched = [];
    listReplies = [];

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
            doorList: async (event: string) => {
              listsFetched.push(event);

              return (listReplies.shift() ?? (async () => ({ ...list, event_id: event })))();
            },
          },
        },
      ],
    });

    offline = onThisPhone(TestBed.inject(DoorOffline));

    // Its door screen open, as it is whenever this phone sends or fetches.
    await offline.prepare('evt_1');
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  /** A door on this phone's storage — the one open now, or a new one after a restart. */
  function onThisPhone<T extends DoorOfflineStore>(door: T): T {
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

  /**
   * A sync the server has not answered yet, on a slow venue wifi: `answer`
   * is the reply arriving, `lose` the signal going before it does.
   */
  function slowReply(reply: SyncResult) {
    let answer!: () => void;
    let lose!: () => void;
    let refuse!: (field: string) => void;
    const arriving = new Promise<SyncResult>((resolve, reject) => {
      answer = () => resolve(reply);
      lose = () => reject(new ApiError('No connection. Check signal and try again.', 0));
      refuse = (field) => reject(refusal(field));
    });

    replies.push(() => arriving);

    return { answer, lose, refuse };
  }

  /** Until the phone has sent the first batch and is waiting on the server. */
  const onItsWay = () => vi.waitFor(() => expect(sent).toHaveLength(1));

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

  /**
   * The fifteen-second timer, a scan that went through online, and the door
   * screen opened again all ask for a sync, and one may still be sending. The
   * second used to be told true at once — everything sent — with the scans
   * still on their way, and still on the phone if the signal then went.
   */
  describe('asked again while a sync is still sending', () => {
    it('waits for that sync, and says the scans did not go when they did not', async () => {
      await queueOnThisPhone([good]);
      const server = slowReply(accepted(good));

      const first = offline.sync('evt_1');

      await onItsWay();

      const second = offline.sync('evt_1');

      server.lose();

      expect(await second).toBe(false);
      expect(await first).toBe(false);
      expect(await queued()).toEqual([good]);
      expect(offline.pendingCount()).toBe(1);
      // Waited on, not sent a second time alongside.
      expect(sent).toHaveLength(1);
    });

    it('says they went only once the server has answered for them', async () => {
      await queueOnThisPhone([good]);
      const server = slowReply(accepted(good));

      void offline.sync('evt_1');
      await onItsWay();

      let answered: boolean | undefined;
      const second = offline.sync('evt_1').then((went) => (answered = went));

      await new Promise((resolve) => setTimeout(resolve));

      expect(answered).toBeUndefined();

      server.answer();

      expect(await second).toBe(true);
      expect(await queued()).toEqual([]);
      expect(offline.pendingCount()).toBe(0);
      expect(sent).toHaveLength(1);
    });

    it('lets a door screen opened again mid-sync fetch its list once the scans have gone', async () => {
      await queueOnThisPhone([good]);
      const server = slowReply(accepted(good));

      // Sending when the door screen was left for the events list...
      void offline.sync('evt_1');
      await onItsWay();

      // ...and what the screen does when it is opened again: count what is
      // waiting, send it, then fetch the list.
      await offline.prepare('evt_1');
      const arriving = offline.sync('evt_1').then(() => offline.refreshList('evt_1'));

      server.answer();
      await arriving;

      // Told the queue had gone while it had not, it found a scan still
      // waiting and fetched nothing, and the phone kept the old list.
      expect(listsFetched).toEqual(['evt_1']);
      expect(offline.listCount()).toBe(1);
    });
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

  /**
   * One door screen at a time, but what the last one asked the server for can
   * still be on its way when the next is opened: its list, or a sync on a slow
   * wifi. What comes back belongs to the door that was left, and is said there
   * when it opens again — never on the door open now, which used to be told
   * another event's list, count and problems as if they were its own.
   */
  describe('a door left for another event’s while it was still waiting on the server', () => {
    const TONIGHT = 'WFY7-F77K4EJW';
    const TOMORROW = 'MFST-9K2L4XQ7';

    /** An event's list as the server sends it, hashed with the event's own salt. */
    async function listFor(eventId: string, generatedAt: string, ...codes: string[]): Promise<DoorList> {
      const salt = `salt-for-${eventId}`;

      return {
        event_id: eventId,
        salt,
        iterations: 1,
        generated_at: generatedAt,
        tickets: await Promise.all(
          codes.map(async (code) => ({
            hash: await hashCode(code, salt, 1),
            status: 'valid',
            admits: 1,
            admitted_count: 0,
            holder_name: 'Ada Okoro',
            type: 'General',
          })),
        ),
      };
    }

    /** A list the server has not sent yet: `answer` is it arriving. */
    function slowList(reply: DoorList) {
      let answer!: () => void;
      const arriving = new Promise<DoorList>((resolve) => (answer = () => resolve(reply)));

      listReplies.push(() => arriving);

      return { answer };
    }

    it('keeps deciding from tomorrow’s list, and says so, when tonight’s arrives late', async () => {
      // Tomorrow's door has been worked on this phone before.
      await onThisPhone(new DoorOfflineStore()).save(
        await listFor('evt_2', '2026-09-26T18:00:00Z', TOMORROW, 'KQ4M-7ZP2XC9D'),
      );

      const server = slowList(await listFor('evt_1', '2026-09-26T21:05:00Z', TONIGHT));
      const refreshing = offline.refreshList('evt_1');

      await vi.waitFor(() => expect(listsFetched).toEqual(['evt_1']));
      await offline.prepare('evt_2');

      server.answer();
      await refreshing;

      expect(offline.listCount()).toBe(2);
      expect(offline.listUpdatedAt()).toEqual(new Date('2026-09-26T18:00:00Z'));
      // Tonight's list would read every one of tomorrow's tickets as not on it.
      expect((await offline.decide(TOMORROW, null)).result).toBe('accepted');

      // Saved all the same, for tonight's door, which has it when it opens again.
      await offline.prepare('evt_1');

      expect(offline.listCount()).toBe(1);
      expect(offline.listUpdatedAt()).toEqual(new Date('2026-09-26T21:05:00Z'));
    });

    it('does not say the last door’s list is saved on a door that has none', async () => {
      await offline.refreshList('evt_1');

      expect(offline.listCount()).toBe(1);

      await offline.prepare('evt_2');

      expect(offline.listCount()).toBe(0);
      expect(offline.listUpdatedAt()).toBeNull();
    });

    it('counts the scans waiting from the door that is open, not from the one it left mid-sync', async () => {
      await queueOnThisPhone([good, later]);
      await offline.prepare('evt_1');

      const server = slowReply(accepted(good, later));
      const sending = offline.sync('evt_1');

      await onItsWay();
      // Left for tomorrow's door, which has made no scans.
      await offline.prepare('evt_2');

      server.lose();
      await sending;

      // "Sending 2 scans made while offline", said on a door that made none.
      expect(offline.pendingCount()).toBe(0);

      // And tomorrow's list not fetched while they waited.
      await offline.refreshList('evt_2');

      expect(listsFetched).toEqual(['evt_2']);

      // Tonight's, still waiting, when its door opens again.
      await offline.prepare('evt_1');

      expect(offline.pendingCount()).toBe(2);
    });

    it('says what the server found wrong with tonight’s scans on tonight’s door', async () => {
      await queueOnThisPhone([good]);

      const server = slowReply({
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
      });
      const sending = offline.sync('evt_1');

      await onItsWay();
      await offline.prepare('evt_2');

      server.answer();
      await sending;

      expect(offline.conflicts()).toEqual([]);

      await offline.prepare('evt_1');

      expect(offline.conflicts()).toMatchObject([{ client_id: good.client_id, conflict: 'admitted_invalid' }]);
    });

    it('says who went in unrecorded on tonight’s door, where only its own staff can dismiss it', async () => {
      const halfAPerson: OfflineScan = { ...later, party: 1.5 };

      await queueOnThisPhone([good, halfAPerson]);

      const server = slowReply(accepted(good));
      const sending = offline.sync('evt_1');

      await onItsWay();
      await offline.prepare('evt_2');

      server.refuse('scans.1.party');
      await sending;

      expect(offline.unrecorded()).toEqual([]);

      // Tapped on tomorrow's door, which has nothing of its own to dismiss.
      await offline.dismissUnrecorded();
      await offline.prepare('evt_1');

      expect(offline.unrecorded()).toEqual([halfAPerson]);
    });
  });

  /**
   * A table's ticket, with no signal. The list carries how many it admits and
   * how many are in, so the phone asks how many are here as the server does —
   * and the server reads a queued scan with no number as the whole table,
   * which is what phones from before the question meant by it.
   */
  describe('a ticket for more than one', () => {
    const TABLE = 'TBLE-ACDEFHJK';

    beforeEach(async () => {
      const salt = 'salt-for-evt_1';

      await offline.save({
        event_id: 'evt_1',
        salt,
        iterations: 1,
        generated_at: '2026-09-26T21:05:00Z',
        tickets: [
          {
            hash: await hashCode(TABLE, salt, 1),
            status: 'valid',
            admits: 4,
            admitted_count: 0,
            holder_name: 'Chidi Nwosu',
            type: 'Table of 4',
          },
        ],
      });
    });

    it('queues nothing for the question, and the answer with how many went in', async () => {
      const asked = await offline.decideAndQueue('evt_1', TABLE, null, good.client_id);

      expect(asked).toMatchObject({ result: 'choose_party', accepted: false, remaining: 4 });
      // Nobody went in and nobody was turned away: nothing for the server.
      expect(await queued()).toEqual([]);
      expect(offline.pendingCount()).toBe(0);

      const two = await offline.decideAndQueue('evt_1', TABLE, 2, later.client_id);

      expect(two).toMatchObject({ accepted: true, admitted: 2, remaining: 2 });
      expect(await queued()).toMatchObject([{ client_id: later.client_id, code: TABLE, party: 2, offline_result: 'accepted' }]);
    });

    it('queues the last of the table with its number, not with none', async () => {
      await offline.decideAndQueue('evt_1', TABLE, 3, good.client_id);

      const last = await offline.decideAndQueue('evt_1', TABLE, null, later.client_id);

      // No question for one place; but no number would read, on the server,
      // as however many it has left.
      expect(last).toMatchObject({ result: 'accepted', admitted: 1, remaining: 0 });
      expect((await queued()).map((scan) => scan.party)).toEqual([3, 1]);
    });
  });

  /*
   * It let them in. A ticket given back for its money still opened this door
   * wherever the signal was down, while its place went on sale again; the
   * server has always turned it away.
   */
  describe('a ticket handed back for resale', () => {
    const LISTED = 'LSTD-ACDEFHJK';

    beforeEach(async () => {
      const salt = 'salt-for-evt_1';

      await offline.save({
        event_id: 'evt_1',
        salt,
        iterations: 1,
        generated_at: '2026-09-26T21:05:00Z',
        tickets: [
          {
            hash: await hashCode(LISTED, salt, 1),
            status: 'listed',
            admits: 4,
            admitted_count: 0,
            holder_name: 'Chidi Nwosu',
            type: 'Table of 4',
          },
        ],
      });
    });

    it('is turned away with no signal, as the server turns it away, and the refusal goes with the next sync', async () => {
      const outcome = await offline.decideAndQueue('evt_1', LISTED, 2, good.client_id);

      expect(outcome).toMatchObject({
        result: 'void',
        accepted: false,
        admitted: 0,
        message: 'This ticket was handed back and is waiting to be resold.',
      });
      expect(await queued()).toMatchObject([{ client_id: good.client_id, code: LISTED, offline_result: 'void' }]);
    });
  });
});
