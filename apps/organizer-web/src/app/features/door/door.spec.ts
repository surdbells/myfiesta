import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DoorOfflineStore, hashCode, zxingWasmUrl } from '@myfiesta/door';
import { memoryIndexedDB } from '../../../../../../packages/door/src/testing/memory-indexeddb';
import { API_BASE_URL } from '../../core/api';
import { OfflineScan, ScanResult } from '../../core/api.types';
import { DoorOffline } from '../../core/door-offline';
import { Door } from './door';

const SCAN = 'http://api.test/api/events/evt_1/scan';
const SYNC = 'http://api.test/api/events/evt_1/scans/sync';
const LIST = 'http://api.test/api/events/evt_1/door-list';

const admitted: ScanResult = {
  result: 'admitted',
  accepted: true,
  admitted: 1,
  remaining: 0,
  message: 'Admitted.',
  ticket: { holder_name: 'Ada Okoro', type: 'General', admits: 1, admitted_count: 1 },
};

/** A camera that is only a track that can be stopped. */
function camera() {
  const track = { stop: vi.fn(), addEventListener: vi.fn() };
  const stream = { getTracks: () => [track], getVideoTracks: () => [track] } as unknown as MediaStream;

  return { track, stream };
}

function giveCamera(getUserMedia: () => Promise<MediaStream>) {
  Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia }, configurable: true });
}

/**
 * A browser with its own BarcodeDetector, as Chrome on Android has. For the
 * tests about the screen rather than the decoder, which is tested with a real
 * code in door-camera.spec.ts.
 */
function engineReading(detect = vi.fn(async () => [] as { rawValue: string }[])) {
  vi.stubGlobal(
    'BarcodeDetector',
    class {
      static getSupportedFormats = async () => ['qr_code'];
      detect = detect;
    },
  );

  return detect;
}

/** The screen's own preview, given frames, a play() and a pause() — jsdom's has none of them. */
function withFrames(page: HTMLElement): HTMLVideoElement {
  const video = page.querySelector('video')!;

  Object.defineProperty(video, 'readyState', { value: HTMLMediaElement.HAVE_ENOUGH_DATA });
  video.play = vi.fn(async () => undefined);
  video.pause = vi.fn();

  return video;
}

/**
 * The console's door screen, and its camera.
 *
 * It used to offer the camera only where the browser had a BarcodeDetector,
 * and told everybody on an iPhone to type the code instead. It offers the
 * camera wherever there is one now, reading with ZXing where the browser
 * cannot — and one ticket held up to it is still one scan, however long it is
 * held up or the answer takes. What it reads that is not a ticket's goes
 * nowhere, and is said over the preview as the phone app says it, and a scan
 * the server will never take cannot hold up the queue of scans made without
 * signal.
 */
describe('Door', () => {
  let backend: HttpTestingController;
  const fetched: string[] = [];

  beforeEach(() => {
    fetched.length = 0;

    // Safari: no BarcodeDetector at all.
    vi.stubGlobal('BarcodeDetector', undefined);
    vi.stubGlobal('fetch', async (input: string | URL | Request) => {
      fetched.push(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url);

      return new Response(new Uint8Array(8), { headers: { 'Content-Type': 'application/wasm' } });
    });
    Object.defineProperty(document, 'hidden', { value: false, configurable: true });

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: 'evt_1' }) }, parent: null },
        },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    backend.verify();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    delete (navigator as { mediaDevices?: unknown }).mediaDevices;
    delete (window as { isSecureContext?: boolean }).isSecureContext;
  });

  function render() {
    const fixture = TestBed.createComponent(Door);
    fixture.detectChanges();

    return fixture;
  }

  function buttonNamed(root: HTMLElement, name: string): HTMLButtonElement | undefined {
    return [...root.querySelectorAll('button')].find((button) => button.textContent?.trim() === name);
  }

  it('offers the camera on an iPhone, and fetches its decoder from the console while there is signal', async () => {
    giveCamera(async () => camera().stream);

    const fixture = render();
    const page: HTMLElement = fixture.nativeElement;

    expect(buttonNamed(page, 'Use the camera')).toBeDefined();
    expect(page.textContent).not.toContain('iPhone');
    // In the page before the camera is asked for: the frames are read off it.
    expect(page.querySelector('video')).not.toBeNull();

    await vi.waitFor(() => expect(fetched).toEqual([zxingWasmUrl()]));
  });

  describe('the list saved on this phone', () => {
    beforeEach(() => {
      const db = memoryIndexedDB();

      vi.stubGlobal('indexedDB', db.indexedDB);
      vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);
    });

    async function oneTicketSaved() {
      const store = TestBed.inject(DoorOffline);
      Object.defineProperty(store, 'supported', { value: true });

      const salt = 'salt-for-evt_1';
      const list = {
        event_id: 'evt_1',
        salt,
        iterations: 1,
        generated_at: new Date().toISOString(),
        tickets: [{ hash: await hashCode('WFY7-F77K4EJW', salt, 1), status: 'valid' as const, admits: 1, admitted_count: 0, holder_name: 'Ada Okoro', type: 'General' }],
      };

      await store.save(list);

      return list;
    }

    /** The fresh list the door asks for once its saved one is loaded. */
    async function listAsked(): Promise<TestRequest> {
      let asked: TestRequest[] = [];

      await vi.waitFor(() => {
        asked = [...asked, ...backend.match(LIST)];
        expect(asked).toHaveLength(1);
      });

      return asked[0];
    }

    // "1 ticket", not "1 tickets".
    it('counts one ticket as one, with signal and without', async () => {
      const list = await oneTicketSaved();
      const fixture = render();
      const page: HTMLElement = fixture.nativeElement;

      (await listAsked()).flush(list);

      await vi.waitFor(() => {
        fixture.detectChanges();
        expect(page.textContent).toContain('1 ticket saved on this phone');
      });
      expect(page.textContent).not.toContain('1 tickets');
    });

    it('counts one ticket as one when there is no signal', async () => {
      await oneTicketSaved();
      const fixture = render();
      const page: HTMLElement = fixture.nativeElement;

      (await listAsked()).error(new ProgressEvent('error'));

      await vi.waitFor(() => {
        fixture.detectChanges();
        expect(page.textContent).toContain('No connection');
      });
      expect(page.textContent).toContain('1 ticket, updated');
    });
  });

  it('says the camera needs https where the page was opened over plain http', () => {
    Object.defineProperty(window, 'isSecureContext', { value: false, configurable: true });

    const page: HTMLElement = render().nativeElement;

    expect(buttonNamed(page, 'Use the camera')).toBeUndefined();
    expect(page.textContent).toContain('The camera only works when the console is opened over https.');
    expect(fetched).toEqual([]);
  });

  /** The door screen with its camera reading whatever `detect` finds, five times a second. */
  async function scanningWith(detect: ReturnType<typeof engineReading>, before?: (door: Door) => void) {
    vi.useFakeTimers();
    engineReading(detect);
    giveCamera(async () => camera().stream);

    const fixture = render();

    before?.(fixture.componentInstance);
    withFrames(fixture.nativeElement);
    await fixture.componentInstance.startCamera();
    fixture.detectChanges();

    return fixture;
  }

  it('checks a ticket in once, however long it is held up to the camera', async () => {
    const detect = vi.fn(async () => [{ rawValue: 'wfy7-f77k4ejw' }]);
    const fixture = await scanningWith(detect);
    const door = fixture.componentInstance;

    expect(door.scanning()).toBe(true);
    expect(buttonNamed(fixture.nativeElement, 'Stop the camera')).toBeDefined();

    await vi.advanceTimersByTimeAsync(600);

    const scans = backend.match(SCAN);

    expect(scans).toHaveLength(1);
    expect(scans[0].request.body.code).toBe('WFY7-F77K4EJW');

    scans[0].flush(admitted);

    // Held up for ten seconds more, through the ID check and well past the
    // four a ticket has to be out of sight before it is read again.
    await vi.advanceTimersByTimeAsync(10_000);
    fixture.detectChanges();

    expect(detect.mock.calls.length).toBeGreaterThan(40);
    expect(backend.match(SCAN)).toHaveLength(0);
    expect(fixture.nativeElement.textContent).toContain('Let them in');
  });

  it('checks a ticket in once when the answer is slow, and does not admit the rest of a table with it', async () => {
    // Two of a table of four going in now.
    const fixture = await scanningWith(
      vi.fn(async () => [{ rawValue: 'WFY7-F77K4EJW' }]),
      (door) => door.party.set('2'),
    );

    await vi.advanceTimersByTimeAsync(200);

    const [scan] = backend.match(SCAN);

    expect(scan.request.body.party).toBe(2);

    // A venue wifi taking its time; the ticket stays in view throughout.
    await vi.advanceTimersByTimeAsync(4500);
    scan.flush({
      ...admitted,
      admitted: 2,
      remaining: 2,
      message: 'Admitted 2. 2 still to come.',
      ticket: { holder_name: 'Chidi Nwosu', type: 'Table of 4', admits: 4, admitted_count: 2 },
    });
    await vi.advanceTimersByTimeAsync(10_000);
    fixture.detectChanges();

    // A second scan here would go with no number, which asks all over again
    // about a table the door has just answered for.
    expect(backend.match(SCAN)).toHaveLength(0);
    expect(fixture.nativeElement.textContent).toContain('2 of 4 in — 2 still to come');
    // The number typed first is the answer: nothing is asked.
    expect(fixture.nativeElement.textContent).not.toContain('How many are here?');
  });

  /**
   * A ticket for more than one, read with nothing in "How many".
   *
   * It used to let in everyone the ticket had left, so the first of a table
   * holding its ticket up counted the whole table in, and the rest walked in
   * later unscanned. The server asks now, and so does this door: the camera
   * holds still, the door says how many are here, and the scan goes again
   * with that number.
   */
  describe('a ticket for more than one', () => {
    const TABLE = 'TBLE-ACDEFHJK';
    const table = (inside: number) => ({ holder_name: 'Chidi Nwosu', type: 'Table of 4', admits: 4, admitted_count: inside });

    /** The server's question about the table, with `inside` of four already in. */
    const asked = (inside: number): ScanResult => ({
      result: 'choose_party',
      accepted: false,
      admitted: 0,
      remaining: 4 - inside,
      message: `This ticket admits 4, ${inside === 0 ? 'nobody in yet' : `${inside} already in`}. Put how many are going in now in How many, and scan it again.`,
      ticket: table(inside),
    });

    const letIn = (admittedNow: number, inside: number): ScanResult => ({
      result: 'accepted',
      accepted: true,
      admitted: admittedNow,
      remaining: 4 - inside,
      message: inside === 4 ? `Admitted ${admittedNow}. That is everyone.` : `Admitted ${admittedNow}. ${4 - inside} still to come.`,
      ticket: table(inside),
    });

    it('asks how many are here with the camera paused, and sends the answer at once', async () => {
      const detect = vi.fn(async () => [{ rawValue: TABLE }]);
      const fixture = await scanningWith(detect);
      const door = fixture.componentInstance;
      const page: HTMLElement = fixture.nativeElement;
      const video = page.querySelector('video')!;

      await vi.advanceTimersByTimeAsync(200);

      const [first] = backend.match(SCAN);

      // Nothing typed, so no number sent: the server decides whether to ask.
      expect(first.request.body.party).toBeUndefined();

      first.flush(asked(0));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      expect(page.textContent).toContain('How many are here?');
      expect(page.textContent).toContain('Chidi Nwosu · Table of 4 · none of 4 in yet');
      expect(buttonNamed(page, 'All 4 here')).toBeDefined();
      expect(['1', '2', '3'].map((n) => buttonNamed(page, n))).not.toContain(undefined);
      // A question, not a refusal, and nobody counted.
      expect(page.textContent).not.toContain('Do not admit');
      expect(door.scannedHere()).toBe(0);

      // Paused: the preview holds still and nothing is read, however long the
      // question takes — the next guest's ticket is not scanned over it.
      expect(page.textContent).toContain('The camera is paused until you choose.');
      expect(video.pause).toHaveBeenCalled();

      const readsBefore = detect.mock.calls.length;

      await vi.advanceTimersByTimeAsync(5000);

      expect(detect.mock.calls.length).toBe(readsBefore);
      expect(backend.match(SCAN)).toHaveLength(0);

      // One of the four is here. The same ticket, read seconds ago and still
      // held up, is sent again straight away rather than held back as a repeat.
      buttonNamed(page, '1')!.click();

      const [answer] = backend.match(SCAN);

      expect(answer.request.body).toMatchObject({ code: TABLE, party: 1 });
      // Under the id of the scan that asked, which the question left free.
      expect(answer.request.body.client_id).toBe(first.request.body.client_id);

      answer.flush(letIn(1, 1));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      expect(page.textContent).toContain('1 of 4 in — 3 still to come');
      expect(page.textContent).not.toContain('How many are here?');
      expect(door.admittedHere()).toBe(1);
      expect(door.scannedHere()).toBe(1);

      // Reading again, and the ticket still held up is the scan just answered,
      // not the rest of the table.
      await vi.advanceTimersByTimeAsync(3000);

      expect(detect.mock.calls.length).toBeGreaterThan(readsBefore);
      expect(backend.match(SCAN)).toHaveLength(0);
    });

    it('asks again for the rest of the table later, and lets them in together', async () => {
      const detect = vi.fn(async () => [{ rawValue: TABLE }]);
      const fixture = await scanningWith(detect);
      const page: HTMLElement = fixture.nativeElement;

      await vi.advanceTimersByTimeAsync(200);
      backend.expectOne(SCAN).flush(asked(0));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      buttonNamed(page, '1')!.click();
      backend.expectOne(SCAN).flush(letIn(1, 1));
      await vi.advanceTimersByTimeAsync(0);

      // The ticket goes away with the first guest, and comes back an hour later.
      detect.mockResolvedValue([]);
      await vi.advanceTimersByTimeAsync(60_000);
      detect.mockResolvedValue([{ rawValue: TABLE }]);
      await vi.advanceTimersByTimeAsync(200);

      backend.expectOne(SCAN).flush(asked(1));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      expect(page.textContent).toContain('Chidi Nwosu · Table of 4 · 1 of 4 already in');
      expect(buttonNamed(page, '3')).toBeUndefined();

      buttonNamed(page, 'All 3 here')!.click();

      const rest = backend.expectOne(SCAN);

      expect(rest.request.body).toMatchObject({ code: TABLE, party: 3 });

      rest.flush(letIn(3, 4));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      expect(page.textContent).toContain('Let them in');
      expect(fixture.componentInstance.admittedHere()).toBe(4);
    });

    it('offers a stepper for a table too big for a button each', async () => {
      const fixture = render();
      const door = fixture.componentInstance;
      const page: HTMLElement = fixture.nativeElement;

      door.code.set(TABLE);
      door.submit();
      backend.expectOne(SCAN).flush({ ...asked(0), remaining: 12, ticket: { ...table(0), type: 'Table of 12', admits: 12 } });
      await fixture.whenStable();
      fixture.detectChanges();

      expect(buttonNamed(page, 'All 12 here')).toBeDefined();
      expect(buttonNamed(page, '5')).toBeUndefined();

      for (let n = 0; n < 4; n++) buttonNamed(page, '+')!.click();
      fixture.detectChanges();

      buttonNamed(page, 'Let 5 in')!.click();

      expect(backend.expectOne(SCAN).request.body).toMatchObject({ code: TABLE, party: 5 });
    });

    it('lets the question go without sending anything, and reads again', async () => {
      const detect = vi.fn(async () => [{ rawValue: TABLE }]);
      const fixture = await scanningWith(detect);
      const page: HTMLElement = fixture.nativeElement;

      await vi.advanceTimersByTimeAsync(200);
      backend.expectOne(SCAN).flush(asked(0));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      const readsBefore = detect.mock.calls.length;

      buttonNamed(page, 'Not now')!.click();
      fixture.detectChanges();

      expect(page.textContent).not.toContain('How many are here?');
      expect(backend.match(SCAN)).toHaveLength(0);

      await vi.advanceTimersByTimeAsync(1000);

      expect(detect.mock.calls.length).toBeGreaterThan(readsBefore);
    });

    describe('with no signal', () => {
      beforeEach(() => {
        const db = memoryIndexedDB();

        vi.stubGlobal('indexedDB', db.indexedDB);
        vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);
      });

      it('asks from the phone’s list too, and queues only the answer, with its number', async () => {
        const store = TestBed.inject(DoorOffline);

        Object.defineProperty(store, 'supported', { value: true });

        const salt = 'salt-for-evt_1';

        await store.save({
          event_id: 'evt_1',
          salt,
          iterations: 1,
          generated_at: '2026-09-26T21:05:00Z',
          tickets: [{ hash: await hashCode(TABLE, salt, 1), status: 'valid', ...table(0) }],
        });

        const fixture = render();
        const door = fixture.componentInstance;
        const page: HTMLElement = fixture.nativeElement;

        // The list refresh on opening: no signal for it.
        await vi.waitFor(() => expect(backend.match(LIST)).toHaveLength(1));

        door.code.set(TABLE);
        door.submit();

        const first = backend.expectOne(SCAN);
        const asked = first.request.body.client_id;

        first.error(new ProgressEvent('error'));
        await vi.waitFor(() => {
          fixture.detectChanges();
          expect(page.textContent).toContain('How many are here?');
        });

        // The question let nobody in and turned nobody away: nothing to send.
        expect(await store.pending('evt_1')).toEqual([]);

        buttonNamed(page, '2')!.click();

        // Under the asking scan's id, online and queued alike. The request
        // that went unanswered may have arrived: if the server let that scan
        // in, this is the same scan, not a second person on the ticket.
        const answer = backend.expectOne(SCAN);

        expect(answer.request.body).toMatchObject({ code: TABLE, party: 2, client_id: asked });

        answer.error(new ProgressEvent('error'));
        await vi.waitFor(() => {
          fixture.detectChanges();
          expect(page.textContent).toContain('2 of 4 in — 2 still to come');
        });

        expect(await store.pending('evt_1')).toMatchObject([
          { client_id: asked, code: TABLE, party: 2, offline_result: 'accepted' },
        ]);
      });
    });
  });

  it('sends nothing for a QR code that is not a ticket’s, and says what it saw', async () => {
    const link = `https://example.com/${'x'.repeat(40)}`;
    const detect = vi.fn(async () => [{ rawValue: link }]);
    const fixture = await scanningWith(detect);
    const page: HTMLElement = fixture.nativeElement;

    await vi.advanceTimersByTimeAsync(2000);
    fixture.detectChanges();

    // Online or off: a code longer than any ticket's, saved while offline,
    // would hold every scan queued behind it.
    expect(backend.match(SCAN)).toHaveLength(0);
    expect(fixture.componentInstance.code()).toBe('');
    expect(page.textContent).not.toContain('Do not admit');
    // It used to say nothing, and a door shown a payment code saw a camera doing nothing.
    expect(page.textContent).toContain('That QR code is not a ticket.');

    // Gone once it is out of view.
    detect.mockResolvedValue([]);
    await vi.advanceTimersByTimeAsync(3000);
    fixture.detectChanges();

    expect(page.textContent).not.toContain('That QR code is not a ticket.');
  });

  it('says nothing about a code behind a ticket that is being read', async () => {
    const fixture = await scanningWith(
      vi.fn(async () => [{ rawValue: 'WFY7-F77K4EJW' }, { rawValue: 'https://example.com/poster' }]),
    );

    await vi.advanceTimersByTimeAsync(2000);
    fixture.detectChanges();

    const scans = backend.match(SCAN);

    expect(scans.map((scan) => scan.request.body.code)).toEqual(['WFY7-F77K4EJW']);
    expect(fixture.nativeElement.textContent).not.toContain('That QR code is not a ticket.');

    scans[0].flush(admitted);
    await vi.advanceTimersByTimeAsync(0);
  });

  it('says a typed code cannot be a ticket’s rather than sending it', () => {
    const fixture = render();
    const door = fixture.componentInstance;

    door.code.set('https://example.com/pay?ref=1234567890abcdefghijklmnop');
    door.submit();
    fixture.detectChanges();

    expect(backend.match(SCAN)).toHaveLength(0);
    expect(fixture.nativeElement.textContent).toContain('That is not a ticket code.');
  });

  describe('how many', () => {
    const refused = 'How many has to be a whole number from 1 to 50.';

    /** Typed into the box the way a person types it, through the number box Angular reads. */
    async function typeParty(fixture: ReturnType<typeof render>, typed: string) {
      await fixture.whenStable();

      const box: HTMLInputElement = fixture.nativeElement.querySelector('input#party');

      box.value = typed;
      box.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    }

    it('refuses a number that is not a whole number of people, rather than sending or deciding it', async () => {
      for (const typed of ['1.5', '0', '51']) {
        const fixture = render();
        const door = fixture.componentInstance;

        door.code.set('WFY7-F77K4EJW');
        await typeParty(fixture, typed);
        door.submit();
        fixture.detectChanges();

        // Offline, 1.5 would have been decided and queued; 0 went as blank,
        // which lets the whole table in.
        expect(backend.match(SCAN), typed).toHaveLength(0);
        expect(fixture.nativeElement.textContent, typed).toContain(refused);
        // Left where it was, to be put right and sent.
        expect(door.code(), typed).toBe('WFY7-F77K4EJW');

        fixture.destroy();
      }
    });

    it('sends a whole number typed into the box', async () => {
      const fixture = render();
      const door = fixture.componentInstance;

      door.code.set('WFY7-F77K4EJW');
      await typeParty(fixture, '3');
      door.submit();

      expect(backend.expectOne(SCAN).request.body.party).toBe(3);
    });

    it('refuses it for a ticket the camera read as well', async () => {
      const fixture = await scanningWith(
        vi.fn(async () => [{ rawValue: 'WFY7-F77K4EJW' }]),
        (door) => door.party.set('1.5'),
      );

      await vi.advanceTimersByTimeAsync(2000);
      fixture.detectChanges();

      expect(backend.match(SCAN)).toHaveLength(0);
      expect(fixture.nativeElement.textContent).toContain(refused);
    });

    it('takes digits only as they are typed, with a keypad of numbers and whole steps', () => {
      const box: HTMLInputElement = render().nativeElement.querySelector('input#party');

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

  it('says the camera was refused, and leaves the code box', async () => {
    engineReading();
    giveCamera(async () => {
      throw new DOMException('Permission denied', 'NotAllowedError');
    });

    const fixture = render();

    await fixture.componentInstance.startCamera();
    fixture.detectChanges();

    const page: HTMLElement = fixture.nativeElement;

    expect(page.textContent).toContain('The camera was refused. Type the code instead');
    expect(page.querySelector('input#code')).not.toBeNull();
    expect(buttonNamed(page, 'Use the camera')).toBeDefined();
  });

  describe('scans waiting on this phone', () => {
    const good: OfflineScan = {
      client_id: '6f1c2a4e-3b7d-4c55-9a0e-2d8f1b3c4a5e',
      event_id: 'evt_1',
      code: 'WFY7-F77K4EJW',
      party: null,
      offline_result: 'accepted',
      scanned_at: '2026-09-26T21:00:00Z',
    };

    // Read before this door checked what it read: longer than any ticket code.
    const unsendable: OfflineScan = {
      ...good,
      client_id: '0b9e8d7c-6a5f-4e3d-8c2b-1a0f9e8d7c6b',
      code: `HTTPS://EXAMPLE.COM/${'X'.repeat(40)}`,
      offline_result: 'not_found',
      scanned_at: '2026-09-26T21:01:00Z',
    };

    /** The saved queue, standing in for IndexedDB, which jsdom does not have. */
    function queueOnThisPhone(rows: OfflineScan[]) {
      const store = TestBed.inject(DoorOffline);
      const phone = { rows };

      Object.defineProperty(store, 'supported', { value: true });
      vi.spyOn(store, 'load').mockResolvedValue(null);
      vi.spyOn(store, 'pending').mockImplementation(async () => [...phone.rows]);
      vi.spyOn(store, 'forget').mockImplementation(async (ids: string[]) => {
        phone.rows = phone.rows.filter((row) => !ids.includes(row.client_id));
      });

      return phone;
    }

    function refuse(field: string) {
      backend.expectOne(SYNC).flush(
        { message: 'The given data was invalid.', errors: { [field]: ['Not valid.'] } },
        { status: 422, statusText: 'Unprocessable Content' },
      );
    }

    it('drops a scan the server can never take, and sends the rest', async () => {
      vi.useFakeTimers();
      const phone = queueOnThisPhone([good, unsendable]);
      const door = render().componentInstance;

      await vi.advanceTimersByTimeAsync(0);
      refuse('scans.1.code');
      await vi.advanceTimersByTimeAsync(0);

      expect(phone.rows).toEqual([good]);

      // Sent on the next try, instead of waiting behind it all night.
      await vi.advanceTimersByTimeAsync(15_000);

      const retry = backend.expectOne(SYNC);

      expect(retry.request.body.scans.map((scan: OfflineScan) => scan.client_id)).toEqual([good.client_id]);

      retry.flush({ data: [{ ...admitted, client_id: good.client_id, conflict: null }], conflicts: [] });
      await vi.advanceTimersByTimeAsync(0);

      expect(phone.rows).toEqual([]);
      expect(door.pendingCount()).toBe(0);
    });

    /**
     * The first sync can take a while on a slow wifi. A screen left before it
     * finished used to go on and fetch this event's list anyway, then set its
     * timers going after they had been cleared, with nothing left to stop
     * them: this event's scans sent and its list fetched all night, by a door
     * nobody could see, whichever event's door was open.
     */
    it('stops sending and fetching once the screen is left, however long the first sync takes', async () => {
      vi.useFakeTimers();
      queueOnThisPhone([good]);

      const fixture = render();

      await vi.advanceTimersByTimeAsync(0);

      const first = backend.expectOne(SYNC);

      fixture.destroy();
      first.flush({ data: [{ ...admitted, client_id: good.client_id, conflict: null }], conflicts: [] });
      await vi.advanceTimersByTimeAsync(3 * 60_000);

      expect(backend.match(LIST)).toHaveLength(0);
      expect(backend.match(SYNC)).toHaveLength(0);
    });

    it('keeps every scan when what the server refused is not something a person typed or a camera read', async () => {
      vi.useFakeTimers();
      const phone = queueOnThisPhone([good, unsendable]);

      render();

      await vi.advanceTimersByTimeAsync(0);
      refuse('scans.0.client_id');
      await vi.advanceTimersByTimeAsync(0);

      expect(phone.rows).toEqual([good, unsendable]);

      // Still sent on the next try; this door just cannot drop what it cannot explain.
      await vi.advanceTimersByTimeAsync(15_000);
      refuse('scans.0.client_id');
      await vi.advanceTimersByTimeAsync(0);
    });

    describe('one that let somebody in and can never be sent', () => {
      // Two of a table let in before this door checked the number, written down as one and a half.
      const halfAPerson: OfflineScan = {
        ...good,
        client_id: '3c2b1a0f-9e8d-4c6b-8a5f-4e3d2c1b0a9f',
        code: 'MFST-9K2L4XQ7',
        party: 1.5,
        scanned_at: '2026-09-26T21:02:00Z',
      };

      /**
       * This phone's IndexedDB, in memory, rather than the store's methods
       * mocked: what matters here is what is still on the phone once the
       * screen has gone.
       */
      beforeEach(() => {
        const db = memoryIndexedDB();

        vi.stubGlobal('indexedDB', db.indexedDB);
        vi.stubGlobal('IDBKeyRange', db.IDBKeyRange);
      });

      function onThisPhone<T extends DoorOfflineStore>(store: T): T {
        Object.defineProperty(store, 'supported', { value: true });

        return store;
      }

      it('says so, rather than losing them quietly', async () => {
        vi.useFakeTimers();
        const store = onThisPhone(TestBed.inject(DoorOffline));

        await store.enqueue(good);
        await store.enqueue(halfAPerson);

        const fixture = render();

        await vi.advanceTimersByTimeAsync(0);
        refuse('scans.1.party');
        await vi.advanceTimersByTimeAsync(0);
        fixture.detectChanges();

        // Not sent again, and not holding up what can be.
        expect(await store.pending('evt_1')).toEqual([good]);

        const page: HTMLElement = fixture.nativeElement;

        expect(page.textContent).toContain('A scan made offline could not be recorded');
        expect(page.textContent).toContain('MFST-9K2L4XQ7 was let in with no signal');

        buttonNamed(page, 'Dismiss')?.click();
        await vi.advanceTimersByTimeAsync(0);
        fixture.detectChanges();

        expect(page.textContent).not.toContain('could not be recorded');
        // Read, so off the phone too.
        expect(await store.unrecordedAdmissions('evt_1')).toEqual([]);
      });

      it('still says so when the door screen is opened again, until somebody has read it', async () => {
        vi.useFakeTimers();

        // The last time the door was open: the server could not take it, and
        // the tab was reloaded before anybody looked.
        const earlier = onThisPhone(new DoorOfflineStore());

        await earlier.enqueue(halfAPerson);
        await earlier.dropUnsendable([halfAPerson], { 'scans.0.party': ['Not valid.'] });

        onThisPhone(TestBed.inject(DoorOffline));

        const fixture = render();

        await vi.advanceTimersByTimeAsync(0);
        // Nothing waiting to be sent, so the list is fetched; there is no signal for it.
        backend.expectOne(LIST).error(new ProgressEvent('error'));
        await vi.advanceTimersByTimeAsync(0);
        fixture.detectChanges();

        const page: HTMLElement = fixture.nativeElement;

        expect(page.textContent).toContain('A scan made offline could not be recorded');
        expect(page.textContent).toContain('MFST-9K2L4XQ7 was let in with no signal');

        buttonNamed(page, 'Dismiss')?.click();
        await vi.advanceTimersByTimeAsync(0);

        expect(await onThisPhone(new DoorOfflineStore()).unrecordedAdmissions('evt_1')).toEqual([]);
      });
    });

    it('says so when the server had already turned away a ticket this door then let in', async () => {
      vi.useFakeTimers();
      queueOnThisPhone([good]);

      const fixture = render();

      await vi.advanceTimersByTimeAsync(0);
      // The scan reached the server before the signal went, and was refused —
      // used at another door since this phone fetched its list — but the
      // answer never came back, so this door let the guest in from its list
      // and queued the scan under the same id.
      backend.expectOne(SYNC).flush({
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
      await vi.advanceTimersByTimeAsync(0);
      backend.expectOne(LIST).error(new ProgressEvent('error'));
      await vi.advanceTimersByTimeAsync(0);
      fixture.detectChanges();

      const page: HTMLElement = fixture.nativeElement;

      expect(page.textContent).toContain('While offline, this door got 1 wrong');
      expect(page.textContent).toContain('Ada Okoro');
      expect(page.textContent).toContain('was let in, but the ticket was already used or not valid.');
    });
  });

  it('turns the camera off when the door screen is left', async () => {
    engineReading();
    const { track, stream } = camera();

    giveCamera(async () => stream);

    const fixture = render();

    withFrames(fixture.nativeElement);
    await fixture.componentInstance.startCamera();

    expect(fixture.componentInstance.scanning()).toBe(true);
    expect(track.stop).not.toHaveBeenCalled();

    fixture.destroy();

    expect(track.stop).toHaveBeenCalled();
  });
});
