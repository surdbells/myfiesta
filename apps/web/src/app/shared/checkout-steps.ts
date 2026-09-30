import { Component, computed, input } from '@angular/core';

/**
 * The three-step strip over the checkout flow: done steps are green ticks,
 * the current one is ringed, the rest wait in grey. Decorative — each page's
 * heading says where you are — so it is hidden from readers.
 *
 * The labels are written in the page's own text colour, whatever the step.
 * The strip sits straight on the poster wash, and a wash can be any colour:
 * the muted greys and the brand green it used to carry fell to 2:1 on a pale
 * poster in the dark theme and on a dark one in the light. Where each step
 * stands is drawn by its circle, which is on a surface of its own.
 */
@Component({
  selector: 'app-checkout-steps',
  standalone: true,
  template: `
    <p class="steps flex flex-wrap items-center gap-3 text-sm font-medium text-text" aria-hidden="true">
      @for (step of steps(); track step.n) {
        @if (step.n > 1) {
          <span class="h-px w-10 bg-border-strong max-sm:w-5"></span>
        }
        <span class="inline-flex items-center gap-2" [class.font-semibold]="step.n === current()">
          @if (step.n < current()) {
            <span class="grid h-7 w-7 place-items-center rounded-full bg-success text-xs font-bold text-text-inverse">✓</span>
          } @else if (step.n === current()) {
            <span class="grid h-7 w-7 place-items-center rounded-full border-2 border-primary bg-surface-raised text-xs font-bold text-primary-text">{{ step.n }}</span>
          } @else {
            <span class="grid h-7 w-7 place-items-center rounded-full border-2 border-border-strong bg-surface-raised text-xs font-bold text-text-muted">{{ step.n }}</span>
          }
          <span class="step-label max-sm:hidden">{{ step.label }}</span>
        </span>
      }
    </p>
  `,
})
export class CheckoutSteps {
  readonly current = input.required<1 | 2 | 3>();

  /**
   * Nothing to pay. The middle step is then only details: a free night's
   * checkout reserves its tickets and never reaches a payment.
   */
  readonly free = input(false);

  protected readonly steps = computed(() => [
    { n: 1, label: 'Selection' },
    { n: 2, label: this.free() ? 'Your details' : 'Details & payment' },
    { n: 3, label: 'Confirmation' },
  ]);
}
