import { Component, input } from '@angular/core';

/**
 * The three-step strip over the checkout flow: done steps are green ticks,
 * the current one is ringed, the rest wait in grey. Decorative — each page's
 * heading says where you are — so it is hidden from readers.
 */
@Component({
  selector: 'app-checkout-steps',
  standalone: true,
  template: `
    <p class="steps flex flex-wrap items-center gap-3 text-sm font-medium" aria-hidden="true">
      @for (step of steps; track step.n) {
        @if (step.n > 1) {
          <span class="h-px w-10 bg-border-strong max-sm:w-5"></span>
        }
        <span
          class="inline-flex items-center gap-2"
          [class]="
            step.n === current() ? 'text-primary-text' : step.n < current() ? 'text-text' : 'text-text-subtle'
          "
        >
          @if (step.n < current()) {
            <span class="grid h-7 w-7 place-items-center rounded-full bg-success text-xs font-bold text-text-inverse">✓</span>
          } @else if (step.n === current()) {
            <span class="grid h-7 w-7 place-items-center rounded-full border-2 border-primary bg-surface-raised text-xs font-bold text-primary-text">{{ step.n }}</span>
          } @else {
            <span class="grid h-7 w-7 place-items-center rounded-full border-2 border-border-strong bg-surface-raised text-xs font-bold text-text-subtle">{{ step.n }}</span>
          }
          <span class="max-sm:hidden">{{ step.label }}</span>
        </span>
      }
    </p>
  `,
})
export class CheckoutSteps {
  readonly current = input.required<1 | 2 | 3>();

  protected readonly steps = [
    { n: 1, label: 'Selection' },
    { n: 2, label: 'Details & payment' },
    { n: 3, label: 'Confirmation' },
  ] as const;
}
