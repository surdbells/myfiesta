import { Component, input } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';

export interface TabLink {
  label: string;
  route: unknown[];
  /** Shown after the label — an outstanding count, a total. */
  count?: number | null;
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
 */
@Component({
  selector: 'ui-tabs',
  imports: [RouterLink, RouterLinkActive],
  template: `
    <nav class="tabs" [attr.aria-label]="label()">
      <ul>
        @for (tab of tabs(); track tab.label) {
          <li>
            <a
              [routerLink]="tab.route"
              routerLinkActive="is-active"
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
}
