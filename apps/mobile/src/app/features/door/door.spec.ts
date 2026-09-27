import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@capacitor/haptics', () => ({
  Haptics: { impact: vi.fn(async () => undefined), notification: vi.fn(async () => undefined) },
  ImpactStyle: { Light: 'LIGHT' },
  NotificationType: { Error: 'ERROR', Warning: 'WARNING' },
}));

import { Haptics } from '@capacitor/haptics';
import { Api, ApiError, ScanResult } from '../../core/api';
import { DoorOffline } from '../../core/door-offline';
import { Scanner } from '../../core/scanner';
import { SessionStore } from '../../core/session';
import { Door } from './door';

const admitted: ScanResult = {
  result: 'accepted',
  accepted: true,
  admitted: 1,
  remaining: 0,
  message: 'Admitted.',
  ticket: { holder_name: 'Ada Okoro', type: 'General', admits: 1, admitted_count: 1 },
};

/** One scan as the phone sent it. */
interface Sent {
  code: string;
  party: number | null;
  clientId: string | undefined;
}

/**
 * The phone's door, and what its camera sends.
 *
 * It used to act on any QR code in view, and to ignore the same one for four
 * seconds from the first sighting only: a long code read with no signal was
 * queued and then held every scan behind it, and a ticket held up through a
 * slow answer was sent twice. It had no "How many" either, so a table's
 * ticket let everyone in at once. It reads by the console's rules now, out of
 * `@myfiesta/door`.
 */
describe('The door, on a phone', () => {
  let sent: Sent[];
  let answer: () => Promise<ScanResult>;
  let seen: ((code: string) => void) | null;

  /** The camera, reduced to what it hands the door: every code it sees, as often as it sees it. */
  const camera = {
    running: signal(false),
    refusal: signal(null),
    engine: signal('webview'),
    supported: async () => true,
    start: async (onCode: (code: string) => void) => {
      seen = onCode;
      camera.running.set(true);
    },
    stop: async () => {
      camera.running.set(false);
    },
  };

  beforeEach(() => {
    sent = [];
    seen = null;
    answer = async () => admitted;
    camera.running.set(false);
    vi.mocked(Haptics.notification).mockClear();

    // The screen measures its header; jsdom has nothing to measure with.
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        disconnect() {}
      },
    );

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap({}) } } },
        {
          provide: SessionStore,
          useValue: {
            session: signal({ eventId: 'evt_1', eventTitle: 'Friday Night' }),
            locked: signal(false),
            clear: async () => undefined,
            signOut: async () => undefined,
          },
        },
        { provide: Scanner, useValue: camera },
        {
          provide: Api,
          useValue: {
            scan: async (_event: string, code: string, party: number | null, clientId?: string) => {
              sent.push({ code, party, clientId });

              return answer();
            },
          },
        },
      ],
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  async function open() {
    const fixture = TestBed.createComponent(Door);

    fixture.detectChanges();
    await fixture.whenStable();

    return fixture;
  }

  /** Typed into "How many" the way a person types it, through the number box Angular reads. */
  function typeParty(fixture: Awaited<ReturnType<typeof open>>, typed: string): void {
    const box = partyBox(fixture);

    box.value = typed;
    box.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function partyBox(fixture: Awaited<ReturnType<typeof open>>): HTMLInputElement {
    return fixture.nativeElement.querySelector('input[name="party"]');
  }

  /** The camera held on one code, five times a second, for `ms`. */
  async function holdUp(code: string, ms: number): Promise<void> {
    for (let held = 0; held < ms; held += 200) {
      seen!(code);
      await vi.advanceTimersByTimeAsync(200);
    }
  }

  describe('what the camera reads', () => {
    it('sends nothing for a QR code that is not a ticket’s, and says what it saw', async () => {
      vi.useFakeTimers();
      const fixture = await open();

      await fixture.componentInstance.startCamera();

      // A payment link on the guest's screen, longer than any ticket code:
      // queued with no signal, it would have held every scan behind it.
      await holdUp(`https://example.com/pay?ref=${'x'.repeat(40)}`, 2000);
      fixture.detectChanges();

      const page: HTMLElement = fixture.nativeElement;

      expect(sent).toEqual([]);
      expect(fixture.componentInstance.code()).toBe('');
      expect(page.textContent).toContain('That QR code is not a ticket.');
      expect(page.textContent).not.toContain('Do not admit');

      // Gone once it is out of view.
      await vi.advanceTimersByTimeAsync(3000);
      fixture.detectChanges();

      expect(page.textContent).not.toContain('That QR code is not a ticket.');
    });

    it('says nothing about a code behind a ticket that is being read', async () => {
      vi.useFakeTimers();
      const fixture = await open();

      await fixture.componentInstance.startCamera();

      for (let held = 0; held < 2000; held += 200) {
        seen!('WFY7-F77K4EJW');
        seen!('https://example.com/poster');
        await vi.advanceTimersByTimeAsync(200);
      }

      fixture.detectChanges();

      expect(sent.map((scan) => scan.code)).toEqual(['WFY7-F77K4EJW']);
      expect(fixture.nativeElement.textContent).not.toContain('That QR code is not a ticket.');
    });

    it('checks a ticket held up for ten seconds in once, with its party, when the answer is slow', async () => {
      vi.useFakeTimers();

      // A venue wifi taking its time.
      answer = () =>
        new Promise((resolve) =>
          setTimeout(
            () =>
              resolve({
                ...admitted,
                admitted: 2,
                remaining: 2,
                message: 'Admitted 2. 2 still to come.',
                ticket: { holder_name: 'Chidi Nwosu', type: 'Table of 4', admits: 4, admitted_count: 2 },
              }),
            4500,
          ),
        );

      const fixture = await open();

      // Two of a table of four going in now.
      typeParty(fixture, '2');
      await fixture.componentInstance.startCamera();

      // Held up through the slow answer and the ID check, well past the four
      // seconds a ticket has to be out of sight before it is read again.
      await holdUp('wfy7-f77k4ejw', 10_000);
      fixture.detectChanges();

      // A second scan would have gone with the number cleared, which asks all
      // over again about a table the door has just answered for.
      expect(sent).toHaveLength(1);
      expect(sent[0]).toMatchObject({ code: 'WFY7-F77K4EJW', party: 2 });
      expect(fixture.nativeElement.textContent).toContain('2 of 4 in — 2 still to come');
      // The number typed first is the answer: nothing is asked.
      expect(fixture.componentInstance.asking()).toBeNull();
      // Cleared once decided: it belonged to that ticket.
      expect(fixture.componentInstance.party()).toBe('');
    });

    it('reads a different ticket straight after, at once', async () => {
      vi.useFakeTimers();
      const fixture = await open();

      await fixture.componentInstance.startCamera();
      await holdUp('WFY7-F77K4EJW', 1000);

      expect(sent.map((scan) => scan.code)).toEqual(['WFY7-F77K4EJW']);

      seen!('MFST-9K2L4XQ7');
      await vi.advanceTimersByTimeAsync(0);

      expect(sent.map((scan) => scan.code)).toEqual(['WFY7-F77K4EJW', 'MFST-9K2L4XQ7']);
    });

    it('sends each scan with its own id, for the server to know it again if it is also queued', async () => {
      const fixture = await open();

      fixture.componentInstance.code.set('WFY7-F77K4EJW');
      await fixture.componentInstance.submit();

      expect(sent[0].clientId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });
  });

  describe('a typed code', () => {
    it('is not sent when no ticket could have it', async () => {
      const fixture = await open();
      const door = fixture.componentInstance;

      door.code.set('https://example.com/pay?ref=1234567890abcdefghijklmnop');
      await door.submit();
      fixture.detectChanges();

      expect(sent).toEqual([]);
      expect(fixture.nativeElement.textContent).toContain('That is not a ticket code.');
    });

    it('is sent as the server reads it', async () => {
      const fixture = await open();

      fixture.componentInstance.code.set('  wfy7-f77k4ejw ');
      await fixture.componentInstance.submit();

      expect(sent).toMatchObject([{ code: 'WFY7-F77K4EJW', party: null }]);
    });
  });

  describe('how many', () => {
    const refused = 'How many has to be a whole number from 1 to 50.';

    it('refuses a number that is not a whole number of people, rather than sending or deciding it', async () => {
      for (const typed of ['1.5', '0', '51']) {
        const fixture = await open();
        const door = fixture.componentInstance;

        door.code.set('WFY7-F77K4EJW');
        typeParty(fixture, typed);
        await door.submit();
        fixture.detectChanges();

        // Offline, 1.5 would have been decided and queued, and the server
        // would then have refused every sync with it in.
        expect(sent, typed).toEqual([]);
        expect(fixture.nativeElement.textContent, typed).toContain(refused);
        // Left where it was, to be put right and sent.
        expect(door.code(), typed).toBe('WFY7-F77K4EJW');

        fixture.destroy();
      }
    });

    it('refuses it for a ticket the camera read as well, and sends once it is put right', async () => {
      vi.useFakeTimers();
      const fixture = await open();
      const door = fixture.componentInstance;

      typeParty(fixture, '1.5');
      await door.startCamera();
      await holdUp('WFY7-F77K4EJW', 1000);
      fixture.detectChanges();

      expect(sent).toEqual([]);
      expect(fixture.nativeElement.textContent).toContain(refused);

      typeParty(fixture, '1');

      expect(fixture.nativeElement.textContent).not.toContain(refused);

      await door.submit();

      expect(sent).toMatchObject([{ code: 'WFY7-F77K4EJW', party: 1 }]);
    });

    it('takes digits only as they are typed, with a keypad of numbers and whole steps', async () => {
      const box = partyBox(await open());

      expect(box.type).toBe('number');
      expect(box.getAttribute('inputmode')).toBe('numeric');
      expect(box.getAttribute('step')).toBe('1');

      const press = (key: string) => {
        const event = new KeyboardEvent('keydown', { key, cancelable: true });

        box.dispatchEvent(event);

        return event.defaultPrevented;
      };

      expect(press('.')).toBe(true);
      expect(press('-')).toBe(true);
      expect(press('e')).toBe(true);
      expect(press('4')).toBe(false);
      expect(press('Backspace')).toBe(false);
    });
  });

  /**
   * A ticket for more than one, read with nothing in "How many".
   *
   * It used to let in everyone the ticket had left, so the first of a table
   * holding its ticket up counted the whole table in, and the rest walked in
   * later unscanned. The server asks now, and so does this door: nothing the
   * camera reads is acted on, the door says how many are here, and the scan
   * goes again with that number.
   */
  describe('a ticket for more than one', () => {
    const TABLE = 'TBLE-ACDEFHJK';
    const table = (inside: number) => ({ holder_name: 'Chidi Nwosu', type: 'Table of 4', admits: 4, admitted_count: inside });

    const asked = (inside: number): ScanResult => ({
      result: 'choose_party',
      accepted: false,
      admitted: 0,
      remaining: 4 - inside,
      message: 'This ticket admits 4. Put how many are going in now in How many, and scan it again.',
      ticket: table(inside),
    });

    const letIn = (now: number, inside: number): ScanResult => ({
      result: 'accepted',
      accepted: true,
      admitted: now,
      remaining: 4 - inside,
      message: inside === 4 ? `Admitted ${now}. That is everyone.` : `Admitted ${now}. ${4 - inside} still to come.`,
      ticket: table(inside),
    });

    /** The server's answers, one per scan, in order. */
    function answers(...replies: ScanResult[]): void {
      answer = async () => replies.shift()!;
    }

    function button(fixture: Awaited<ReturnType<typeof open>>, name: string): HTMLButtonElement | undefined {
      return [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')].find(
        (candidate) => candidate.textContent?.trim() === name,
      );
    }

    async function settle(fixture: Awaited<ReturnType<typeof open>>): Promise<void> {
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();
      fixture.detectChanges();
    }

    it('asks how many are here, reads nothing meanwhile, and sends the answer at once', async () => {
      vi.useFakeTimers();
      answers(asked(0), letIn(1, 1));

      const fixture = await open();
      const door = fixture.componentInstance;
      const page: HTMLElement = fixture.nativeElement;

      await door.startCamera();
      await holdUp(TABLE, 200);
      await settle(fixture);

      // Nothing typed, so no number sent: the server decides whether to ask.
      expect(sent).toMatchObject([{ code: TABLE, party: null }]);
      expect(page.textContent).toContain('How many are here?');
      expect(page.textContent).toContain('Chidi Nwosu · Table of 4 · none of 4 in yet');
      expect(button(fixture, 'All 4 here')).toBeDefined();
      expect(['1', '2', '3'].map((n) => button(fixture, n))).not.toContain(undefined);
      // A question: felt as one, and not a refusal or a scan in the tally.
      expect(Haptics.notification).toHaveBeenCalledWith({ type: 'WARNING' });
      expect(page.textContent).not.toContain('Do not admit');
      expect(door.scanned()).toBe(0);

      // Paused: the ticket still held up, and the next guest's behind it, go
      // nowhere however long the question takes.
      expect(page.textContent).toContain('Paused until you say how many are here');

      await holdUp(TABLE, 5000);
      await holdUp('MFST-9K2L4XQ7', 1000);

      expect(sent).toHaveLength(1);

      // One of the four is here. The ticket read seconds ago, still held up,
      // is sent again straight away rather than held back as a repeat.
      button(fixture, '1')!.click();
      await settle(fixture);

      expect(sent).toHaveLength(2);
      expect(sent[1]).toMatchObject({ code: TABLE, party: 1 });
      // Under the id of the scan that asked, which the question left free.
      expect(sent[1].clientId).toBe(sent[0].clientId);

      expect(page.textContent).toContain('Let them in');
      expect(page.textContent).toContain('1 of 4 in — 3 still to come');
      expect(door.asking()).toBeNull();
      expect(door.admitted()).toBe(1);

      // Reading again, and the ticket still held up is the scan just answered,
      // not the rest of the table.
      await holdUp(TABLE, 3000);

      expect(sent).toHaveLength(2);
    });

    it('asks again for the rest of the table later, and lets them in together', async () => {
      vi.useFakeTimers();
      answers(asked(0), letIn(1, 1), asked(1), letIn(3, 4));

      const fixture = await open();

      await fixture.componentInstance.startCamera();
      await holdUp(TABLE, 200);
      await settle(fixture);
      button(fixture, '1')!.click();
      await settle(fixture);

      // Away with the first guest, and back an hour later with the others.
      await vi.advanceTimersByTimeAsync(60 * 60_000);
      await holdUp(TABLE, 200);
      await settle(fixture);

      expect(fixture.nativeElement.textContent).toContain('Chidi Nwosu · Table of 4 · 1 of 4 already in');
      expect(button(fixture, '3')).toBeUndefined();

      button(fixture, 'All 3 here')!.click();
      await settle(fixture);

      expect(sent.map((scan) => scan.party)).toEqual([null, 1, null, 3]);
      // Each answer goes with its own question's id; the second question is
      // a scan of its own.
      expect(sent[1].clientId).toBe(sent[0].clientId);
      expect(sent[2].clientId).not.toBe(sent[0].clientId);
      expect(sent[3].clientId).toBe(sent[2].clientId);
      expect(fixture.nativeElement.textContent).toContain('Let them in');
      expect(fixture.componentInstance.admitted()).toBe(4);
    });

    it('puts the next ticket’s question up straight after the last one was answered', async () => {
      vi.useFakeTimers();

      const couple: ScanResult = {
        ...asked(0),
        remaining: 2,
        ticket: { holder_name: 'Bisi Adeyemi', type: 'Couple', admits: 2, admitted_count: 0 },
      };

      answers(asked(0), letIn(4, 4), couple);

      const fixture = await open();
      const page: HTMLElement = fixture.nativeElement;
      const raised = () => page.querySelector('mf-sheet .panel.showing');

      await fixture.componentInstance.startCamera();
      await holdUp(TABLE, 200);
      await settle(fixture);
      await vi.advanceTimersByTimeAsync(50);
      fixture.detectChanges();

      expect(raised()).not.toBeNull();

      button(fixture, 'All 4 here')!.click();
      await settle(fixture);

      // The couple beside them hold up theirs while the table's question is
      // still sliding away.
      seen!('CPLE-ACDEFHJK');
      await settle(fixture);
      await vi.advanceTimersByTimeAsync(50);
      fixture.detectChanges();

      // Up, with the couple's ticket in it: not slid away over a camera that
      // says it is waiting for an answer nobody can see how to give.
      expect(fixture.componentInstance.asking()?.code).toBe('CPLE-ACDEFHJK');
      expect(raised()).not.toBeNull();
      expect(raised()!.textContent).toContain('Bisi Adeyemi · Couple · none of 2 in yet');
      expect(button(fixture, 'All 2 here')).toBeDefined();

      await vi.advanceTimersByTimeAsync(1000);
      fixture.detectChanges();

      expect(raised()).not.toBeNull();
    });

    it('sends the answer under the asking scan’s id when it was asked with no signal', async () => {
      vi.useFakeTimers();

      const offline = TestBed.inject(DoorOffline);
      const queued: { party: number | null; clientId: string | undefined }[] = [];

      Object.defineProperty(offline, 'supported', { value: true });
      vi.spyOn(offline, 'prepare').mockResolvedValue(undefined);
      vi.spyOn(offline, 'sync').mockResolvedValue(true);
      vi.spyOn(offline, 'refreshList').mockResolvedValue(undefined);
      vi.spyOn(offline, 'decideAndQueue').mockImplementation(async (_event, _code, party, clientId) => {
        queued.push({ party, clientId });

        return party === null ? asked(0) : letIn(party, party);
      });

      // No answer from the server, either time.
      answer = async () => {
        throw new ApiError('No connection.', 0);
      };

      const fixture = await open();

      fixture.componentInstance.code.set(TABLE);
      await fixture.componentInstance.submit();
      await settle(fixture);

      button(fixture, '2')!.click();
      await settle(fixture);

      // The request that went unanswered may have arrived, and been let in on
      // the one place the server had left. Under the same id the answer is
      // that scan, online or queued, not a second person on the ticket.
      expect(sent).toHaveLength(2);
      expect(sent[1]).toMatchObject({ code: TABLE, party: 2, clientId: sent[0].clientId });
      expect(queued).toEqual([
        { party: null, clientId: sent[0].clientId },
        { party: 2, clientId: sent[0].clientId },
      ]);
    });

    it('lets the question go without sending anything', async () => {
      vi.useFakeTimers();
      answers(asked(0));

      const fixture = await open();

      fixture.componentInstance.code.set(TABLE);
      await fixture.componentInstance.submit();
      await settle(fixture);

      button(fixture, 'Not now')!.click();
      await settle(fixture);

      expect(fixture.componentInstance.asking()).toBeNull();
      expect(sent).toHaveLength(1);
    });

    it('uses a number typed before the scan without asking', async () => {
      answers(letIn(2, 2));

      const fixture = await open();

      fixture.componentInstance.code.set(TABLE);
      typeParty(fixture, '2');
      await fixture.componentInstance.submit();

      expect(sent).toMatchObject([{ code: TABLE, party: 2 }]);
      expect(fixture.componentInstance.asking()).toBeNull();
    });
  });

  /**
   * A scan refused before it is sent. The last guest's verdict used to stay up
   * through it, and nothing buzzed: a door holding a table's ticket to the
   * camera saw the previous guest's green "Let them in", and the only sign of
   * the refusal was small print under a box further down.
   */
  describe('a scan that goes nowhere', () => {
    async function afterAGuestWasLetIn() {
      const fixture = await open();
      const page: HTMLElement = fixture.nativeElement;

      fixture.componentInstance.code.set('WFY7-F77K4EJW');
      await fixture.componentInstance.submit();
      fixture.detectChanges();

      expect(page.textContent).toContain('Let them in');

      return { fixture, door: fixture.componentInstance, page };
    }

    it('takes the last verdict down and buzzes, for a ticket the camera read with a number that cannot be', async () => {
      vi.useFakeTimers();
      const { fixture, door, page } = await afterAGuestWasLetIn();

      // More than one scan can let in, typed for a table held up next.
      typeParty(fixture, '60');
      await door.startCamera();
      await holdUp('MFST-9K2L4XQ7', 1000);
      fixture.detectChanges();

      expect(sent).toHaveLength(1);
      expect(page.textContent).not.toContain('Let them in');
      expect(page.textContent).toContain('How many has to be a whole number from 1 to 50.');
      // Once, however long it is held up.
      expect(Haptics.notification).toHaveBeenCalledTimes(1);
      // The guest before is still in the list, just no longer the verdict.
      expect(page.querySelector('.recent')?.textContent).toContain('WFY7-F77K4EJW');
    });

    it('takes it down for a typed code no ticket could have, and puts up the next real answer', async () => {
      const { fixture, door, page } = await afterAGuestWasLetIn();

      door.code.set('https://example.com/pay?ref=1234');
      await door.submit();
      fixture.detectChanges();

      expect(page.textContent).not.toContain('Let them in');
      expect(page.textContent).toContain('That is not a ticket code.');
      expect(Haptics.notification).toHaveBeenCalledTimes(1);

      door.code.set('MFST-9K2L4XQ7');
      await door.submit();
      fixture.detectChanges();

      expect(page.textContent).toContain('Let them in');
      expect(page.querySelector('.recent')?.textContent).toContain('WFY7-F77K4EJW');
    });
  });

  /**
   * The screen sends what is waiting and fetches the list when it opens, then
   * keeps doing both on timers. The timers used to be set going only once the
   * first sync had finished, which on a slow wifi can be well after the screen
   * was left: nothing was left to stop them, and they went on all night for
   * that event — replacing the list another event's door was deciding from.
   */
  describe('sending and fetching without the screen open', () => {
    /** The phone's offline side, with a first sync still waiting on the server. */
    function slowFirstSync() {
      let settle!: (went: boolean) => void;
      const first = new Promise<boolean>((resolve) => (settle = resolve));
      const syncs: string[] = [];
      const refreshes: string[] = [];

      TestBed.overrideProvider(DoorOffline, {
        useValue: {
          supported: true,
          listCount: signal(0),
          listUpdatedAt: signal(null),
          pendingCount: signal(1),
          connectionLost: signal(false),
          conflicts: signal([]),
          unrecorded: signal([]),
          prepare: async () => undefined,
          sync: (event: string) => {
            syncs.push(event);

            return syncs.length === 1 ? first : Promise.resolve(true);
          },
          refreshList: async (event: string) => {
            refreshes.push(event);
          },
        },
      });

      return { syncs, refreshes, settle };
    }

    it('stops once the screen is left, however long the first sync takes', async () => {
      vi.useFakeTimers();
      const offline = slowFirstSync();
      const fixture = TestBed.createComponent(Door);

      fixture.detectChanges();
      await vi.advanceTimersByTimeAsync(0);

      expect(offline.syncs).toEqual(['evt_1']);

      // Back to the events list with the scans still on their way...
      fixture.destroy();

      // ...and the server answering some time after.
      offline.settle(false);
      await vi.advanceTimersByTimeAsync(10 * 60_000);

      expect(offline.syncs).toEqual(['evt_1']);
      expect(offline.refreshes).toEqual([]);
    });

    it('keeps sending and fetching while the screen is open', async () => {
      vi.useFakeTimers();
      const offline = slowFirstSync();
      const fixture = TestBed.createComponent(Door);

      fixture.detectChanges();
      offline.settle(true);
      await vi.advanceTimersByTimeAsync(0);

      expect(offline.refreshes).toEqual(['evt_1']);

      await vi.advanceTimersByTimeAsync(3 * 60_000);

      // Every fifteen seconds, and the list every three minutes.
      expect(offline.syncs).toHaveLength(13);
      expect(offline.refreshes).toHaveLength(2);

      fixture.destroy();
    });
  });
});
