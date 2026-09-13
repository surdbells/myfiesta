import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterOutlet } from '@angular/router';
import { UiButton, UiConfirm } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { DoorOffline } from '../../core/door-offline';
import { DoorPassStore } from '../../core/door-pass';
import { shortEventTime } from '../../core/event-time';

/**
 * The door, on a phone that holds a door pass and nothing else.
 *
 * No sidebar and no way into the rest of the console: there is nothing else
 * this phone may open, and a screen full of links that all refuse is worse
 * than a screen with one job. The scanner itself is the same one organizers
 * use, so the door behaves the same whoever is holding it.
 */
@Component({
  selector: 'app-door-pass-scanner',
  imports: [RouterOutlet, UiButton, UiConfirm],
  templateUrl: './door-pass-scanner.html',
})
export class DoorPassScanner {
  private readonly api = inject(Api);
  private readonly offline = inject(DoorOffline);
  private readonly route = inject(ActivatedRoute);
  readonly passes = inject(DoorPassStore);

  private readonly eventId = this.route.snapshot.paramMap.get('id')!;

  /** Re-read on every change, so a pass that stops mid-shift takes the scanner down with it. */
  readonly pass = computed(() => (this.passes.pass() ? this.passes.for(this.eventId) : null));

  readonly until = computed(() => {
    const pass = this.pass();

    return pass ? shortEventTime(pass.expires_at, pass.event.timezone) : '';
  });

  readonly when = computed(() => {
    const pass = this.pass();

    return pass ? shortEventTime(pass.event.starts_at, pass.event.timezone) : '';
  });

  readonly confirmingEnd = signal(false);
  readonly ending = signal(false);
  readonly unsent = signal(0);

  async askToEnd(): Promise<void> {
    // Scans made without signal live only on this phone until they are sent.
    // Throwing the pass away first would throw them away with it.
    this.unsent.set(this.offline.supported ? (await this.offline.pending(this.eventId)).length : 0);
    this.confirmingEnd.set(true);
  }

  endShift(): void {
    const pass = this.pass();
    if (!pass || this.ending()) return;

    this.ending.set(true);

    const finish = () => {
      this.ending.set(false);
      this.confirmingEnd.set(false);
      this.passes.end('Shift ended. This phone no longer scans for this event.');
    };

    // Ended here whether or not the server hears about it: the phone stops
    // scanning either way, and the pass runs out on its own tonight.
    this.api.endDoorPass(pass.token).subscribe({ next: finish, error: finish });
  }
}
