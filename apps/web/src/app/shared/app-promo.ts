import { Component, computed, inject, input } from '@angular/core';
import { UiIcon } from '@myfiesta/ui';
import { BellRing, Heart, QrCode, Smartphone } from 'lucide-angular';
import { STORE_LINKS } from '../core/store-links';
import { EventSummary } from '../core/api.types';

/**
 * The phone app, for iOS and Android.
 *
 * Why somebody would want it, said as what it does: tickets that open without
 * signal at the door, saved nights, and organizers followed. The listings are
 * told to the site at run time (STORE_LINKS); a store with no listing gets no
 * button, and with neither the section is not drawn at all — a store badge
 * that leads nowhere is worse than none.
 *
 * The buttons are the site's own, not Apple's or Google's badge artwork. Both
 * require their official badges once an app is listed, and those are theirs
 * to supply: docs/LAUNCH.md says where the operator drops them in.
 */
@Component({
  selector: 'app-app-promo',
  imports: [UiIcon],
  template: `
    @if (shown()) {
      <section class="promo frame mt-24" aria-labelledby="promo-title">
        <div
          class="relative isolate grid grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] items-center gap-10 overflow-hidden rounded-(--radius-card) border border-border-subtle bg-surface-raised p-12 shadow-(--shadow-raised) max-[900px]:grid-cols-1 max-[900px]:p-8 max-sm:p-6"
        >
          <span
            class="pointer-events-none absolute -left-24 -top-24 -z-10 h-80 w-80 rounded-full bg-[radial-gradient(circle,color-mix(in_srgb,var(--primary)_16%,transparent),transparent_70%)]"
            aria-hidden="true"
          ></span>

          <div class="grid content-start gap-5">
            <p class="text-sm font-semibold uppercase tracking-[0.08em] text-primary-text">The myFiesta app</p>
            <h2 id="promo-title" class="text-balance text-[clamp(1.75rem,3.4vw,2.5rem)] font-bold leading-[1.1] tracking-[-0.02em]">
              Your tickets, in your pocket — even with no signal at the door.
            </h2>

            <ul class="m-0 grid list-none gap-3 p-0 text-text-muted">
              @for (point of points; track point.text) {
                <li class="flex items-start gap-3">
                  <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary-soft text-primary-soft-text">
                    <ui-icon [icon]="point.icon" size="sm" />
                  </span>
                  <span class="pt-1">{{ point.text }}</span>
                </li>
              }
            </ul>

            <!-- The site's own buttons in the store's words; the official
                 badges replace them once supplied (docs/LAUNCH.md). -->
            <div class="mt-2 flex flex-wrap gap-3">
              @if (links.ios) {
                <a
                  class="store store--ios inline-flex h-14 min-w-[11.5rem] items-center gap-3 rounded-xl bg-sold px-5 text-on-sold no-underline transition-transform duration-(--motion-fast) ease-(--motion-ease) hover:-translate-y-0.5 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                  [href]="links.ios"
                  rel="noopener"
                >
                  <ui-icon [icon]="phoneIcon" size="md" />
                  <span class="grid leading-tight">
                    <span class="text-xs">Download on the</span>
                    <span class="text-lg font-semibold">App Store</span>
                  </span>
                </a>
              }
              @if (links.android) {
                <a
                  class="store store--android inline-flex h-14 min-w-[11.5rem] items-center gap-3 rounded-xl bg-sold px-5 text-on-sold no-underline transition-transform duration-(--motion-fast) ease-(--motion-ease) hover:-translate-y-0.5 motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                  [href]="links.android"
                  rel="noopener"
                >
                  <ui-icon [icon]="phoneIcon" size="md" />
                  <span class="grid leading-tight">
                    <span class="text-xs">Get it on</span>
                    <span class="text-lg font-semibold">Google Play</span>
                  </span>
                </a>
              }
            </div>
          </div>

          <!-- A phone, drawn: one of the nights on sale as the app shows a
               ticket. Decorative; the words beside it say everything. -->
          <div class="relative mx-auto w-full max-w-[17rem] max-[900px]:hidden" aria-hidden="true">
            <div class="rounded-[2.25rem] border-[10px] border-sold bg-surface p-3 shadow-(--shadow-floating)">
              <div class="mx-auto mb-3 h-1.5 w-16 rounded-full bg-border-strong"></div>
              <div class="overflow-hidden rounded-(--radius-card) border border-border-subtle bg-surface-raised shadow-(--shadow-card)">
                @if (sample()?.poster_url) {
                  <img class="aspect-video w-full object-cover" [src]="sample()!.poster_url" alt="" loading="lazy" decoding="async" width="320" height="180" />
                } @else {
                  <div class="aspect-video w-full bg-[linear-gradient(150deg,var(--color-brand-600),var(--color-brand-900))]"></div>
                }
                <div class="grid gap-1 p-3">
                  <span class="text-[10px] font-semibold uppercase tracking-[0.08em] text-primary-text">Your ticket</span>
                  <span class="line-clamp-2 text-sm font-semibold leading-snug">{{ sample()?.title ?? 'Your next night out' }}</span>
                  <span class="text-xs text-text-muted">{{ sample()?.city ?? 'Ready at the door' }}</span>
                </div>
                <div class="grid place-items-center border-t border-dashed border-border-strong p-4">
                  <span class="grid h-24 w-24 place-items-center rounded-lg bg-surface-inset text-text-subtle">
                    <ui-icon [icon]="codeIcon" size="lg" />
                  </span>
                  <span class="mt-2 text-[11px] text-text-subtle">Opens without signal</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    }
  `,
})
export class AppPromo {
  readonly links = inject(STORE_LINKS);

  /** A night on sale, to put on the drawn phone's screen. */
  readonly sample = input<EventSummary | null | undefined>(null);

  protected readonly phoneIcon = Smartphone;
  protected readonly codeIcon = QrCode;

  readonly points = [
    { icon: QrCode, text: 'Tickets open without signal — the door scans them all the same.' },
    { icon: Heart, text: 'Save nights for later and find them again on any device you sign in on.' },
    { icon: BellRing, text: 'Follow the organizers you like and hear about their next nights.' },
  ];

  readonly shown = computed(() => !!(this.links.ios || this.links.android));
}
