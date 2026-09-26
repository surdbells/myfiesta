import { Component, inject, input } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { CircleUser, Compass, LayoutDashboard, Ticket, type LucideIconData } from 'lucide-angular';
import { SessionStore } from '../core/session';
import { Chrome } from '../core/chrome';
import { MfIcon } from './icon';

export interface MfTab {
  link: string;
  label: string;
  glyph: 'home' | 'manage' | 'ticket' | 'person';
  exact?: boolean;
}

const GLYPHS: Record<MfTab['glyph'], LucideIconData> = {
  home: Compass,
  manage: LayoutDashboard,
  ticket: Ticket,
  person: CircleUser,
};

/**
 * The bar along the bottom, for the few places somebody lives in.
 *
 * It has states, and they come from Chrome rather than from any one screen:
 *
 * - the active tab is said twice — a filled pill behind a heavier icon, and
 *   the colour — so it survives a colour-blind reader and a dim screen;
 * - a badge counts what is waiting behind a tab;
 * - it **tucks** away while a long screen is scrolled down and comes back on
 *   the first scroll up, so a list gets the whole phone while it is read;
 * - it is **gone** while the keyboard is up (a bar floating over the field
 *   being typed into) and while a task is open;
 * - tapping the tab you are already on takes that screen back to its top.
 *
 * It floats over the content rather than taking a row of its own, so lists
 * scroll under it; screens leave room for it through --mf-bar-space.
 *
 * Hidden entirely for a door pass: that phone has one screen and no way to
 * move from it, and a bar offering more is an invitation to try.
 */
@Component({
  selector: 'mf-tabs',
  imports: [RouterLink, RouterLinkActive, MfIcon],
  template: `
    @if (!session.locked()) {
      <nav class="bar" aria-label="Sections" [class.tucked]="chrome.barTucked()" [class.gone]="chrome.barHidden()">
        @for (tab of tabs(); track tab.link) {
          <a
            class="tab"
            [routerLink]="tab.link"
            routerLinkActive="on"
            #active="routerLinkActive"
            [routerLinkActiveOptions]="{ exact: tab.exact ?? false }"
            [attr.aria-current]="active.isActive ? 'page' : null"
            (click)="tapped(active.isActive)"
          >
            <span class="pill">
              <mf-icon [icon]="glyph(tab)" [bold]="active.isActive" />
              @if (count(tab); as n) {
                <span class="badge" [attr.aria-label]="n + ' waiting'">{{ n > 99 ? '99+' : n }}</span>
              }
            </span>
            <span class="label">{{ tab.label }}</span>
          </a>
        }
      </nav>
    }
  `,
  styles: `
    :host {
      position: absolute;
      z-index: 30;
      left: 0;
      right: 0;
      bottom: 0;
      pointer-events: none;
    }

    .bar {
      pointer-events: auto;
      display: grid;
      grid-auto-flow: column;
      grid-auto-columns: 1fr;
      height: calc(var(--mf-tab-bar) + var(--mf-safe-bottom));
      padding: 6px var(--space-2) var(--mf-safe-bottom);
      border-top: 1px solid var(--border-subtle);
      /* Translucent over whatever is scrolling underneath, which is what
         separates a bar that belongs to the phone from one drawn on the page.
         The solid colour is the fallback where a browser has no blur. */
      background: var(--surface);
      background: color-mix(in srgb, var(--surface) 82%, transparent);
      backdrop-filter: blur(20px) saturate(1.7);
      box-shadow: 0 -10px 30px -22px rgb(0 0 0 / 0.35);
      transition:
        transform 260ms var(--mf-ease-out),
        opacity 200ms ease;
    }

    .bar.tucked {
      transform: translateY(calc(100% - var(--mf-safe-bottom) + 2px));
    }

    .bar.gone {
      transform: translateY(100%);
      opacity: 0;
      pointer-events: none;
    }

    .tab {
      display: grid;
      justify-items: center;
      align-content: start;
      gap: 3px;
      padding-top: 2px;
      color: var(--text-subtle);
      text-decoration: none;
      -webkit-user-select: none;
      user-select: none;
      transition: color 150ms ease;
    }

    .tab:focus-visible {
      outline: none;
    }

    .tab:focus-visible .pill {
      box-shadow: 0 0 0 2px var(--primary);
    }

    .pill {
      position: relative;
      display: grid;
      place-items: center;
      width: 56px;
      height: 30px;
      border-radius: var(--radius-full);
      transition:
        background-color 200ms ease,
        transform 120ms ease;
    }

    .tab:active .pill {
      transform: scale(0.92);
    }

    .tab.on {
      color: var(--primary-text);
    }

    .tab.on .pill {
      background: var(--primary-soft);
    }

    .label {
      font-size: 11px;
      font-weight: var(--font-weight-medium);
      letter-spacing: 0.01em;
    }

    .tab.on .label {
      font-weight: var(--font-weight-semibold);
    }

    .badge {
      position: absolute;
      top: -3px;
      left: calc(50% + 6px);
      min-width: 18px;
      height: 18px;
      padding: 0 5px;
      border-radius: var(--radius-full);
      background: var(--danger);
      color: var(--color-neutral-0);
      font-size: 10px;
      font-weight: var(--font-weight-bold);
      line-height: 18px;
      text-align: center;
      font-variant-numeric: tabular-nums;
      box-shadow: 0 0 0 2px var(--surface);
    }
  `,
})
export class MfTabs {
  readonly session = inject(SessionStore);
  readonly chrome = inject(Chrome);

  readonly tabs = input<MfTab[]>([]);

  protected glyph(tab: MfTab): LucideIconData {
    return GLYPHS[tab.glyph];
  }

  protected count(tab: MfTab): number {
    return this.chrome.badges()[tab.link] ?? 0;
  }

  /** The tab you are already on: back to the top of it. */
  protected tapped(alreadyThere: boolean): void {
    if (alreadyThere) this.chrome.scrollTopRequested.update((n) => n + 1);
  }
}
