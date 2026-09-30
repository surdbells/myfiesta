import { Component, DestroyRef, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { Banknote, Heart, LucideAngularModule, MapPin, Music, QrCode, ScanLine, Ticket, WifiOff } from 'lucide-angular';
import { Intro } from '../../core/intro';
import { Navigation } from '../../core/navigation';
import { SessionStore } from '../../core/session';
import { MfButton, MfCard, MfIcon } from '../../ui';

type Art = 'welcome' | 'find' | 'tickets' | 'run';

interface Page {
  art: Art;
  kicker: string;
  title: string;
  body: string;
}

/**
 * What the app does, in the words somebody would use to describe it to a
 * friend. Two kinds of people open it — going out, and putting the night on —
 * so each gets its own pages, and neither has to read the other's to start.
 */
export const INTRO_PAGES: readonly Page[] = [
  {
    art: 'welcome',
    kicker: 'Welcome',
    title: 'Nights out, sorted',
    body: 'Find something to do, get in without the fuss, or run the whole night from your phone. Here is how.',
  },
  {
    art: 'find',
    kicker: 'Going out',
    title: 'Find what’s on near you',
    body: 'See what’s on in your city, save the nights you like and follow the organizers you trust. Buying takes a few taps, and you don’t need an account.',
  },
  {
    art: 'tickets',
    kicker: 'Going out',
    title: 'Your tickets, ready at the door',
    body: 'Sign in and your tickets live on your phone. They open with no signal, so a basement venue is no problem: show the code at the door and you’re in.',
  },
  {
    art: 'run',
    kicker: 'Running events',
    title: 'Put the night on',
    body: 'Sell tickets, scan them at the door even when the venue has no signal, see what you’ve sold and get paid. Set your events up on the myFiesta website, then run them from here.',
  },
];

/**
 * The introduction: shown once, on a first launch, and again from Settings or
 * from signing in.
 *
 * Pages that are swiped, the way the rest of the app's rows are — scroll
 * snapping, so the browser does the momentum and the snap — and that never
 * need a finger: Next and the dots move between them, and so do the arrow
 * keys, for a Bluetooth keyboard or a switch. Each move is said aloud, since
 * a page sliding in is otherwise silent to a screen reader, and only the page
 * on screen is read.
 *
 * Nothing on it comes from the network. The pictures are drawn from the
 * tokens and the icons the app already carries, around the brand mark it
 * ships with, so the first thing a new phone shows works in a field with no
 * signal and looks right in light and in dark.
 *
 * Every way out is remembered, so it does not come back: Skip, Get started
 * (What's on), and I run events (signing in to the organizer screens).
 */
@Component({
  selector: 'mf-intro',
  imports: [MfButton, MfCard, MfIcon, LucideAngularModule],
  host: {
    '(keydown)': 'onKey($event)',
    // The pages are the swipe here. Without this an edge swipe on iPhone
    // would scroll back a page and ask the shell for a page back as well.
    '(touchstart)': '$event.stopPropagation()',
  },
  template: `
    <div class="intro">
      <header class="top">
        <h1 class="brand">
          <img src="brand/mark-64.png" alt="" width="32" height="32" />
          myFiesta
        </h1>
        <!-- Not on the last page, where Get started is the same thing said
             better. Hidden rather than removed, so the header does not move. -->
        <button mfButton variant="ghost" size="sm" class="skip" [class.gone]="last()" (click)="skip()">Skip</button>
      </header>

      <section class="carousel" aria-roledescription="carousel" aria-label="What myFiesta does">
        <div #track class="track scroll-x" tabindex="0" (scroll)="onScroll()">
          @for (page of pages; track page.art; let i = $index) {
            <article
              class="page"
              role="group"
              aria-roledescription="slide"
              [attr.aria-label]="i + 1 + ' of ' + pages.length"
              [attr.aria-hidden]="i === current() ? null : 'true'"
            >
              <div class="art" aria-hidden="true">
                <span class="disc"></span>
                @switch (page.art) {
                  @case ('welcome') {
                    <span class="main mark"><img src="brand/mark-192.png" alt="" width="88" height="88" /></span>
                    <span class="chip gold nw"><mf-icon [icon]="icons.music" size="lg" /></span>
                    <span class="chip ne"><mf-icon [icon]="icons.scan" size="lg" /></span>
                    <span class="chip green se"><mf-icon [icon]="icons.ticket" size="lg" /></span>
                  }
                  @case ('find') {
                    <span class="main events">
                      <mf-card class="event behind">
                        <span class="poster other"></span>
                        <span class="lines"><span class="line"></span><span class="line short"></span></span>
                      </mf-card>
                      <mf-card class="event">
                        <span class="poster"></span>
                        <span class="lines">
                          <span class="line"></span>
                          <span class="line short"></span>
                          <span class="buy"></span>
                        </span>
                      </mf-card>
                    </span>
                    <span class="chip gold ne"><mf-icon [icon]="icons.pin" size="lg" /></span>
                    <span class="chip sw"><mf-icon [icon]="icons.heart" size="lg" /></span>
                  }
                  @case ('tickets') {
                    <mf-card class="main ticket" flush>
                      <span class="stub"><span class="line"></span><span class="line short"></span></span>
                      <span class="code"><lucide-icon [img]="icons.code" [size]="72" [strokeWidth]="1.5" /></span>
                    </mf-card>
                    <span class="chip gold ne"><mf-icon [icon]="icons.offline" size="lg" /></span>
                    <span class="chip green sw"><mf-icon [icon]="icons.ticket" size="lg" /></span>
                  }
                  @case ('run') {
                    <mf-card class="main chart">
                      <span class="line short"></span>
                      <span class="bars"><span></span><span></span><span></span><span></span><span></span></span>
                    </mf-card>
                    <span class="chip green nw"><mf-icon [icon]="icons.scan" size="lg" /></span>
                    <span class="chip gold se"><mf-icon [icon]="icons.money" size="lg" /></span>
                  }
                }
              </div>

              <p class="kicker">{{ page.kicker }}</p>
              <h2>{{ page.title }}</h2>
              <p class="body">{{ page.body }}</p>
            </article>
          }
        </div>
      </section>

      <footer class="bottom">
        <div class="dots" role="group" aria-label="Pages">
          @for (page of pages; track page.art; let i = $index) {
            <button
              type="button"
              class="dot"
              [class.on]="i === current()"
              [attr.aria-current]="i === current() ? 'step' : null"
              [attr.aria-label]="'Page ' + (i + 1) + ' of ' + pages.length + ': ' + page.title"
              (click)="go(i)"
            ></button>
          }
        </div>

        <!-- One button whose words change, rather than two that swap: focus
             stays on it from Next to Get started. -->
        <button mfButton size="lg" block (click)="forward()">{{ last() ? 'Get started' : 'Next' }}</button>

        <!-- On every page, so somebody who came to run a night never has to
             read about buying tickets to find their way in. -->
        <button mfButton [variant]="last() ? 'secondary' : 'ghost'" block (click)="runEvents()">I run events</button>
      </footer>

      <p class="announce" aria-live="polite" aria-atomic="true">{{ announcement() }}</p>
    </div>
  `,
  styles: `
    :host {
      display: block;
      height: 100%;
      background: var(--surface-sunken);
    }

    /* One column no wider than the phone: left to size itself, it grew to
       fit every page side by side, and the pages ran off the screen. */
    .intro {
      display: grid;
      grid-template-rows: auto minmax(0, 1fr) auto;
      grid-template-columns: minmax(0, 1fr);
      height: 100%;
      padding: var(--mf-safe-top) 0 calc(var(--mf-safe-bottom) + var(--space-4));
    }

    .top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: var(--mf-app-bar);
      padding: 0 var(--space-3) 0 var(--space-5);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-size-lg);
    }

    .brand img {
      border-radius: var(--radius-sm);
    }

    .skip.gone {
      visibility: hidden;
    }

    .carousel {
      min-height: 0;
    }

    /* Sideways with no scrollbar, and its focus ring inside (scroll-x, in
       styles.css). */
    .track {
      display: flex;
      height: 100%;
      overflow-y: hidden;
      scroll-snap-type: x mandatory;
      overscroll-behavior-x: contain;
    }

    /*
     * Centred by auto margins rather than justify-content, which on a short
     * phone centres the overflow too and pushes the top of the page out of
     * reach above the scroll. The margins give way instead, and the page
     * scrolls from its picture.
     */
    .page {
      flex: 0 0 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: var(--space-4) var(--space-6);
      overflow-y: auto;
      text-align: center;
      scroll-snap-align: start;
      /* One swipe, one page, however hard the flick. */
      scroll-snap-stop: always;
    }

    .page > :first-child {
      margin-top: auto;
    }

    .page > :last-child {
      margin-bottom: auto;
    }

    .kicker {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--primary-text);
    }

    h2 {
      margin-top: var(--space-2);
      font-size: var(--font-size-2xl);
      font-weight: var(--font-weight-bold);
      letter-spacing: var(--font-tracking-tight);
      line-height: var(--font-leading-tight);
    }

    .body {
      max-width: 34ch;
      margin-top: var(--space-3);
      color: var(--text-muted);
      line-height: var(--font-leading-normal);
    }

    /* ------------------------------------------------------------ the pictures */

    /*
     * The picture gives way to the words. It asks for a third of the screen,
     * and on a phone too short for that and the words both — an SE, a small
     * Android, a notch taking its share — it shrinks, to nothing if it has
     * to, rather than leave the end of a sentence below the buttons, where
     * nothing says there is more to read. A breakpoint on the screen's
     * height guessed at how much the words need, and guessed short on the
     * pages that need the most.
     *
     * What is drawn in it shrinks with it, a step at a time (the container
     * queries below): a smaller picture of the same thing, never a cropped
     * one. Each step comes where the tallest picture, the ticket, would stop
     * fitting at the size above it.
     */
    .art {
      position: relative;
      display: grid;
      place-items: center;
      flex: 0 1 clamp(8rem, 30dvh, 16rem);
      min-height: 0;
      width: 100%;
      margin-bottom: var(--space-6);
      container: intro-art / size;
    }

    @container intro-art (max-height: 11rem) {
      .main,
      .chip {
        zoom: 0.75;
      }
    }

    @container intro-art (max-height: 8.25rem) {
      .main,
      .chip {
        zoom: 0.55;
      }
    }

    /* Too little room to be a picture of anything: the words have it all. */
    @container intro-art (max-height: 5.75rem) {
      .art > * {
        display: none;
      }
    }

    .disc {
      position: absolute;
      height: 94%;
      aspect-ratio: 1;
      border-radius: var(--radius-full);
      /* A tint of the brand green rather than --primary-soft, which in light
         is a shade off the page and all but disappears behind a picture. */
      background: color-mix(in srgb, var(--primary) 9%, transparent);
    }

    .main {
      position: relative;
    }

    .chip {
      position: absolute;
      display: grid;
      place-items: center;
      width: 3rem;
      height: 3rem;
      border-radius: var(--radius-full);
      background: var(--surface-raised);
      color: var(--primary-text);
      box-shadow: var(--shadow-raised);
    }

    .chip.gold {
      background: var(--accent);
      color: var(--on-accent);
    }

    .chip.green {
      background: var(--primary);
      color: var(--on-primary);
    }

    .nw {
      top: 6%;
      left: calc(50% - 8.5rem);
    }

    .ne {
      top: 8%;
      right: calc(50% - 8.5rem);
    }

    .sw {
      bottom: 6%;
      left: calc(50% - 8rem);
    }

    .se {
      bottom: 4%;
      right: calc(50% - 8rem);
    }

    /* The app's icon, as it sits on the home screen: the mark on white. */
    .mark {
      display: grid;
      place-items: center;
      width: 7.5rem;
      height: 7.5rem;
      border-radius: var(--radius-xl);
      background: var(--color-neutral-0);
      box-shadow: var(--shadow-floating);
    }

    .line {
      display: block;
      height: 0.5rem;
      border-radius: var(--radius-full);
      background: var(--surface-inset);
    }

    .line.short {
      width: 60%;
    }

    .events {
      display: grid;
    }

    .events > * {
      grid-area: 1 / 1;
    }

    /* Positioned, so the card in front is painted in front: the tilted one
       behind it is lifted into a layer of its own by its transform. */
    .event {
      position: relative;
      display: flex;
      align-items: center;
      gap: var(--space-3);
      width: 13rem;
      padding: var(--space-3);
    }

    .event.behind {
      transform: translate(-1.25rem, -1.75rem) rotate(-6deg);
    }

    .poster {
      flex-shrink: 0;
      width: 3.5rem;
      height: 3.5rem;
      border-radius: var(--radius-md);
      background: linear-gradient(135deg, var(--primary), var(--accent));
    }

    .poster.other {
      background: linear-gradient(135deg, var(--accent), var(--primary));
    }

    .lines {
      display: grid;
      flex: 1;
      gap: var(--space-2);
    }

    .buy {
      width: 3.5rem;
      height: 1.25rem;
      border-radius: var(--radius-full);
      background: var(--primary);
    }

    .ticket {
      display: grid;
      width: 9.5rem;
    }

    .stub {
      display: grid;
      gap: var(--space-2);
      padding: var(--space-4);
      background: var(--primary);
    }

    .stub .line {
      background: color-mix(in srgb, var(--on-primary) 70%, transparent);
    }

    .code {
      display: grid;
      place-items: center;
      padding: var(--space-4);
      border-top: 2px dashed var(--border-strong);
      color: var(--text);
    }

    .chart {
      width: 11rem;
    }

    .bars {
      display: flex;
      align-items: flex-end;
      gap: var(--space-2);
      height: 5.5rem;
      margin-top: var(--space-4);
    }

    .bars span {
      flex: 1;
      border-radius: var(--radius-sm) var(--radius-sm) 0 0;
      background: var(--primary);
    }

    .bars span:nth-child(1) {
      height: 38%;
    }

    .bars span:nth-child(2) {
      height: 58%;
    }

    .bars span:nth-child(3) {
      height: 46%;
    }

    .bars span:nth-child(4) {
      height: 76%;
    }

    .bars span:nth-child(5) {
      height: 100%;
      background: var(--accent);
    }

    /* ------------------------------------------------------------ the controls */

    .bottom {
      display: grid;
      gap: var(--space-2);
      padding: var(--space-2) var(--space-5) 0;
    }

    .dots {
      display: flex;
      justify-content: center;
    }

    /* A dot to look at, a finger's worth of button to press. */
    .dot {
      display: grid;
      place-items: center;
      width: 2.25rem;
      height: 2.75rem;
      padding: 0;
      border: 0;
      border-radius: var(--radius-full);
      background: none;
      cursor: pointer;
    }

    .dot::before {
      content: '';
      width: 0.5rem;
      height: 0.5rem;
      border-radius: var(--radius-full);
      background: var(--border-strong);
      transition:
        width 160ms ease,
        background-color 160ms ease;
    }

    .dot.on::before {
      width: 1.25rem;
      background: var(--primary);
    }

    .announce {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
    }
  `,
})
export class IntroScreen {
  private readonly intro = inject(Intro);
  private readonly router = inject(Router);
  private readonly nav = inject(Navigation);
  private readonly session = inject(SessionStore);

  protected readonly pages = INTRO_PAGES;

  protected readonly icons = {
    music: Music,
    scan: ScanLine,
    ticket: Ticket,
    pin: MapPin,
    heart: Heart,
    code: QrCode,
    offline: WifiOff,
    money: Banknote,
  };

  /** The page on screen. */
  readonly current = signal(0);

  readonly last = computed(() => this.current() === INTRO_PAGES.length - 1);

  /** What the live region says: the page moved to, never the one the screen opened on. */
  readonly announcement = signal('');

  private readonly track = viewChild.required<ElementRef<HTMLElement>>('track');

  /** Read once, as app.config does: the phone asks for a relaunch when it changes. */
  private readonly lessMotion = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

  private announced = 0;

  /** Where a Next or a dot is scrolling to, so the pages it passes are not each announced. */
  private steering: number | null = null;
  private settling: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    // Android's back and the iOS edge swipe go back a page, and from the
    // first page out — which is a skip, and remembered like one.
    this.nav.claimBack(this, () => (this.current() > 0 ? this.go(this.current() - 1) : void this.skip()));

    inject(DestroyRef).onDestroy(() => {
      this.nav.releaseBack(this);
      if (this.settling) clearTimeout(this.settling);
    });
  }

  /** To a page, by a button or a key. */
  go(index: number): void {
    const page = Math.max(0, Math.min(INTRO_PAGES.length - 1, index));
    const track = this.track().nativeElement;

    this.steering = page;
    this.show(page);

    // Optional: jsdom, where the specs run, has no scrolling to do.
    track.scrollTo?.({ left: page * track.clientWidth, behavior: this.lessMotion ? 'auto' : 'smooth' });
  }

  forward(): void {
    if (this.last()) void this.getStarted();
    else this.go(this.current() + 1);
  }

  /** A finger moving the pages: the dots follow it, and where it stops is said. */
  protected onScroll(): void {
    const track = this.track().nativeElement;
    const page = track.clientWidth > 0 ? Math.round(track.scrollLeft / track.clientWidth) : 0;

    if (this.steering === null) this.current.set(page);

    if (this.settling) clearTimeout(this.settling);

    this.settling = setTimeout(() => {
      this.steering = null;
      this.show(page);
    }, 150);
  }

  protected onKey(event: KeyboardEvent): void {
    const to: Record<string, number> = {
      ArrowRight: this.current() + 1,
      ArrowLeft: this.current() - 1,
      Home: 0,
      End: INTRO_PAGES.length - 1,
    };

    if (!(event.key in to)) return;

    event.preventDefault();
    this.go(to[event.key]);
  }

  /**
   * Skip: back where it was opened from when that was Settings, and What's on
   * when it was the first thing the app showed.
   */
  async skip(): Promise<void> {
    await this.intro.finish();

    if (this.nav.canPop()) this.nav.back('/');
    else await this.router.navigateByUrl('/', { replaceUrl: true });
  }

  /** The end of it: What's on, which needs no account. */
  async getStarted(): Promise<void> {
    await this.intro.finish();
    await this.router.navigateByUrl('/', { replaceUrl: true });
  }

  /**
   * The organizer screens: straight there for somebody already signed in to
   * run events, and through signing in for everybody else, coming back to
   * them afterwards rather than to a tickets list.
   */
  async runEvents(): Promise<void> {
    await this.intro.finish();

    if (this.session.canSeeSales()) {
      await this.router.navigateByUrl('/manage', { replaceUrl: true });
      return;
    }

    await this.router.navigate(['/sign-in'], { queryParams: { next: '/manage' }, replaceUrl: true });
  }

  private show(page: number): void {
    this.current.set(page);

    if (page === this.announced) return;

    this.announced = page;
    this.announcement.set(`Page ${page + 1} of ${INTRO_PAGES.length}: ${INTRO_PAGES[page].title}`);
  }
}
