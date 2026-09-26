import { createHash } from 'node:crypto';
import { readFileSync, realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import QRCode from 'qrcode';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  PageCamera,
  READ_EVERY_MS,
  canScanInPage,
  fetchDecoderAhead,
  openDetector,
  zxingWasmUrl,
} from '@myfiesta/door';

/** The door package, which the console compiles as source and whose camera its door reads with. */
const doorPackage = resolve(process.cwd(), '../../packages/door');

/**
 * The .wasm the console ships: the very file the shared camera imports, found
 * by reading that import rather than by asking Node where the package is.
 *
 * The two can disagree. Node looks in the nearest node_modules first; the
 * import goes by path to the one at the root of the repository. They are the
 * same file only while npm keeps a single copy, and a spec that checked Node's
 * copy would stay green on the day it kept two — while every iPhone at a door
 * loaded the other one.
 */
function shippedWasm(): string {
  const camera = resolve(doorPackage, 'src/camera.ts');
  const source = readFileSync(camera, 'utf8');
  const path = /from\s+'([^']+\/zxing_reader\.wasm)'\s+with\s*\{\s*loader:\s*'file'\s*\}/.exec(source)?.[1];

  if (!path) throw new Error(`${camera} no longer imports zxing_reader.wasm through the file loader.`);

  return resolve(dirname(camera), path);
}

const wasmFile = shippedWasm();

/**
 * The .wasm the ponyfill's own pinned dependency installed, wherever npm put
 * it: the copy that is the same release as the JavaScript the ponyfill
 * carries. Asked from the door package, which is where the ponyfill is
 * imported.
 */
function ponyfillsOwnWasm(): string {
  const ponyfill = createRequire(resolve(doorPackage, 'package.json')).resolve('barcode-detector/ponyfill');

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

/** A preview element that has frames of the given size, which jsdom's never does on its own. */
function preview(width = 1280, height = 720): HTMLVideoElement {
  const video = document.createElement('video');

  Object.defineProperty(video, 'readyState', { value: HTMLMediaElement.HAVE_ENOUGH_DATA });
  Object.defineProperty(video, 'videoWidth', { value: width });
  Object.defineProperty(video, 'videoHeight', { value: height });
  video.play = vi.fn(async () => undefined);

  return video;
}

/** A camera that is only a track that can be stopped — or be taken away. */
function camera() {
  const ended: (() => void)[] = [];
  const track = {
    stop: vi.fn(),
    addEventListener: vi.fn((type: string, listener: () => void) => {
      if (type === 'ended') ended.push(listener);
    }),
  };
  const stream = { getTracks: () => [track], getVideoTracks: () => [track] } as unknown as MediaStream;

  return { track, stream, end: () => ended.forEach((listener) => listener()) };
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
 * The camera the console's door reads with, from `@myfiesta/door`.
 *
 * The console used to read only with the browser's own BarcodeDetector, which
 * Safari does not have — so an iPhone opening the door screen could not scan
 * at all. It now reads with the phone app's camera, ZXing compiled to
 * WebAssembly standing in where the browser has no detector. So this is
 * tested with a real QR code through the real WebAssembly, in the console's
 * own build. And with nothing from the network except the console itself: a
 * door is where the signal goes, so every request the decoder makes is caught
 * and checked.
 */
describe('the camera at the door', () => {
  const requests: string[] = [];

  beforeAll(() => {
    const wasm = readFileSync(wasmFile);

    vi.stubGlobal('ImageData', globalThis.ImageData ?? TestImageData);

    // The only thing served is the console's own copy of ZXing, at the
    // address the camera asks for. Anything else — a CDN above all — fails.
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

  let reader: PageCamera;

  beforeEach(() => {
    vi.stubGlobal('BarcodeDetector', undefined);
    Object.defineProperty(document, 'hidden', { value: false, configurable: true });

    reader = new PageCamera();
  });

  afterEach(() => {
    reader.stop();
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (navigator as { mediaDevices?: unknown }).mediaDevices;
  });

  describe('the ponyfill, which is what an iPhone reads with', () => {
    it('reads a real QR code, with ZXing loaded from the console and nowhere else', async () => {
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
      // the ponyfill's copy down beside it and puts the other one where the
      // shared camera looks. This says which file to point it at instead.
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

  describe('whether a door can use the camera at all', () => {
    it('can on an iPhone: a camera to ask for, WebAssembly, and no BarcodeDetector', () => {
      giveCamera(async () => camera().stream);

      expect(canScanInPage()).toBe(true);
    });

    it('cannot where there is no camera to ask for, as over plain http', () => {
      expect(canScanInPage()).toBe(false);
    });
  });

  describe('the decoder, fetched while there is signal', () => {
    it('comes from the console itself, on an engine that will need it', async () => {
      requests.length = 0;

      await fetchDecoderAhead();

      expect(requests).toEqual([zxingWasmUrl()]);
    });

    it('is not fetched where the engine reads QR codes itself', async () => {
      engineDetector(['qr_code']);
      requests.length = 0;

      await fetchDecoderAhead();

      expect(requests).toEqual([]);
    });
  });

  describe('the camera inside the page', () => {
    it('reads a ticket off the preview with ZXing, as an iPhone does', async () => {
      const frame = frameOf('WFY7-F77K4EJW');
      const { stream } = camera();
      const seen: string[] = [];

      // jsdom cannot draw a video. The canvas the frames go through hands back
      // the ticket instead, which is everything after the lens.
      vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
        drawImage: vi.fn(),
        getImageData: () => frame,
      } as unknown as CanvasRenderingContext2D);
      giveCamera(async () => stream);

      expect(await reader.start(preview(frame.width, frame.height), (code) => seen.push(code))).toBeNull();

      await vi.waitFor(() => expect(seen[0]).toBe('WFY7-F77K4EJW'), { timeout: 5000 });
    });

    it('reads a few times a second rather than every frame, and hands over what it reads', async () => {
      vi.useFakeTimers();
      const detect = engineDetector(['qr_code'], vi.fn(async () => [{ rawValue: 'WFY7-F77K4EJW' }]));
      const { stream } = camera();
      const seen: string[] = [];
      const live = vi.fn();

      giveCamera(async () => stream);

      await reader.start(preview(), (code) => seen.push(code), { onLive: live });
      await vi.advanceTimersByTimeAsync(1000);

      const perSecond = 1000 / READ_EVERY_MS;

      expect(live).toHaveBeenCalledOnce();
      expect(detect.mock.calls.length).toBeGreaterThanOrEqual(perSecond - 1);
      expect(detect.mock.calls.length).toBeLessThanOrEqual(perSecond + 1);
      expect(seen[0]).toBe('WFY7-F77K4EJW');
    });

    it('asks for the back camera', async () => {
      engineDetector(['qr_code']);
      const getUserMedia = vi.fn(async () => camera().stream);

      giveCamera(getUserMedia);

      await reader.start(preview(), () => undefined);

      expect(getUserMedia).toHaveBeenCalledWith(
        expect.objectContaining({ video: expect.objectContaining({ facingMode: { ideal: 'environment' } }) }),
      );
    });

    it('stops cleanly: the camera off, the preview empty, and no more reading', async () => {
      vi.useFakeTimers();
      const detect = engineDetector(['qr_code']);
      const { track, stream } = camera();
      const video = preview();

      giveCamera(async () => stream);

      await reader.start(video, () => undefined);
      await vi.advanceTimersByTimeAsync(READ_EVERY_MS * 2);
      reader.stop();

      const readsAtStop = detect.mock.calls.length;

      await vi.advanceTimersByTimeAsync(READ_EVERY_MS * 10);

      expect(track.stop).toHaveBeenCalled();
      expect(video.srcObject).toBeNull();
      expect(detect.mock.calls.length).toBe(readsAtStop);
    });

    it('leaves no camera running when stopped while the permission question is still up', async () => {
      engineDetector(['qr_code']);
      const { track, stream } = camera();
      const live = vi.fn();
      let answer!: (stream: MediaStream) => void;

      giveCamera(() => new Promise<MediaStream>((resolve) => (answer = resolve)));

      const starting = reader.start(preview(), () => undefined, { onLive: live });

      await vi.waitFor(() => expect(answer).toBeDefined());
      reader.stop();
      answer(stream);

      expect(await starting).toBeNull();
      expect(track.stop).toHaveBeenCalled();
      expect(live).not.toHaveBeenCalled();
    });

    it('stops, and says so, when the camera is taken away', async () => {
      vi.useFakeTimers();
      const detect = engineDetector(['qr_code']);
      const { track, stream, end } = camera();
      const ended = vi.fn();

      giveCamera(async () => stream);

      await reader.start(preview(), () => undefined, { onEnded: ended });
      end();

      const readsAtEnd = detect.mock.calls.length;

      await vi.advanceTimersByTimeAsync(READ_EVERY_MS * 5);

      expect(ended).toHaveBeenCalledOnce();
      expect(track.stop).toHaveBeenCalled();
      expect(detect.mock.calls.length).toBe(readsAtEnd);
    });

    it('tells a refused camera from a missing one', async () => {
      giveCamera(async () => {
        throw new DOMException('Permission denied', 'NotAllowedError');
      });

      expect(await reader.start(preview(), () => undefined)).toBe('permission');

      giveCamera(async () => {
        throw new DOMException('Requested device not found', 'NotFoundError');
      });

      expect(await reader.start(preview(), () => undefined)).toBe('no-camera');
    });
  });
});
