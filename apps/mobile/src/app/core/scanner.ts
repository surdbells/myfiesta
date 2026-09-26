import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { BarcodeFormat, BarcodeScanner, LensFacing } from '@capacitor-mlkit/barcode-scanning';
/*
 * The ZXing decoder the ponyfill runs, emitted into this app's own build and
 * imported here as nothing more than its address. Left to itself the library
 * fetches this file from jsDelivr, which is a door that cannot scan the moment
 * the venue's signal goes — and a door is exactly where the signal goes.
 *
 * Imported through the build rather than copied in by an asset rule because
 * Angular will not copy from outside this app's folder, and npm keeps the
 * package at the root of the repository. By path rather than by package name
 * because the development server hands package imports to Vite, which refuses
 * a .wasm outright; a path the build resolves itself works the same in
 * development, in tests and in a release. It is the copy the ponyfill's own
 * dependency installed, so the two are one release — the JavaScript and the
 * .wasm have to be. The spec finds the file by reading this import, hashes it
 * against the release the ponyfill was built for, and fails if npm ever puts
 * the ponyfill's own copy somewhere other than where this path points.
 */
import zxingReaderWasm from '../../../../../node_modules/zxing-wasm/dist/reader/zxing_reader.wasm' with { loader: 'file' };

/** Why the camera is not running, in words the door can act on. */
export type ScannerRefusal =
  | 'unsupported'
  | 'permission'
  | 'no-camera'
  | 'unavailable'
  | null;

/**
 * Which reader a device gets.
 *
 * `mlkit` is the native scanner, drawing the camera behind the page. `webview`
 * is the camera inside the page, read by a BarcodeDetector — the engine's own
 * or the ZXing ponyfill standing in for it.
 */
export type ScannerEngine = 'mlkit' | 'webview' | null;

/** How often the WebView path looks at a frame, at most. */
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

/** Where ZXing's WebAssembly is served from: this app's own files, never a CDN. */
export function zxingWasmUrl(): string {
  return new URL(zxingReaderWasm, document.baseURI).href;
}

let ponyfill: Promise<CodeDetectorClass> | null = null;

/**
 * The ponyfill, loaded once and pointed at this app's own copy of ZXing.
 *
 * Loaded when a door first starts the camera rather than with the app: it is
 * a megabyte of WebAssembly that most people opening the app to show a ticket
 * never need. Once compiled it stays, so a door that stops for a sale and
 * starts again does not wait twice.
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
 * A detector for QR codes: the engine's own where it has one that reads them,
 * the ponyfill everywhere else.
 *
 * Chrome on Windows has a BarcodeDetector that supports no formats at all, so
 * having the class is not the question — reading `qr_code` is.
 */
export async function openDetector(): Promise<OpenedDetector> {
  const Engine = (globalThis as { BarcodeDetector?: CodeDetectorClass }).BarcodeDetector;

  if (typeof Engine === 'function') {
    try {
      const formats = (await Engine.getSupportedFormats?.()) ?? [];

      if (formats.includes('qr_code')) return { detector: new Engine({ formats: ['qr_code'] }), kind: 'engine' };
    } catch {
      // An engine that cannot say what it reads is treated as one that reads nothing.
    }
  }

  const Ponyfill = await loadPonyfill();

  return { detector: new Ponyfill({ formats: ['qr_code'] }), kind: 'ponyfill' };
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
function refusalFor(error: unknown): ScannerRefusal {
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

/**
 * Reading a ticket with the camera.
 *
 * Two ways to read behind one interface, because the platforms genuinely
 * differ:
 *
 * - On Android, ML Kit reads the code natively. It is faster in the dark and
 *   at an angle than anything a WebView can do, which is the whole job: a
 *   queue moves at the speed of the worst scan, not the average one.
 * - On iPhone the camera runs inside the page. ML Kit ships only for
 *   CocoaPods and this project links its plugins through Swift Package
 *   Manager, so the plugin is never there; the WebView reads instead, with
 *   ZXing compiled to WebAssembly standing in for the BarcodeDetector Safari
 *   does not have. Browsers take the same path, which is how a laptop in
 *   development scans at all.
 *
 * Which one a device gets is decided by what it has — whether the plugin is
 * actually linked — not by what it is called, so linking ML Kit on iOS one day
 * moves iPhones back to it without touching this file.
 *
 * Either way typing the code stays on screen. A cracked lens, a flat battery
 * and a guest whose screen will not brighten all end there, and that is not a
 * moment to be hunting for a fallback.
 *
 * The native scanner draws the camera *behind* the WebView, so starting it
 * makes the page transparent and stopping it puts the background back. Leaving
 * that class on is a door screen with no background at all, which is why
 * stopping cleans up whichever path ran, and why a stop that lands while the
 * camera is still starting cancels the start rather than racing it.
 */
@Injectable({ providedIn: 'root' })
export class Scanner {
  private readonly document = inject(DOCUMENT);

  readonly running = signal(false);
  readonly refusal = signal<ScannerRefusal>(null);
  /** Null until decided, and then fixed: what a phone can do does not change while the app is open. */
  readonly engine = signal<ScannerEngine>(null);

  private decided: Promise<ScannerEngine> | null = null;
  private starting = false;
  /** Bumped by every stop, so a start still waiting on the camera knows it has been called off. */
  private attempt = 0;
  private stopWeb: (() => void) | null = null;

  /** Whether this device can read a code at all, before anything is asked of it. */
  async supported(): Promise<boolean> {
    return (await this.decide()) !== null;
  }

  /**
   * Start reading. `onCode` fires for every code seen, deduplicated by the
   * caller — a camera pointed at a ticket reads it many times a second.
   *
   * @param video where to draw the preview on the WebView path; unused by ML
   *              Kit, whose camera is behind the page.
   */
  async start(onCode: (code: string) => void, video?: HTMLVideoElement): Promise<void> {
    if (this.running() || this.starting) return;

    this.starting = true;
    this.refusal.set(null);

    const attempt = this.attempt;

    try {
      const engine = await this.decide();

      if (attempt !== this.attempt) return;

      if (engine === 'mlkit') await this.startNative(onCode, attempt);
      else if (engine === 'webview') await this.startWebView(onCode, attempt, video);
      else this.refusal.set('unsupported');
    } finally {
      this.starting = false;
    }
  }

  async stop(): Promise<void> {
    this.attempt++;

    if (this.engine() === 'mlkit') await this.stopNative();

    this.stopWeb?.();
    this.stopWeb = null;
    this.running.set(false);
  }

  private decide(): Promise<ScannerEngine> {
    this.decided ??= this.pick().then((engine) => {
      this.engine.set(engine);

      return engine;
    });

    return this.decided;
  }

  private async pick(): Promise<ScannerEngine> {
    // Linked is the question, not native: on iPhone the plugin is installed and
    // absent, and asking it anything throws.
    if (Capacitor.isNativePlatform() && Capacitor.isPluginAvailable('BarcodeScanner')) {
      try {
        const { supported } = await BarcodeScanner.isSupported();

        if (supported) return 'mlkit';
      } catch {
        // Linked but unwilling; the page can still try.
      }
    }

    const camera = typeof globalThis.navigator?.mediaDevices?.getUserMedia === 'function';
    const decoder =
      typeof (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector === 'function' ||
      typeof globalThis.WebAssembly === 'object';

    return camera && decoder ? 'webview' : null;
  }

  // --- ML Kit, on Android ------------------------------------------------------

  private async startNative(onCode: (code: string) => void, attempt: number): Promise<void> {
    const granted = await this.permission();

    if (attempt !== this.attempt) return;

    if (!granted) {
      this.refusal.set('permission');

      return;
    }

    try {
      // Google's scanning module is downloaded on first use on some Android
      // builds; asking for it before starting turns a silent no-op into a
      // wait that ends in a working camera.
      if (Capacitor.getPlatform() === 'android') {
        const { available } = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable();

        if (!available) await BarcodeScanner.installGoogleBarcodeScannerModule();
      }

      await BarcodeScanner.addListener('barcodesScanned', ({ barcodes }) => {
        for (const barcode of barcodes) {
          if (barcode.rawValue) onCode(barcode.rawValue);
        }
      });

      // The camera renders behind the WebView; the page has to get out of the
      // way for it to be visible.
      this.document.documentElement.classList.add('scanning');

      await BarcodeScanner.startScan({
        formats: [BarcodeFormat.QrCode],
        lensFacing: LensFacing.Back,
      });

      // Left while the camera was coming up: put the page back rather than
      // leave a camera running behind a screen nobody is looking at.
      if (attempt !== this.attempt) {
        await this.stopNative();

        return;
      }

      this.running.set(true);
    } catch {
      this.document.documentElement.classList.remove('scanning');
      this.refusal.set('unavailable');
    }
  }

  private async stopNative(): Promise<void> {
    try {
      await BarcodeScanner.removeAllListeners();
      await BarcodeScanner.stopScan();
    } catch {
      // Already stopped, or the plugin is gone with the page.
    }

    this.document.documentElement.classList.remove('scanning');
  }

  private async permission(): Promise<boolean> {
    try {
      const { camera } = await BarcodeScanner.checkPermissions();

      if (camera === 'granted' || camera === 'limited') return true;

      const asked = await BarcodeScanner.requestPermissions();

      return asked.camera === 'granted' || asked.camera === 'limited';
    } catch {
      return false;
    }
  }

  // --- the camera inside the page: every iPhone, and any browser --------------

  /**
   * getUserMedia into the preview, and a detector reading frames off it a few
   * times a second.
   *
   * A few, not sixty: the door ignores a repeat for four seconds anyway, and a
   * phone decoding every frame in WebAssembly is a phone too hot to hold by
   * the end of the night.
   */
  private async startWebView(onCode: (code: string) => void, attempt: number, video?: HTMLVideoElement): Promise<void> {
    if (!video) {
      this.refusal.set('unavailable');

      return;
    }

    // Asked for before the camera is, so the decoder's first load happens
    // while the permission question is on screen rather than after it.
    const opening = openDetector();

    opening.catch(() => undefined);

    let stream: MediaStream;

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      });
    } catch (error) {
      if (attempt === this.attempt) this.refusal.set(refusalFor(error));

      return;
    }

    const release = () => {
      stream.getTracks().forEach((track) => track.stop());

      if (video.srcObject === stream) video.srcObject = null;
    };

    // Stopped while the permission question was up: the camera light must not
    // come on for a screen that has already gone.
    if (attempt !== this.attempt) {
      release();

      return;
    }

    let opened: OpenedDetector;

    try {
      opened = await opening;
    } catch {
      release();

      if (attempt === this.attempt) this.refusal.set('unavailable');

      return;
    }

    if (attempt !== this.attempt) {
      release();

      return;
    }

    const grab = opened.kind === 'ponyfill' ? frameGrabber(video) : null;
    let live = true;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const read = async () => {
      const began = Date.now();

      // Nothing to read in a frame that has not arrived, or on a screen nobody
      // can see.
      if (!this.document.hidden && video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
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

    this.stopWeb = () => {
      live = false;
      clearTimeout(timer);
      release();
    };

    // iOS ends the camera when the app goes to the background. The door goes
    // back to its button rather than showing a black frame that never reads.
    for (const track of stream.getVideoTracks()) {
      track.addEventListener('ended', () => {
        if (live) void this.stop();
      });
    }

    // muted and playsinline, or iOS refuses to play it inline and Chrome
    // blocks autoplay outright.
    video.muted = true;
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');
    video.setAttribute('autoplay', '');
    video.srcObject = stream;

    // Running before play() rather than after, so the preview unfolds as it
    // starts: WebKit may hold back a video it thinks nobody can see.
    this.running.set(true);

    await video.play().catch(() => undefined);

    if (live) void read();
  }
}
