import {
  Component,
  DestroyRef,
  ElementRef,
  afterNextRender,
  booleanAttribute,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { Router } from '@angular/router';
import { ChevronLeft } from 'lucide-angular';
import { Navigation } from '../core/navigation';
import { Chrome } from '../core/chrome';
import { MfIconButton } from './icon-button';

/**
 * The frame every screen sits in: an app bar, a body that scrolls under it,
 * and an optional footer for the action the screen exists for.
 *
 * The bar has states, and each one answers something the reader is doing:
 *
 * - **flat** at the top of a screen, the same colour as the page, so a screen
 *   that has not been scrolled has no chrome to look past;
 * - **scrolled** once content passes under it: translucent, blurred, with a
 *   hairline — the cue that there is more above;
 * - **large** titles on the places the bottom bar goes to. The title sits in
 *   the page, big, and folds into the bar as it scrolls away — the phone's
 *   own pattern for "this is a place" rather than "this is a step";
 * - **overlay** for a screen that opens on a photograph: the bar is clear and
 *   its buttons sit on dark chips until the picture has scrolled past;
 * - **busy**, a thin line moving along its bottom edge while something saves.
 *
 * Back is the navigation service's back, so the arrow, Android's button and
 * the iOS edge swipe all do the same thing — and it goes to the previous
 * screen rather than a fixed parent, with `backTo` only for a screen opened
 * from outside the app.
 *
 * A `task` screen — a form, a flow with a Save at the end — takes the bottom
 * bar away while it is open. A Save button with three tabs underneath it is an
 * invitation to leave halfway.
 */
@Component({
  selector: 'mf-screen',
  imports: [MfIconButton],
  template: `
    <header
      #bar
      class="bar"
      [class.scrolled]="scrolled()"
      [class.collapsed]="collapsed()"
      [class.large]="large()"
      [class.overlay]="overlay() && !pastHero()"
    >
      <div class="row">
        @if (back()) {
          <button
            mfIconButton
            class="back"
            [icon]="backIcon"
            label="Back"
            [tone]="overlay() && overlayDark() && !pastHero() ? 'over' : 'plain'"
            (click)="goBack()"
          ></button>
        }

        <div class="titles" [class.hidden]="titleHidden()">
          <h1 class="title">{{ title() }}</h1>
          @if (subtitle() && !large()) {
            <p class="sub">{{ subtitle() }}</p>
          }
        </div>

        <div class="actions">
          <ng-content select="[screenActions]" />
        </div>
      </div>

      <div class="under">
        <ng-content select="[screenBar]" />
      </div>

      @if (busy()) {
        <span class="progress" role="progressbar" aria-label="Working"></span>
      }
    </header>

    @if (refreshable()) {
      <div class="pull" [style.transform]="'translateY(' + pullY() + 'px)'" [style.opacity]="pullOpacity()" aria-hidden="true">
        <span class="spinner" [class.spinning]="refreshing()" [style.transform]="'rotate(' + pullY() * 3 + 'deg)'"></span>
      </div>
    }

    <main
      #body
      class="body"
      [class.flush]="flush()"
      [class.under-bar]="!overlay()"
      (scroll)="scrolledTo()"
      (touchstart)="pullStart($event)"
      (touchmove)="pullMove($event)"
      (touchend)="pullEnd()"
      (touchcancel)="pullEnd()"
    >
      @if (large()) {
        <div class="hero-title">
          <h1>{{ title() }}</h1>
          @if (subtitle()) {
            <p class="sub">{{ subtitle() }}</p>
          }
        </div>
      }

      <ng-content />
    </main>

    <footer class="footer" [style.transform]="lift()">
      <ng-content select="[screenFooter]" />
    </footer>
  `,
  host: {
    '[style.--mf-bar-h.px]': 'barHeight()',
    '[style.--mf-kb.px]': 'chrome.keyboardHeight()',
  },
  styles: `
    :host {
      position: relative;
      display: block;
      width: 100%;
      height: 100%;
      overflow: hidden;
      background: var(--surface-sunken);
    }

    /* ---------------------------------------------------------------- bar */

    .bar {
      position: absolute;
      z-index: 20;
      inset: 0 0 auto 0;
      padding-top: var(--mf-safe-top);
      background: var(--surface-sunken);
      border-bottom: 1px solid transparent;
      transition:
        background-color 180ms ease,
        border-color 180ms ease,
        box-shadow 180ms ease;
    }

    .bar.scrolled {
      background: color-mix(in srgb, var(--surface) 84%, transparent);
      backdrop-filter: blur(18px) saturate(1.6);
      border-bottom-color: var(--border-subtle);
      box-shadow: var(--shadow-card);
    }

    .bar.overlay {
      background: transparent;
      backdrop-filter: none;
      border-bottom-color: transparent;
      box-shadow: none;
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-1);
      min-width: 0;
      height: var(--mf-app-bar);
      padding: 0 var(--space-2) 0 var(--space-5);
    }

    .row:has(.back) {
      padding-left: var(--space-1);
    }

    .titles {
      flex: 1;
      min-width: 0;
      transition:
        opacity 160ms ease,
        transform 200ms var(--mf-ease-out);
    }

    /* The large title is in the page; the bar's copy waits until it has gone. */
    .titles.hidden {
      opacity: 0;
      transform: translateY(6px);
      pointer-events: none;
    }

    .title {
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-bold);
      letter-spacing: var(--font-tracking-tight);
      line-height: 1.2;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      font-size: var(--font-size-xs);
      color: var(--text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .actions {
      display: flex;
      align-items: center;
    }

    .under:empty {
      display: none;
    }

    .under {
      padding: 0 var(--space-5) var(--space-3);
    }

    .progress {
      position: absolute;
      left: 0;
      right: 0;
      bottom: -1px;
      height: 2px;
      overflow: hidden;
      background: color-mix(in srgb, var(--primary) 18%, transparent);
    }

    .progress::after {
      content: '';
      position: absolute;
      inset: 0 auto 0 0;
      width: 38%;
      background: var(--primary);
      animation: mf-progress 1.1s var(--mf-ease-out) infinite;
    }

    @keyframes mf-progress {
      from {
        transform: translateX(-100%);
      }
      to {
        transform: translateX(270%);
      }
    }

    /* --------------------------------------------------------------- body */

    .body {
      height: 100%;
      overflow-y: auto;
      overscroll-behavior-y: contain;
      -webkit-overflow-scrolling: touch;
      padding: var(--space-4) var(--space-5);
      /* Room for the bottom bar or the footer, the home indicator, the
         keyboard when it is up, and a thumb. */
      padding-bottom: calc(var(--mf-bar-space) + var(--mf-footer-h) + var(--mf-kb) + var(--space-8));
      scroll-padding-top: calc(var(--mf-bar-h) + var(--space-3));
    }

    /* Content starts below the bar and scrolls up under it. */
    .body.under-bar {
      padding-top: calc(var(--mf-bar-h) + var(--space-2));
    }

    .body.flush {
      padding-left: 0;
      padding-right: 0;
    }

    .body.flush.under-bar {
      padding-top: var(--mf-bar-h);
    }

    .hero-title {
      display: grid;
      gap: var(--space-1);
      margin: 0 0 var(--space-5);
    }

    .body.flush .hero-title {
      padding: 0 var(--space-5);
    }

    .hero-title h1 {
      font-size: var(--font-size-3xl);
      font-weight: var(--font-weight-bold);
      letter-spacing: var(--font-tracking-tighter);
      line-height: 1.05;
    }

    .hero-title .sub {
      font-size: var(--font-size-sm);
      white-space: normal;
    }

    /* ------------------------------------------------------ pull to refresh */

    .pull {
      position: absolute;
      z-index: 19;
      top: calc(var(--mf-bar-h) - 44px);
      left: 50%;
      width: 40px;
      height: 40px;
      margin-left: -20px;
      display: grid;
      place-items: center;
      border-radius: var(--radius-full);
      background: var(--surface-raised);
      box-shadow: var(--shadow-overlay);
      pointer-events: none;
      transition: opacity 160ms ease;
    }

    .spinner {
      width: 18px;
      height: 18px;
      border-radius: var(--radius-full);
      border: 2.5px solid var(--primary-soft);
      border-top-color: var(--primary);
    }

    .spinner.spinning {
      animation: mf-spin 0.7s linear infinite;
    }

    @keyframes mf-spin {
      to {
        transform: rotate(360deg);
      }
    }

    /* ------------------------------------------------------------- footer */

    .footer:empty {
      display: none;
    }

    .footer {
      position: absolute;
      z-index: 20;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-5) calc(var(--mf-safe-bottom) + var(--space-3));
      background: color-mix(in srgb, var(--surface) 88%, transparent);
      backdrop-filter: blur(18px) saturate(1.6);
      border-top: 1px solid var(--border-subtle);
      box-shadow: 0 -8px 24px -16px rgb(0 0 0 / 0.25);
      transition: transform 240ms var(--mf-ease-out);
    }

    .footer > ::ng-deep * {
      flex: 1;
    }
  `,
})
export class MfScreen {
  readonly title = input.required<string>();
  readonly subtitle = input<string | null>(null);
  readonly back = input(false, { transform: booleanAttribute });

  /** Where back goes when the app has nothing underneath this screen. */
  readonly backTo = input<string | null>(null);

  /** No side padding on the body: for full-bleed lists and the camera. */
  readonly flush = input(false, { transform: booleanAttribute });

  /** A place, not a step: the title sits big in the page and folds into the bar. */
  readonly large = input(false, { transform: booleanAttribute });

  /** The screen opens on a header of its own that runs up under a clear bar. */
  readonly overlay = input(false, { transform: booleanAttribute });

  /** What is under the clear bar is a photograph: its buttons sit on dark chips. */
  readonly overlayDark = input(true, { transform: booleanAttribute });

  /** Something is saving: a line runs along the bar. */
  readonly busy = input(false, { transform: booleanAttribute });

  /** A form or flow: the bottom bar goes away while this is open. */
  readonly task = input(false, { transform: booleanAttribute });

  /** Pulling down at the top asks for fresh data. */
  readonly refreshable = input(false, { transform: booleanAttribute });

  /** Emitted when back is pressed on a screen without `backTo`. */
  readonly backed = output<void>();

  /** Pulled far enough and let go. The screen reloads and sets `busy` meanwhile. */
  readonly refresh = output<void>();

  protected readonly backIcon = ChevronLeft;
  protected readonly chrome = inject(Chrome);

  private readonly nav = inject(Navigation);
  private readonly router = inject(Router);
  private readonly bar = viewChild.required<ElementRef<HTMLElement>>('bar');
  private readonly body = viewChild.required<ElementRef<HTMLElement>>('body');

  /** Measured once rendered. Unset until then, so the resting value in styles.css holds. */
  protected readonly barHeight = signal<number | null>(null);
  private readonly top = signal(0);

  protected readonly scrolled = computed(() => this.top() > 2);

  /** The large title has scrolled up behind the bar. */
  protected readonly collapsed = computed(() => this.large() && this.top() > 44);

  /** The photograph at the top has scrolled away. */
  protected readonly pastHero = computed(() => this.top() > 220);

  protected readonly titleHidden = computed(() => {
    if (this.large()) return !this.collapsed();
    if (this.overlay()) return !this.pastHero();

    return false;
  });

  /** Lifts the footer above the keyboard, which the WebView does not resize for. */
  protected readonly lift = computed(() => {
    const height = this.chrome.keyboardHeight();

    return height > 0 ? `translateY(calc(-${height}px + var(--mf-safe-bottom)))` : null;
  });

  private key: string | null = null;

  // --- pull to refresh ------------------------------------------------------

  private pullFrom: number | null = null;
  protected readonly pullY = signal(0);
  protected readonly refreshing = signal(false);
  protected readonly pullOpacity = computed(() => (this.refreshing() ? 1 : Math.min(1, this.pullY() / 56)));

  protected pullStart(event: TouchEvent): void {
    if (!this.refreshable() || this.refreshing() || this.body().nativeElement.scrollTop > 0) return;

    this.pullFrom = event.touches[0].clientY;
  }

  protected pullMove(event: TouchEvent): void {
    if (this.pullFrom === null) return;

    const dy = event.touches[0].clientY - this.pullFrom;

    // Resistance, like the phone's own: the further the pull, the less it gives.
    this.pullY.set(dy > 0 ? Math.min(96, dy * 0.45) : 0);
  }

  protected pullEnd(): void {
    if (this.pullFrom === null) return;

    this.pullFrom = null;

    if (this.pullY() > 60) {
      this.refreshing.set(true);
      this.pullY.set(56);
      this.refresh.emit();

      // Held for at least a beat, so a fast reload still reads as "it did it".
      const started = Date.now();
      const settle = () => {
        if (this.busy() && Date.now() - started < 15_000) return void setTimeout(settle, 120);

        setTimeout(() => {
          this.refreshing.set(false);
          this.pullY.set(0);
        }, Math.max(0, 500 - (Date.now() - started)));
      };

      setTimeout(settle, 120);
    } else {
      this.pullY.set(0);
    }
  }

  constructor() {
    const destroyRef = inject(DestroyRef);

    effect(() => {
      if (this.back()) this.nav.claimBack(this, () => this.goBack());
      else this.nav.releaseBack(this);
    });

    effect(() => this.chrome.task.set(this.task()));

    // The tab you are already on, tapped again: back to the top. Only the
    // places the bar goes to answer it — a pushed screen underneath does not.
    let seen = this.chrome.scrollTopRequested();

    effect(() => {
      const asked = this.chrome.scrollTopRequested();

      if (asked !== seen && this.nav.isTabRoot()) this.scrollToTop();
      seen = asked;
    });

    // The focused field, kept in view when the keyboard comes up over it.
    effect(() => {
      if (!this.chrome.keyboard()) return;

      const active = document.activeElement;

      if (active instanceof HTMLElement && this.body().nativeElement.contains(active)) {
        requestAnimationFrame(() => active.scrollIntoView({ block: 'center', behavior: 'smooth' }));
      }
    });

    afterNextRender(() => {
      this.key = this.router.url;

      // The bar's height decides where content starts, and it changes: a
      // sub-bar appears, the type is set larger by the phone's settings.
      const observer = new ResizeObserver(() => this.barHeight.set(this.bar().nativeElement.offsetHeight));
      observer.observe(this.bar().nativeElement);
      this.barHeight.set(this.bar().nativeElement.offsetHeight);

      const footer = this.body().nativeElement.parentElement?.querySelector<HTMLElement>('.footer');

      if (footer) {
        const measure = () => this.body().nativeElement.style.setProperty('--mf-footer-h', `${footer.offsetHeight}px`);
        new ResizeObserver(measure).observe(footer);
        measure();
      }

      this.restoreScroll();

      destroyRef.onDestroy(() => observer.disconnect());
    });

    destroyRef.onDestroy(() => {
      this.nav.releaseBack(this);
      if (this.task()) this.chrome.task.set(false);
      if (this.key) this.nav.rememberScroll(this.key, this.body().nativeElement.scrollTop);
    });
  }

  goBack(): void {
    const fallback = this.backTo();

    if (fallback !== null) this.nav.back(fallback);
    else this.backed.emit();
  }

  /** Scroll the body back to the top — a tapped tab that is already open does this. */
  scrollToTop(): void {
    this.body().nativeElement.scrollTo({ top: 0, behavior: 'smooth' });
  }

  protected scrolledTo(): void {
    const top = this.body().nativeElement.scrollTop;

    this.top.set(top);
    this.chrome.reportScroll(top);
  }

  /**
   * Back where the list was, when arriving by going back.
   *
   * The content arrives a moment after the screen does — it is fetched — so
   * this keeps trying for a few hundred milliseconds while the list grows tall
   * enough to scroll to where it was.
   */
  private restoreScroll(): void {
    const saved = this.key ? this.nav.savedScroll(this.key) : null;

    if (!saved) return;

    const body = this.body().nativeElement;
    let tries = 0;

    const attempt = () => {
      body.scrollTop = saved;

      if (Math.abs(body.scrollTop - saved) > 2 && tries++ < 45) requestAnimationFrame(attempt);
      else this.scrolledTo();
    };

    attempt();
  }
}
