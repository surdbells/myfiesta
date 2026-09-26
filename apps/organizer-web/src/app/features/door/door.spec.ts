import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { zxingWasmUrl } from '@myfiesta/door';
import { API_BASE_URL } from '../../core/api';
import { OfflineScan, ScanResult } from '../../core/api.types';
import { DoorOffline } from '../../core/door-offline';
import { Door } from './door';

const SCAN = 'http://api.test/api/events/evt_1/scan';
const SYNC = 'http://api.test/api/events/evt_1/scans/sync';

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

/** The screen's own preview, given frames and a play() — jsdom's has neither. */
function withFrames(page: HTMLElement): void {
  const video = page.querySelector('video')!;

  Object.defineProperty(video, 'readyState', { value: HTMLMediaElement.HAVE_ENOUGH_DATA });
  video.play = vi.fn(async () => undefined);
}

/**
 * The console's door screen, and its camera.
 *
 * It used to offer the camera only where the browser had a BarcodeDetector,
 * and told everybody on an iPhone to type the code instead. It offers the
 * camera wherever there is one now, reading with ZXing where the browser
 * cannot — and one ticket held up to it is still one scan, however long it is
 * held up or the answer takes. What it reads that is not a ticket's goes
 * nowhere, and a scan the server will never take cannot hold up the queue of
 * scans made without signal.
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
    scan.flush({ ...admitted, admitted: 2, remaining: 2, message: 'Admitted 2. 2 still to come.' });
    await vi.advanceTimersByTimeAsync(10_000);
    fixture.detectChanges();

    // A second scan here would go with no number, which admits everyone left.
    expect(backend.match(SCAN)).toHaveLength(0);
    expect(fixture.nativeElement.textContent).toContain('2 in · 2 still outside');
  });

  it('sends nothing for a QR code that is not a ticket’s', async () => {
    const link = `https://example.com/${'x'.repeat(40)}`;
    const fixture = await scanningWith(vi.fn(async () => [{ rawValue: link }]));

    await vi.advanceTimersByTimeAsync(2000);
    fixture.detectChanges();

    // Online or off: a code longer than any ticket's, saved while offline,
    // would hold every scan queued behind it.
    expect(backend.match(SCAN)).toHaveLength(0);
    expect(fixture.componentInstance.code()).toBe('');
    expect(fixture.nativeElement.textContent).not.toContain('Do not admit');
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
