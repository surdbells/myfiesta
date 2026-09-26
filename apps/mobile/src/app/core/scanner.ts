import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { BarcodeFormat, BarcodeScanner, LensFacing } from '@capacitor-mlkit/barcode-scanning';
import { PageCamera, canScanInPage } from '@myfiesta/door';

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
 * The camera inside the page is `PageCamera` from `@myfiesta/door`, the same
 * one the console's door reads with — an iPhone scanning from the console and
 * one scanning from this app are the same Safari, and deserve the same reader.
 * Only ML Kit is this app's own.
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
  private readonly camera = new PageCamera();

  readonly running = signal(false);
  readonly refusal = signal<ScannerRefusal>(null);
  /** Null until decided, and then fixed: what a phone can do does not change while the app is open. */
  readonly engine = signal<ScannerEngine>(null);

  private decided: Promise<ScannerEngine> | null = null;
  private starting = false;
  /** Bumped by every stop, so a start still waiting on the camera knows it has been called off. */
  private attempt = 0;

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

    this.camera.stop();
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

    return canScanInPage() ? 'webview' : null;
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
   * The shared camera, told what this app's door needs to hear: that the
   * preview is live, and that iOS took the camera away when the app went to
   * the background — after which the door goes back to its button rather than
   * a black frame that never reads.
   */
  private async startWebView(onCode: (code: string) => void, attempt: number, video?: HTMLVideoElement): Promise<void> {
    if (!video) {
      this.refusal.set('unavailable');

      return;
    }

    const refusal = await this.camera.start(video, onCode, {
      onLive: () => this.running.set(true),
      onEnded: () => void this.stop(),
    });

    if (refusal && attempt === this.attempt) this.refusal.set(refusal);
  }
}
