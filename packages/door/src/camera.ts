/// <reference path="./wasm.d.ts" />
/*
 * The ZXing decoder the ponyfill runs, emitted into the build of whichever app
 * imports this file and imported here as nothing more than its address. Left
 * to itself the library fetches this file from jsDelivr, which is a door that
 * cannot scan the moment the venue's signal goes — and a door is exactly where
 * the signal goes.
 *
 * Imported through the build rather than copied in by an asset rule because
 * Angular will not copy from outside an app's folder, and npm keeps the
 * package at the root of the repository. By path rather than by package name
 * because the development server hands package imports to Vite, which refuses
 * a .wasm outright; a path the build resolves itself works the same in
 * development, in tests and in a release, and the same for the console as for
 * the phone app, since both compile this file as their own source. It is the
 * copy the ponyfill's own dependency installed, so the two are one release —
 * the JavaScript and the .wasm have to be. The console's door-camera spec
 * finds the file by reading this import, hashes it against the release the
 * ponyfill was built for, and fails if npm ever puts the ponyfill's own copy
 * somewhere other than where this path points.
 */
import zxingReaderWasm from '../../../node_modules/zxing-wasm/dist/reader/zxing_reader.wasm' with { loader: 'file' };

/** Why the camera in the page is not running, in words a door can act on. */
export type CameraRefusal = 'permission' | 'no-camera' | 'unavailable';

/** How often the camera in the page looks at a frame, at most. */
export const READ_EVERY_MS = 200;

/**
 * The longest side of the frame handed to the ponyfill. A camera delivering
 * 1280 across is sending more pixels than a ticket needs, and every one of
 * them is copied and converted in JavaScript before ZXing sees it.
 */
const LONGEST_SIDE = 960;

/** The part of BarcodeDetector the door uses. The engine's own and the ponyfill both have it. */
export interface CodeDetector {
  detect(source: HTMLVideoElement | ImageData): Promise<readonly { rawValue: string }[]>;
}

interface CodeDetectorClass {
  new (options: { formats: string[] }): CodeDetector;
  getSupportedFormats?(): Promise<readonly string[]>;
}

/** A detector, and whose it is — the two are fed differently. */
export interface OpenedDetector {
  detector: CodeDetector;
  kind: 'engine' | 'ponyfill';
}

/** Where ZXing's WebAssembly is served from: the app's own files, never a CDN. */
export function zxingWasmUrl(): string {
  return new URL(zxingReaderWasm, document.baseURI).href;
}

let ponyfill: Promise<CodeDetectorClass> | null = null;

/**
 * The ponyfill, loaded once and pointed at the app's own copy of ZXing.
 *
 * Loaded when a door first starts the camera rather than with the app: it is
 * a megabyte of WebAssembly that most people opening either app never need.
 * Once compiled it stays, so a door that stops for a sale and starts again
 * does not wait twice.
 *
 * A load that fails is forgotten rather than kept, so the next tap tries
 * again instead of replaying the same failure all night.
 */
function loadPonyfill(): Promise<CodeDetectorClass> {
  ponyfill ??= (async () => {
    const { BarcodeDetector, prepareZXingModule, purgeZXingModule } = await import('barcode-detector/ponyfill');
    const wasm = zxingWasmUrl();

    try {
      // Waited for here, not left to the first frame, so a decoder that cannot
      // load is a refusal the door can say out loud rather than a camera that
      // silently never reads anything.
      await prepareZXingModule({
        overrides: {
          locateFile: (path: string, prefix: string) => (path.endsWith('.wasm') ? wasm : prefix + path),
        },
        fireImmediately: true,
      });
    } catch (error) {
      purgeZXingModule();

      throw error;
    }

    return BarcodeDetector as unknown as CodeDetectorClass;
  })().catch((error: unknown) => {
    ponyfill = null;

    throw error;
  });

  return ponyfill;
}

/**
 * The engine's own BarcodeDetector, if it has one that reads QR codes.
 *
 * Chrome on Windows has a BarcodeDetector that supports no formats at all, so
 * having the class is not the question — reading `qr_code` is.
 */
async function engineReader(): Promise<CodeDetectorClass | null> {
  const Engine = (globalThis as { BarcodeDetector?: CodeDetectorClass }).BarcodeDetector;

  if (typeof Engine !== 'function') return null;

  try {
    const formats = (await Engine.getSupportedFormats?.()) ?? [];

    return formats.includes('qr_code') ? Engine : null;
  } catch {
    // An engine that cannot say what it reads is treated as one that reads nothing.
    return null;
  }
}

/** A detector for QR codes: the engine's own where it has one that reads them, the ponyfill everywhere else. */
export async function openDetector(): Promise<OpenedDetector> {
  const Engine = await engineReader();

  if (Engine) return { detector: new Engine({ formats: ['qr_code'] }), kind: 'engine' };

  const Ponyfill = await loadPonyfill();

  return { detector: new Ponyfill({ formats: ['qr_code'] }), kind: 'ponyfill' };
}

/**
 * Whether a camera inside the page could read a code here: a camera to ask
 * for, and something to decode with — the engine's own detector, or
 * WebAssembly for the ponyfill. Every current browser has the second, Safari
 * included, so in practice this is the camera.
 *
 * Browsers only offer the camera to a secure context, so a page opened over
 * plain http has none to ask for, whatever the device.
 */
export function canScanInPage(): boolean {
  const camera = typeof globalThis.navigator?.mediaDevices?.getUserMedia === 'function';
  const decoder =
    typeof (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector === 'function' ||
    typeof globalThis.WebAssembly === 'object';

  return camera && decoder;
}

/**
 * Fetch ZXing now, without compiling it, on an engine that will need it.
 *
 * For an app whose files a service worker keeps, which keeps this one only
 * once it has been asked for. A door opened with signal whose camera is first
 * started after the signal has gone would otherwise find everything saved but
 * the decoder. Fetched rather than compiled: the download happens once, while
 * compiling would spend battery on every door that opens and never scans.
 */
export async function fetchDecoderAhead(): Promise<void> {
  if (typeof globalThis.WebAssembly !== 'object' || (await engineReader())) return;

  try {
    await (await fetch(zxingWasmUrl())).arrayBuffer();
  } catch {
    // No signal now. Starting the camera asks again.
  }
}

/**
 * Frames off the preview, shrunk and handed over as pixels.
 *
 * Drawn into one canvas that is kept rather than a new one each time: given
 * the video itself, the ponyfill makes a fresh full-size canvas for every
 * frame, and on an older iPhone that is where the time goes.
 */
function frameGrabber(video: HTMLVideoElement): () => ImageData | null {
  const canvas = video.ownerDocument.createElement('canvas');
  let context: CanvasRenderingContext2D | null = null;

  return () => {
    const { videoWidth, videoHeight } = video;

    if (!videoWidth || !videoHeight) return null;

    const scale = Math.min(1, LONGEST_SIDE / Math.max(videoWidth, videoHeight));
    const width = Math.round(videoWidth * scale);
    const height = Math.round(videoHeight * scale);

    if (canvas.width !== width) canvas.width = width;
    if (canvas.height !== height) canvas.height = height;

    context ??= canvas.getContext('2d', { willReadFrequently: true });

    if (!context) return null;

    context.drawImage(video, 0, 0, width, height);

    return context.getImageData(0, 0, width, height);
  };
}

/** What a refused camera means for the door, from the name the browser gives it. */
function refusalFor(error: unknown): CameraRefusal {
  switch ((error as { name?: string } | null)?.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return 'permission';
    case 'NotFoundError':
    case 'OverconstrainedError':
      return 'no-camera';
    default:
      return 'unavailable';
  }
}

/** What the screen around the camera hears about. */
export interface PageCameraEvents {
  /** The preview is about to play: show it now, not after. */
  onLive?(): void;
  /** The camera was taken away — the phone locked, the app went to the background — and reading has stopped. */
  onEnded?(): void;
}

/**
 * The camera inside the page: getUserMedia into a preview, and a detector
 * reading frames off it a few times a second.
 *
 * A few, not sixty: a door ignores a repeat for seconds anyway, and a phone
 * decoding every frame in WebAssembly is a phone too hot to hold by the end of
 * the night.
 *
 * Shared by the console's door and the phone app's, which on an iPhone reads
 * this way too, because an iPhone is an iPhone whichever of the two it opened.
 * Safari has no BarcodeDetector, so either door there reads with the ponyfill;
 * so do Firefox and Chrome on Windows, whose detector reads nothing.
 *
 * No framework in it, like the rest of this package. The preview element is
 * the caller's, and has to be in the page before the camera starts — a camera
 * whose video is only created once it is running can never start.
 */
export class PageCamera {
  /** Bumped by every stop, so a start still waiting on the camera knows it has been called off. */
  private attempt = 0;
  private active = false;
  private halt: (() => void) | null = null;

  /**
   * Start reading. `onCode` fires for every code seen, whatever it is, and is
   * checked and deduplicated by the caller — a camera pointed at a ticket reads
   * it many times a second. `ticketCode` and `RepeatReads` are how.
   *
   * @returns null once the camera is reading, or when a stop called the start
   *          off; otherwise why it could not start.
   */
  async start(
    video: HTMLVideoElement,
    onCode: (code: string) => void,
    events: PageCameraEvents = {},
  ): Promise<CameraRefusal | null> {
    if (this.active) return null;

    this.active = true;

    const attempt = this.attempt;
    const current = () => attempt === this.attempt;
    const refuse = (why: CameraRefusal) => {
      if (!current()) return null;

      this.active = false;

      return why;
    };

    // Asked for before the camera is, so the decoder's first load happens
    // while the permission question is on screen rather than after it.
    const opening = openDetector();

    opening.catch(() => undefined);

    let stream: MediaStream;

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          // The back camera. Without this a phone opens the selfie camera and
          // somebody has to hold the guest's ticket behind their own head.
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      });
    } catch (error) {
      return refuse(refusalFor(error));
    }

    const release = () => {
      stream.getTracks().forEach((track) => track.stop());

      if (video.srcObject === stream) video.srcObject = null;
    };

    // Stopped while the permission question was up: the camera light must not
    // come on for a screen that has already gone.
    if (!current()) {
      release();

      return null;
    }

    let opened: OpenedDetector;

    try {
      opened = await opening;
    } catch {
      release();

      return refuse('unavailable');
    }

    if (!current()) {
      release();

      return null;
    }

    const grab = opened.kind === 'ponyfill' ? frameGrabber(video) : null;
    let live = true;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const read = async () => {
      const began = Date.now();

      // Nothing to read in a frame that has not arrived, or on a screen nobody
      // can see.
      if (!video.ownerDocument.hidden && video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
        try {
          const source = grab ? grab() : video;
          const found = source ? await opened.detector.detect(source) : [];

          if (live) {
            for (const code of found) {
              if (code.rawValue) onCode(code.rawValue);
            }
          }
        } catch {
          // A frame that cannot be read is not a failure worth stopping for.
        }
      }

      if (live) timer = setTimeout(() => void read(), Math.max(0, READ_EVERY_MS - (Date.now() - began)));
    };

    this.halt = () => {
      live = false;
      clearTimeout(timer);
      release();
    };

    // iOS ends the camera when the app or the tab goes to the background. The
    // door goes back to its button rather than showing a black frame that
    // never reads.
    for (const track of stream.getVideoTracks()) {
      track.addEventListener('ended', () => {
        if (!live) return;

        this.stop();
        events.onEnded?.();
      });
    }

    // muted and playsinline, or iOS refuses to play it inline and Chrome
    // blocks autoplay outright.
    video.muted = true;
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');
    video.setAttribute('autoplay', '');
    video.srcObject = stream;

    // Said before play() rather than after, so the preview unfolds as it
    // starts: WebKit may hold back a video it thinks nobody can see.
    events.onLive?.();

    await video.play().catch(() => undefined);

    if (live) void read();

    return null;
  }

  /** Stop reading and turn the camera off — or call off a start still waiting on it. */
  stop(): void {
    this.attempt++;
    this.active = false;
    this.halt?.();
    this.halt = null;
  }
}
