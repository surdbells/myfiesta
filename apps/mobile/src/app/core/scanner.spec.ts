import { TestBed } from '@angular/core/testing';
import { createHash } from 'node:crypto';
import { readFileSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import QRCode from 'qrcode';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * vi.mock factories are hoisted above everything else in the file, so the
 * state they close over has to be hoisted with them.
 */
const fake = vi.hoisted(() => ({
  native: false,
  platform: 'web',
  /** Whether ML Kit is linked into the native project, which on iOS it never is. */
  linked: false,
  scans: 0,
}));

vi.mock('@capacitor/core', () => ({
  Capacitor: {
    isNativePlatform: () => fake.native,
    getPlatform: () => fake.platform,
    isPluginAvailable: (name: string) => name === 'BarcodeScanner' && fake.linked,
  },
}));

vi.mock('@capacitor-mlkit/barcode-scanning', () => ({
  BarcodeFormat: { QrCode: 'QR_CODE' },
  LensFacing: { Back: 'BACK' },
  BarcodeScanner: {
    // What an installed but unlinked plugin does when asked anything.
    isSupported: async () => {
      if (!fake.linked) throw new Error(`"BarcodeScanner" plugin is not implemented on ${fake.platform}`);

      return { supported: true };
    },
    checkPermissions: async () => ({ camera: 'granted' }),
    requestPermissions: async () => ({ camera: 'granted' }),
    isGoogleBarcodeScannerModuleAvailable: async () => ({ available: true }),
    installGoogleBarcodeScannerModule: async () => undefined,
    addListener: async () => ({ remove: async () => undefined }),
    startScan: async () => {
      fake.scans++;
    },
    stopScan: async () => undefined,
    removeAllListeners: async () => undefined,
  },
}));

import { READ_EVERY_MS, Scanner, openDetector, zxingWasmUrl } from './scanner';

/**
 * The .wasm this app ships: the very file scanner.ts imports, found by reading
 * that import rather than by asking Node where the package is.
 *
 * The two can disagree. Node looks in this app's own node_modules first; the
 * import goes by path to the one at the root of the repository. They are the
 * same file only while npm keeps a single copy, and a spec that checked Node's
 * copy would stay green on the day it kept two — while every iPhone door
 * shipped the other one.
 */
function shippedWasm(): string {
  const scanner = resolve(process.cwd(), 'src/app/core/scanner.ts');
  const source = readFileSync(scanner, 'utf8');
  const path = /from\s+'([^']+\/zxing_reader\.wasm)'\s+with\s*\{\s*loader:\s*'file'\s*\}/.exec(source)?.[1];

  if (!path) throw new Error(`${scanner} no longer imports zxing_reader.wasm through the file loader.`);

  return resolve(dirname(scanner), path);
}

const wasmFile = shippedWasm();

/**
 * The .wasm the ponyfill's own pinned dependency installed, wherever npm put
 * it: the copy that is the same release as the JavaScript the ponyfill carries.
 */
function ponyfillsOwnWasm(): string {
  const ponyfill = createRequire(resolve(process.cwd(), 'package.json')).resolve('barcode-detector/ponyfill');

  return createRequire(ponyfill).resolve('zxing-wasm/reader/zxing_reader.wasm');
}

/**
 * jsdom draws nothing, so it has no ImageData. The ponyfill only needs the
 * shape — pixels, width and height — and to recognise it by class.
 */
class TestImageData {
  constructor(
    readonly data: Uint8ClampedArray,
    readonly width: number,
    readonly height: number,
  ) {}
}

/** A ticket's QR code as a camera frame: black on white, with the quiet zone a scanner needs. */
function frameOf(code: string, scale = 4, quiet = 4): ImageData {
  const { modules } = QRCode.create(code, { errorCorrectionLevel: 'M' });
  const side = (modules.size + quiet * 2) * scale;
  const data = new Uint8ClampedArray(side * side * 4).fill(255);

  for (let row = 0; row < modules.size; row++) {
    for (let col = 0; col < modules.size; col++) {
      if (!modules.get(row, col)) continue;

      for (let y = 0; y < scale; y++) {
        for (let x = 0; x < scale; x++) {
          const at = (((row + quiet) * scale + y) * side + (col + quiet) * scale + x) * 4;

          data[at] = data[at + 1] = data[at + 2] = 0;
        }
      }
    }
  }

  return new ImageData(data, side, side);
}

/** A preview element that has frames, which jsdom's never does on its own. */
function preview(): HTMLVideoElement {
  const video = document.createElement('video');

  Object.defineProperty(video, 'readyState', { value: HTMLMediaElement.HAVE_ENOUGH_DATA });
  video.play = vi.fn(async () => undefined);

  return video;
}

/** A camera that is only a track that can be stopped. */
function camera() {
  const track = { stop: vi.fn(), addEventListener: vi.fn() };
  const stream = { getTracks: () => [track], getVideoTracks: () => [track] } as unknown as MediaStream;

  return { track, stream };
}

function giveCamera(getUserMedia: (constraints: MediaStreamConstraints) => Promise<MediaStream>) {
  Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia }, configurable: true });
}

/** An engine with its own BarcodeDetector, reading the given formats. */
function engineDetector(formats: string[], detect = vi.fn(async () => [] as { rawValue: string }[])) {
  const Engine = class {
    static getSupportedFormats = async () => formats;
    detect = detect;
  };

  vi.stubGlobal('BarcodeDetector', Engine);

  return detect;
}

/**
 * Reading a ticket with the camera.
 *
 * Two things have to hold. On an iPhone, where the native scanner cannot be
 * linked, the camera inside the page has to actually read a code — so that is
 * tested with a real QR code through the real WebAssembly, not a stand-in.
 * And it has to do it with nothing from the network: a door is where the
 * signal goes, so every request the decoder makes is caught and checked.
 */
describe('Scanner', () => {
  const requests: string[] = [];

  beforeAll(() => {
    const wasm = readFileSync(wasmFile);

    vi.stubGlobal('ImageData', globalThis.ImageData ?? TestImageData);

    // The only thing served is this app's own copy of ZXing, at the address
    // the scanner asks for. Anything else — a CDN above all — fails.
    vi.stubGlobal('fetch', async (input: string | URL | Request) => {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;

      requests.push(url);

      if (url === zxingWasmUrl()) {
        return new Response(wasm, { headers: { 'Content-Type': 'application/wasm' } });
      }

      throw new TypeError(`No network at the door: ${url}`);
    });
  });

  afterAll(() => {
    vi.unstubAllGlobals();
  });

  let scanner: Scanner;

  beforeEach(() => {
    fake.native = false;
    fake.platform = 'web';
    fake.linked = false;
    fake.scans = 0;

    vi.stubGlobal('BarcodeDetector', undefined);
    Object.defineProperty(document, 'hidden', { value: false, configurable: true });

    TestBed.resetTestingModule();
    scanner = TestBed.inject(Scanner);
  });

  afterEach(async () => {
    await scanner.stop();
    vi.useRealTimers();
    delete (navigator as { mediaDevices?: unknown }).mediaDevices;
    document.documentElement.classList.remove('scanning');
  });

  describe('the ponyfill, which is what an iPhone reads with', () => {
    it('reads a real QR code, with ZXing loaded from this app and nowhere else', async () => {
      const { detector, kind } = await openDetector();

      expect(kind).toBe('ponyfill');

      const found = await detector.detect(frameOf('WFY7-F77K4EJW'));

      expect(found.map((code) => code.rawValue)).toEqual(['WFY7-F77K4EJW']);

      const origin = new URL(document.baseURI).origin;

      expect(requests.length).toBeGreaterThan(0);
      expect(requests.every((url) => new URL(url).origin === origin)).toBe(true);
      expect(requests.some((url) => /jsdelivr|unpkg/.test(url))).toBe(false);
      expect([...new Set(requests)]).toEqual([zxingWasmUrl()]);
    });

    it('reads nothing from a frame with no code in it', async () => {
      const { detector } = await openDetector();
      const blank = new ImageData(new Uint8ClampedArray(160 * 120 * 4).fill(255), 160, 120);

      expect(await detector.detect(blank)).toEqual([]);
    });

    it('ships the same ZXing release the ponyfill was built against', async () => {
      // The JavaScript and the .wasm are one release; a mismatch fails at a
      // door, on a phone, with an error nobody there can read. Hashed from the
      // file the build takes, so a copy that drifts fails here and not there.
      const { ZXING_WASM_SHA256 } = await import('barcode-detector/ponyfill');
      const shipped = createHash('sha256').update(readFileSync(wasmFile)).digest('hex');

      expect(shipped).toBe(ZXING_WASM_SHA256);
    });

    it('ships the copy the ponyfill installed for itself, not another one npm put beside it', () => {
      // If another app in the repository pulls a different ZXing, npm moves
      // this app's copy down beside the ponyfill and puts the other one where
      // scanner.ts looks. This says which file to point it at instead.
      expect(realpathSync.native(wasmFile)).toBe(realpathSync.native(ponyfillsOwnWasm()));
    });
  });

  describe('which detector a page gets', () => {
    it("uses the engine's own when it reads QR codes", async () => {
      engineDetector(['qr_code', 'ean_13']);

      expect((await openDetector()).kind).toBe('engine');
    });

    it('uses the ponyfill when the engine has the class and reads nothing, as Chrome on Windows does', async () => {
      engineDetector([]);

      expect((await openDetector()).kind).toBe('ponyfill');
    });
  });

  describe('which reader a phone gets', () => {
    it('reads inside the page on an iPhone, where ML Kit is installed but never linked', async () => {
      fake.native = true;
      fake.platform = 'ios';
      const { stream } = camera();
      const getUserMedia = vi.fn(async () => stream);

      giveCamera(getUserMedia);

      expect(await scanner.supported()).toBe(true);
      expect(scanner.engine()).toBe('webview');

      await scanner.start(() => undefined, preview());

      expect(scanner.running()).toBe(true);
      expect(getUserMedia).toHaveBeenCalledWith(
        expect.objectContaining({ video: expect.objectContaining({ facingMode: { ideal: 'environment' } }) }),
      );
      expect(fake.scans).toBe(0);
      // The page keeps its background: the camera is in it, not behind it.
      expect(document.documentElement.classList.contains('scanning')).toBe(false);
    });

    it('uses ML Kit on Android, where it is linked', async () => {
      fake.native = true;
      fake.platform = 'android';
      fake.linked = true;

      expect(await scanner.supported()).toBe(true);
      expect(scanner.engine()).toBe('mlkit');

      await scanner.start(() => undefined);

      expect(fake.scans).toBe(1);
      expect(document.documentElement.classList.contains('scanning')).toBe(true);

      await scanner.stop();

      expect(document.documentElement.classList.contains('scanning')).toBe(false);
    });

    it('says it cannot scan where there is no camera to ask for', async () => {
      expect(await scanner.supported()).toBe(false);

      await scanner.start(() => undefined, preview());

      expect(scanner.refusal()).toBe('unsupported');
      expect(scanner.running()).toBe(false);
    });
  });

  describe('the camera inside the page', () => {
    it('reads a few times a second rather than every frame, and hands over what it reads', async () => {
      vi.useFakeTimers();
      const detect = engineDetector(['qr_code'], vi.fn(async () => [{ rawValue: 'WFY7-F77K4EJW' }]));
      const { stream } = camera();
      const seen: string[] = [];

      giveCamera(async () => stream);

      await scanner.start((code) => seen.push(code), preview());
      await vi.advanceTimersByTimeAsync(1000);

      const perSecond = 1000 / READ_EVERY_MS;

      expect(detect.mock.calls.length).toBeGreaterThanOrEqual(perSecond - 1);
      expect(detect.mock.calls.length).toBeLessThanOrEqual(perSecond + 1);
      expect(seen[0]).toBe('WFY7-F77K4EJW');
    });

    it('stops cleanly: the camera off, the preview empty, and no more reading', async () => {
      vi.useFakeTimers();
      const detect = engineDetector(['qr_code']);
      const { track, stream } = camera();
      const video = preview();

      giveCamera(async () => stream);

      await scanner.start(() => undefined, video);
      await vi.advanceTimersByTimeAsync(READ_EVERY_MS * 2);
      await scanner.stop();

      const readsAtStop = detect.mock.calls.length;

      await vi.advanceTimersByTimeAsync(READ_EVERY_MS * 10);

      expect(track.stop).toHaveBeenCalled();
      expect(video.srcObject).toBeNull();
      expect(scanner.running()).toBe(false);
      expect(detect.mock.calls.length).toBe(readsAtStop);
    });

    it('leaves no camera running when stopped while the permission question is still up', async () => {
      engineDetector(['qr_code']);
      const { track, stream } = camera();
      let answer!: (stream: MediaStream) => void;

      giveCamera(() => new Promise<MediaStream>((resolve) => (answer = resolve)));

      const starting = scanner.start(() => undefined, preview());

      await vi.waitFor(() => expect(answer).toBeDefined());
      await scanner.stop();
      answer(stream);
      await starting;

      expect(track.stop).toHaveBeenCalled();
      expect(scanner.running()).toBe(false);
    });

    it('tells a refused camera from a missing one', async () => {
      giveCamera(async () => {
        throw new DOMException('Permission denied', 'NotAllowedError');
      });

      await scanner.start(() => undefined, preview());

      expect(scanner.refusal()).toBe('permission');

      giveCamera(async () => {
        throw new DOMException('Requested device not found', 'NotFoundError');
      });

      await scanner.start(() => undefined, preview());

      expect(scanner.refusal()).toBe('no-camera');
    });
  });
});
