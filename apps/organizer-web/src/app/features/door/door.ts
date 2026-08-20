import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { ScanResult } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * The door.
 *
 * The scan endpoint, partial admission, ticket_scans and the ledger all existed
 * and were tested; there was no screen to scan with, so none of it could be
 * used on a night.
 *
 * Two ways in, and the manual one is not a fallback for form's sake. Camera
 * scanning uses BarcodeDetector, which Chrome and Android have and Safari does
 * not — and a door with a flat battery, a cracked lens or a guest whose screen
 * will not brighten still has to work. Typing the code is the path that never
 * fails, so it is always visible rather than hidden behind "having trouble?".
 */
@Component({
  selector: 'app-door',
  imports: [FormsModule, RouterLink],
  templateUrl: './door.html',
  styleUrl: './door.css',
})
export class Door {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  readonly eventId = this.route.snapshot.paramMap.get('id')!;

  readonly code = signal('');
  readonly party = signal('');
  readonly busy = signal(false);
  readonly outcome = signal<ScanResult | null>(null);
  readonly error = signal<string | null>(null);

  /** Running count for this session, so a door can sanity-check itself. */
  readonly admittedHere = signal(0);
  readonly scannedHere = signal(0);

  readonly scanning = signal(false);
  readonly cameraError = signal<string | null>(null);

  private stream: MediaStream | null = null;
  private detector: unknown = null;
  private stopping = false;

  /** Whether this browser can read a QR without a library. */
  readonly cameraSupported = typeof window !== 'undefined' && 'BarcodeDetector' in window;

  /**
   * The colour and words the person on the door reacts to.
   *
   * Deliberately coarse: at a door, in the dark, the only question is let them
   * in or do not. The detail underneath is for the conversation that follows a
   * refusal.
   */
  readonly verdict = computed<'in' | 'partial' | 'no' | null>(() => {
    const outcome = this.outcome();

    if (!outcome) return null;
    if (!outcome.accepted) return 'no';

    return outcome.remaining > 0 ? 'partial' : 'in';
  });

  submit(): void {
    const code = this.code().trim().toUpperCase();

    if (!code || this.busy()) return;

    this.send(code);
  }

  private send(code: string): void {
    this.busy.set(true);
    this.error.set(null);

    const party = Number(this.party());

    this.api.scan(this.eventId, code, party > 0 ? party : undefined).subscribe({
      next: (outcome) => {
        this.busy.set(false);
        this.outcome.set(outcome);
        this.scannedHere.update((n) => n + 1);
        this.admittedHere.update((n) => n + outcome.admitted);

        // Cleared so the next guest can be scanned without a delete. The party
        // size is cleared too — it belongs to one ticket, and carrying it over
        // would silently admit four people on the next single ticket.
        this.code.set('');
        this.party.set('');
      },
      error: (response) => {
        this.busy.set(false);
        this.outcome.set(null);
        this.error.set(messageFor(response, 'That scan could not be sent.'));
      },
    });
  }

  // --- the camera ---------------------------------------------------------

  async startCamera(): Promise<void> {
    if (this.scanning() || !this.cameraSupported) return;

    this.cameraError.set(null);

    try {
      this.stream = await navigator.mediaDevices.getUserMedia({
        // The back camera. Without this a phone opens the selfie camera and
        // somebody has to hold the guest's ticket behind their own head.
        video: { facingMode: { ideal: 'environment' } },
      });
    } catch {
      this.cameraError.set(
        'No camera permission. Type the code instead — it works exactly the same.',
      );

      return;
    }

    const video = document.getElementById('door-camera') as HTMLVideoElement | null;

    if (!video) return;

    video.srcObject = this.stream;
    await video.play().catch(() => undefined);

    const Detector = (window as unknown as Record<string, new (o: object) => unknown>)[
      'BarcodeDetector'
    ];
    this.detector = new Detector({ formats: ['qr_code'] });

    this.scanning.set(true);
    this.stopping = false;
    void this.readLoop(video);
  }

  /**
   * Read frames until something is found or the camera is stopped.
   *
   * Polled on a timer rather than every animation frame: a door phone runs for
   * hours on one charge, and decoding sixty frames a second drains a battery
   * long before the queue is through.
   */
  private async readLoop(video: HTMLVideoElement): Promise<void> {
    const detector = this.detector as { detect(source: unknown): Promise<{ rawValue: string }[]> };

    while (!this.stopping) {
      try {
        const found = await detector.detect(video);

        if (found.length > 0 && !this.busy()) {
          const value = found[0].rawValue.trim().toUpperCase();

          this.code.set(value);
          this.send(value);

          // A pause after a hit, so one ticket held in front of the lens is not
          // scanned six times while the door reads the result.
          await new Promise((r) => setTimeout(r, 1800));
        }
      } catch {
        // A dropped frame is not worth stopping for.
      }

      await new Promise((r) => setTimeout(r, 250));
    }
  }

  stopCamera(): void {
    this.stopping = true;
    this.scanning.set(false);
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;
  }

  ngOnDestroy(): void {
    // Leaving the camera on after navigating away keeps the phone's indicator
    // light burning and the battery draining, which reads as spyware.
    this.stopCamera();
  }
}
