import { Component, inject, input } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { SessionStore } from '../core/session';

/**
 * The bar along the bottom, for the two or three places somebody lives in.
 *
 * Not ion-tabs. That brings its own router outlet and its own idea of what a
 * tab looks like on each platform; what is needed is three links a thumb can
 * reach, sitting above the home indicator.
 *
 * Hidden entirely for a door pass: that phone has one screen and no navigation
 * away from it, and a bar offering two more is an invitation to try.
 */
@Component({
  selector: 'mf-tabs',
  imports: [RouterLink, RouterLinkActive],
  template: `
    @if (!session.locked()) {
      <nav class="bar" aria-label="Sections">
        @for (tab of tabs(); track tab.link) {
          <a
            class="tab"
            [routerLink]="tab.link"
            routerLinkActive="on"
            [routerLinkActiveOptions]="{ exact: tab.exact ?? false }"
          >
            <span class="glyph" [class]="tab.glyph" aria-hidden="true"></span>
            <span class="label">{{ tab.label }}</span>
          </a>
        }
      </nav>
    }
  `,
  styles: `
    .bar {
      display: grid;
      grid-auto-flow: column;
      grid-auto-columns: 1fr;
      border-top: 1px solid var(--border-subtle);
      background: var(--surface);
      padding-bottom: var(--mf-safe-bottom);
    }

    .tab {
      display: grid;
      justify-items: center;
      gap: 4px;
      padding: var(--space-2) 0 var(--space-1);
      min-height: var(--mf-tap);
      color: var(--text-subtle);
      text-decoration: none;
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-medium);
    }

    .tab.on {
      color: var(--primary-text);
    }

    /*
     * The glyphs are drawn here rather than imported: three shapes is less
     * weight than an icon package, and they are the only ones this bar needs.
     */
    .glyph {
      width: 22px;
      height: 22px;
      border: 2px solid currentColor;
    }

    .glyph.home {
      border-radius: 4px 4px 3px 3px;
      border-top-width: 10px;
      border-top-color: transparent;
      box-shadow: inset 0 -2px 0 currentColor;
      position: relative;
    }

    .glyph.home::before {
      content: '';
      position: absolute;
      inset: -12px -2px auto -2px;
      height: 12px;
      border: 2px solid currentColor;
      border-bottom: 0;
      border-radius: 4px 4px 0 0;
      transform: scaleX(0.72);
    }

    .glyph.ticket {
      border-radius: 4px;
      position: relative;
    }

    .glyph.ticket::before,
    .glyph.ticket::after {
      content: '';
      position: absolute;
      top: 50%;
      width: 6px;
      height: 6px;
      margin-top: -3px;
      border-radius: 50%;
      background: var(--surface);
      box-shadow: 0 0 0 2px currentColor;
    }

    .glyph.ticket::before {
      left: -5px;
    }

    .glyph.ticket::after {
      right: -5px;
    }

    .glyph.events {
      border-radius: 4px;
      border-top-width: 6px;
    }

    .glyph.person {
      border-radius: 50%;
      border-bottom-color: transparent;
      transform: translateY(2px);
    }
  `,
})
export class MfTabs {
  readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  readonly tabs = input<{ link: string; label: string; glyph: string; exact?: boolean }[]>([]);
}
