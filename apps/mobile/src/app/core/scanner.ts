import { DOCUMENT } from '@angular/common';
import { Injectable, inject, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { BarcodeFormat, BarcodeScanner, LensFacing } from '@capacitor-mlkit/barcode-scanning';

/** Why the camera is not running, in words the door can act on. */
export type ScannerRefusal =
  | 'unsupported'
  | 'permission'
  | 'unavailable'
  | null;

/**
 * Reading a ticket with the camera.
 *
 * Two implementations behind one interface, because the platforms genuinely
 * differ:
 *
 * - On a phone, ML Kit reads the code natively. It is faster in the dark and
 *   at an angle than anything a WebView can do, which is the whole job: a
 *   queue moves at the speed of the worst scan, not the average one.
 * - In a browser, BarcodeDetector does it where the engine has one (Chrome on
 *   Android and desktop). Safari has none, so the browser path is for
 *   development rather than a promise.
 *
 * Either way typing the code stays on screen. A cracked lens, a flat battery
 * and a guest whose screen will not brighten all end there, and that is not a
 * moment to be hunting for a fallback.
 *
 * The native scanner draws the camera *behind* the WebView, so starting it
 * makes the page transparent and stopping it puts the background back. Leaving
 * that class on is a door screen with no background at all, which is why
 * stopping is in a finally rather than a happy path.
 */
@Injectable({ providedIn: 'root' })
export class Scanner {
  private readonly document = inject(DOCUMENT);

  readonly running = signal(false);
  readonly refusal = signal<ScannerRefusal>(null);

  private stopWeb: (() => void) | null = null;

  /** Whether this device can read a code at all, before anything is asked of it. */
  async supported(): Promise<boolean> {
    if (Capacitor.isNativePlatform()) {
      try {
        const { supported } = await BarcodeScanner.isSupported();

        return supported;
      } catch {
        return false;
      }
    }

    return typeof (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector === 'function';
  }

  /**
   * Start reading. `onCode` fires for every code seen, deduplicated by the
   * caller — a camera pointed at a ticket reads it many times a second.
   *
   * @param video where to draw the preview in the browser; ignored natively,
   *              where the camera is behind the page.
   */
  async start(onCode: (code: string) => void, video?: HTMLVideoElement): Promise<void> {
    if (this.running()) return;

    this.refusal.set(null);

    if (!(await this.supported())) {
      this.refusal.set('unsupported');

      return;
    }

    if (Capacitor.isNativePlatform()) await this.startNative(onCode);
    else await this.startWeb(onCode, video);
  }

  async stop(): Promise<void> {
    if (Capacitor.isNativePlatform()) {
      try {
        await BarcodeScanner.removeAllListeners();
        await BarcodeScanner.stopScan();
      } catch {
        // Already stopped, or the plugin is gone with the page.
      }

      this.document.documentElement.classList.remove('scanning');
    }

    this.stopWeb?.();
    this.stopWeb = null;
    this.running.set(false);
  }

  private async startNative(onCode: (code: string) => void): Promise<void> {
    const granted = await this.permission();

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

      this.running.set(true);
    } catch {
      this.document.documentElement.classList.remove('scanning');
      this.refusal.set('unavailable');
    }
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

  /**
   * The browser path: getUserMedia into a video element, and the engine's own
   * detector reading frames off it.
   */
  private async startWeb(onCode: (code: string) => void, video?: HTMLVideoElement): Promise<void> {
    if (!video) {
      this.refusal.set('unavailable');

      return;
    }

    let stream: MediaStream;

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false,
      });
    } catch {
      this.refusal.set('permission');

      return;
    }

    const Detector = (globalThis as unknown as { BarcodeDetector: new (o: { formats: string[] }) => { detect: (source: CanvasImageSource) => Promise<{ rawValue: string }[]> } }).BarcodeDetector;
    const detector = new Detector({ formats: ['qr_code'] });

    video.srcObject = stream;
    // muted and playsinline, or iOS refuses to play it inline and Chrome
    // blocks autoplay outright.
    video.muted = true;
    video.setAttribute('playsinline', '');
    await video.play().catch(() => undefined);

    let live = true;

    const read = async () => {
      if (!live) return;

      try {
        const found = await detector.detect(video);

        for (const code of found) {
          if (code.rawValue) onCode(code.rawValue);
        }
      } catch {
        // A frame that cannot be read is not a failure worth stopping for.
      }

      if (live) requestAnimationFrame(() => void read());
    };

    void read();
    this.running.set(true);

    this.stopWeb = () => {
      live = false;
      stream.getTracks().forEach((track) => track.stop());
      video.srcObject = null;
    };
  }
}
