import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { EventReviewStep, OrganizerEventDetail } from '@myfiesta/api-types';

/**
 * An event waiting for myFiesta's review cannot be changed.
 *
 * The API refuses every write that would change what a buyer sees or pays for
 * it (423) until it is decided or taken back, so the screens that make those
 * writes check this first and say why, rather than letting somebody fill in a
 * form and be refused on save.
 */
export const LOCKED_FOR_REVIEW = 'This event is being reviewed. Withdraw it to make changes.';

export function lockedForReview(event: Pick<OrganizerEventDetail, 'status'> | null | undefined): boolean {
  return event?.status === 'in_review';
}

/** What an event's status is called: the same words as the console and the admin. */
export function eventStatusLabel(status: string): string {
  switch (status) {
    case 'draft':
      return 'Draft';
    case 'in_review':
      return 'In review';
    case 'published':
      return 'On sale';
    case 'cancelled':
      return 'Cancelled';
    default:
      return status;
  }
}

/** One step of the review history, in words an organizer reads. */
export function reviewStepLabel(step: EventReviewStep): string {
  switch (step.action) {
    case 'submitted':
      return 'Sent for review';
    case 'withdrawn':
      return 'Taken back from review';
    case 'rejected':
      return 'Sent back with changes to make';
    case 'approved':
      switch (step.via) {
        case 'takedown_lifted':
          return 'Put back on sale by myFiesta';
        case 'suspension_lifted':
          return 'Back on sale after the suspension was lifted';
        case 'series':
          return 'On sale as the next date of an approved series';
        case 'existing':
        case 'imported':
          return 'Approved as it was already on sale';
        default:
          return 'Approved and put on sale';
      }
  }
}

/**
 * The note at the top of a screen whose changes wait for the review.
 *
 * Says what is happening and where to undo it, once, above the list it
 * applies to. The screen itself stops offering the changes.
 */
@Component({
  selector: 'mf-review-lock',
  imports: [RouterLink],
  template: `
    <p class="lock" role="status">
      <strong>This event is being reviewed, so it cannot be changed.</strong>
      myFiesta looks at every event before it goes on sale. To change something,
      <a [routerLink]="['/manage/events', eventId()]">withdraw it from review</a> on the event’s page, then send it again.
    </p>
  `,
  styles: `
    :host {
      display: block;
    }

    .lock {
      margin: 0 0 var(--space-4);
      padding: var(--space-3) var(--space-4);
      border-left: 3px solid var(--primary);
      border-radius: var(--radius-md);
      background: color-mix(in srgb, var(--primary) 10%, var(--surface-raised));
      font-size: var(--font-size-sm);
      line-height: 1.5;
    }

    .lock strong {
      display: block;
      margin-bottom: var(--space-1);
    }

    .lock a {
      color: var(--primary-text);
    }
  `,
})
export class MfReviewLock {
  readonly eventId = input.required<string>();
}
