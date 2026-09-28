import { Component, DestroyRef, ElementRef, afterNextRender, inject, input, signal, viewChild } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { filter } from 'rxjs';

export interface TabLink {
  label: string;
  route: unknown[];
  /** Shown after the label — an outstanding count, a total. */
  count?: number | null;
  /**
   * Match the whole URL rather than its prefix.
   *
   * Needed by the tab that points at the parent route itself — an
   * Overview at /events/:id is a prefix of every sibling, so without this
   * it stays lit on all of them and two tabs claim to be the current one.
   */
  exact?: boolean;
}

/**
 * Navigation within one thing.
 *
 * Real links, not buttons that swap a signal, so each tab has a URL somebody
 * can bookmark, open in a new tab, or land on from an email. The event
 * workspace is the reason this exists: eight sibling routes reached by
 * returning to a parent each time is not a workspace.
 *
 * Marked as a tablist for assistive technology while remaining ordinary links,
 * because that is what they are — the panel is a routed view, not hidden
 * content on the same page.
 *
 * When the row is wider than the page it scrolls, and says so: the edge with
 * more tabs past it fades out, and the current tab is always scrolled into
 * view. A row cut off cleanly at a tab boundary otherwise reads as the whole
 * row — "Flyer & gallery" and "Settings" sat past the right edge of an event
 * at laptop widths with nothing to say they were there.
 */
@Component({
  selector: 'ui-tabs',
  imports: [RouterLink, RouterLinkActive],
  template: `
    <nav
      #bar
      class="tabs"
      [class.tabs--more-before]="moreBefore()"
      [class.tabs--more-after]="moreAfter()"
      [attr.aria-label]="label()"
      (scroll)="measure()"
    >
      <ul>
        @for (tab of tabs(); track tab.label) {
          <li>
            <a
              [routerLink]="tab.route"
              routerLinkActive="is-active"
              [routerLinkActiveOptions]="{ exact: tab.exact ?? false }"
              #active="routerLinkActive"
              [attr.aria-current]="active.isActive ? 'page' : null"
            >
              {{ tab.label }}
              @if (tab.count !== null && tab.count !== undefined) {
                <span class="tabs__count">{{ tab.count }}</span>
              }
            </a>
          </li>
        }
      </ul>
    </nav>
  `,
  styles: `
    .tabs {
      border-bottom: 1px solid var(--border);
      /* Scrolls rather than wraps. A wrapped second row of tabs changes the
         page's height as you move between them. */
      overflow-x: auto;
      scrollbar-width: none;
    }
    .tabs::-webkit-scrollbar { display: none; }
    /* The fade is a mask, not a colour laid over the row, so it works on
       whatever the page behind it is. Three rem: about half a tab. */
    .tabs--more-after {
      mask-image: linear-gradient(to right, #000 calc(100% - 3rem), transparent);
    }
    .tabs--more-before {
      mask-image: linear-gradient(to left, #000 calc(100% - 3rem), transparent);
    }
    .tabs--more-before.tabs--more-after {
      mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
    }
    ul {
      display: flex;
      gap: var(--space-1);
      margin: 0;
      padding: 0;
      list-style: none;
      min-width: max-content;
    }
    a {
      display: inline-flex;
      align-items: center;
      gap: var(--space-2);
      padding: var(--space-3) var(--space-4);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
      color: var(--text-muted);
      text-decoration: none;
      white-space: nowrap;
      /* Transparent rather than absent, so the row does not shift by 2px when
         a tab becomes active. */
      border-bottom: 2px solid transparent;
      transition: color var(--motion-fast) var(--motion-ease);
    }
    a:hover { color: var(--text); }
    a.is-active {
      color: var(--primary-text);
      border-bottom-color: var(--primary);
    }
    a:focus-visible {
      outline: 2px solid var(--primary);
      outline-offset: -2px;
      border-radius: var(--radius-sm) var(--radius-sm) 0 0;
    }
    .tabs__count {
      padding: 1px var(--space-2);
      font-size: var(--font-size-xs);
      font-variant-numeric: tabular-nums;
      color: var(--text-muted);
      background-color: var(--surface-inset);
      border-radius: var(--radius-full);
    }
    a.is-active .tabs__count {
      color: var(--primary-soft-text);
      background-color: var(--primary-soft);
    }
  `,
})
export class UiTabs {
  readonly tabs = input.required<TabLink[]>();
  readonly label = input('Sections');

  /** More tabs past the left edge, and past the right. */
  readonly moreBefore = signal(false);
  readonly moreAfter = signal(false);

  private readonly bar = viewChild.required<ElementRef<HTMLElement>>('bar');

  constructor() {
    const destroyRef = inject(DestroyRef);

    afterNextRender(() => {
      this.revealCurrent();
      this.measure();

      if (typeof ResizeObserver !== 'undefined') {
        const resized = new ResizeObserver(() => this.measure());
        resized.observe(this.bar().nativeElement);
        destroyRef.onDestroy(() => resized.disconnect());
      }
    });

    // A tab chosen from a link elsewhere, or by the back button, may be one
    // scrolled out of sight: bring it in once the link has been marked.
    const moved = inject(Router)
      .events.pipe(filter((event) => event instanceof NavigationEnd))
      .subscribe(() => setTimeout(() => {
        this.revealCurrent();
        this.measure();
      }));
    destroyRef.onDestroy(() => moved.unsubscribe());
  }

  /** Whether there is more of the row either side of what shows. */
  measure(): void {
    const bar = this.bar().nativeElement;
    const hidden = bar.scrollWidth - bar.clientWidth;

    this.moreBefore.set(hidden > 1 && bar.scrollLeft > 1);
    this.moreAfter.set(hidden > 1 && bar.scrollLeft < hidden - 1);
  }

  /**
   * The current tab, scrolled into the row if it is past an edge — along
   * the row only, never the page up or down.
   */
  private revealCurrent(): void {
    const bar = this.bar().nativeElement;
    const current = bar.querySelector<HTMLElement>('a.is-active');

    if (!current) return;

    const row = bar.getBoundingClientRect();
    const tab = current.getBoundingClientRect();
    const room = 48;

    if (tab.left < row.left) {
      bar.scrollLeft -= row.left - tab.left + room;
    } else if (tab.right > row.right) {
      bar.scrollLeft += tab.right - row.right + room;
    }
  }
}
