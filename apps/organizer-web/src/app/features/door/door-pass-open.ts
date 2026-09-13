import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { UiAlert, UiButton } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { DoorPassPreview } from '../../core/api.types';
import { DoorPassStore } from '../../core/door-pass';
import { messageFor } from '../../core/errors';
import { shortEventTime } from '../../core/event-time';

/**
 * Where a door link lands.
 *
 * One button, and it has to be pressed. Chat apps open links to draw a
 * preview, and a link that claimed itself on load would be spent by the
 * preview before the person on the door ever saw it.
 */
@Component({
  selector: 'app-door-pass-open',
  imports: [UiButton, UiAlert],
  templateUrl: './door-pass-open.html',
})
export class DoorPassOpen {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly passes = inject(DoorPassStore);

  private readonly secret = this.route.snapshot.paramMap.get('secret')!;

  readonly preview = signal<DoorPassPreview | null>(null);
  readonly notFound = signal(false);
  readonly opening = signal(false);
  readonly error = signal<string | null>(null);

  readonly when = computed(() => {
    const event = this.preview()?.event;

    return event ? shortEventTime(event.starts_at, event.timezone) : '';
  });

  readonly until = computed(() => {
    const pass = this.preview();

    return pass ? shortEventTime(pass.expires_at, pass.event.timezone) : '';
  });

  constructor() {
    // Already open on this phone — reloading the link, or tapping it again
    // from the chat — goes straight back to scanning.
    this.api.doorPassPreview(this.secret).subscribe({
      next: (preview) => {
        if (this.passes.for(preview.event.id) && preview.state === 'active') {
          void this.router.navigate(['/scan', preview.event.id], { replaceUrl: true });
          return;
        }

        this.preview.set(preview);
      },
      error: () => this.notFound.set(true),
    });
  }

  open(): void {
    if (this.opening()) return;

    this.opening.set(true);
    this.error.set(null);

    this.api.claimDoorPass(this.secret).subscribe({
      next: (pass) => {
        this.passes.start(pass);
        // replaceUrl: the secret is spent, and the back button should not
        // lead to a page that can only say so.
        void this.router.navigate(['/scan', pass.event.id], { replaceUrl: true });
      },
      error: (response) => {
        this.opening.set(false);
        this.error.set(messageFor(response, 'This door link could not be opened. Check the signal and try again.'));
      },
    });
  }
}
