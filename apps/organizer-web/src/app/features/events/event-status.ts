import type { EventReviewStep, EventStatus } from '../../core/api.types';

/**
 * What an event's status is called, and the colour it is allowed to be.
 *
 * One place for the words, because the list, the workspace header and the
 * series dates all show the same thing: a raw `in_review` in a badge reads
 * like a bug, and three spellings of "on sale" read like three states.
 * The same words as the admin and the phone.
 */
export function eventStatusLabel(status: EventStatus | string): string {
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

export function eventStatusTone(status: EventStatus | string): 'neutral' | 'brand' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'draft':
      return 'warning';
    case 'in_review':
      return 'brand';
    case 'cancelled':
      return 'danger';
    default:
      return 'success';
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
