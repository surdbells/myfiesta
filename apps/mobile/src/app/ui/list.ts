import { Component, booleanAttribute, computed, input, output } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ChevronRight } from 'lucide-angular';
import { MfIcon, type LucideIconData } from './icon';

/**
 * A group of rows on one raised surface: the shape of a settings screen, a
 * hub, the facts about an order.
 *
 * Elevated and rounded, with hairlines between rows that start after the icon
 * rather than running the full width — the separator belongs to the text, and
 * a line cutting under the icons turns a list into a table.
 */
@Component({
  selector: 'mf-list',
  template: `
    @if (heading()) {
      <h2 class="heading">{{ heading() }}</h2>
    }
    <div class="group" role="list">
      <ng-content />
    </div>
    @if (footer()) {
      <p class="footer">{{ footer() }}</p>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    .heading {
      margin: 0 var(--space-2) var(--space-2);
      font-family: var(--font-family-sans);
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--text-subtle);
    }

    .group {
      overflow: hidden;
      border-radius: var(--radius-card);
      background: var(--surface-raised);
      box-shadow:
        inset 0 0 0 1px var(--border-subtle),
        var(--shadow-raised);
    }

    .footer {
      margin: var(--space-2) var(--space-2) 0;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      line-height: var(--font-leading-snug);
    }
  `,
})
export class MfList {
  readonly heading = input<string | null>(null);
  readonly footer = input<string | null>(null);
}

/**
 * One row in an mf-list.
 *
 * A link when it goes somewhere (`link`), a button when it does something
 * (`pressed` is listened to and `action` is set), and plain text otherwise.
 * Whatever is projected sits on the right: a switch, a badge, a count.
 */
@Component({
  selector: 'mf-row',
  imports: [NgTemplateOutlet, RouterLink, MfIcon],
  template: `
    @if (link()) {
      <a class="row tappable" role="listitem" [routerLink]="link()" [class.danger]="danger()">
        <ng-container *ngTemplateOutlet="body" />
      </a>
    } @else if (action()) {
      <button type="button" class="row tappable" role="listitem" [class.danger]="danger()" [disabled]="disabled()" (click)="pressed.emit()">
        <ng-container *ngTemplateOutlet="body" />
      </button>
    } @else {
      <div class="row" role="listitem" [class.danger]="danger()">
        <ng-container *ngTemplateOutlet="body" />
      </div>
    }

    <ng-template #body>
      @if (icon()) {
        <span class="glyph" [class]="tone()"><mf-icon [icon]="icon()!" /></span>
      }
      <span class="text">
        <span class="label">{{ label() }}</span>
        @if (sub()) {
          <span class="sub">{{ sub() }}</span>
        }
      </span>
      @if (value()) {
        <span class="value">{{ value() }}</span>
      }
      <span class="trail"><ng-content /></span>
      @if (showChevron()) {
        <mf-icon class="chevron" [icon]="chevron" size="sm" />
      }
    </ng-template>
  `,
  host: { '[class.with-icon]': '!!icon()' },
  styles: `
    :host {
      display: block;
      position: relative;
    }

    /* The hairline, from the text's edge to the right. */
    :host + :host::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      left: var(--space-5);
      height: 1px;
      background: var(--border-subtle);
    }

    :host(.with-icon) + :host::before,
    :host + :host(.with-icon)::before {
      left: calc(var(--space-5) + 36px + var(--space-3));
    }

    .row {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      width: 100%;
      min-height: 56px;
      padding: var(--space-3) var(--space-4) var(--space-3) var(--space-5);
      border: 0;
      background: transparent;
      color: var(--text);
      font: inherit;
      text-align: left;
      text-decoration: none;
    }

    .row.tappable {
      cursor: pointer;
      transition: background-color 120ms ease;
    }

    .row.tappable:active {
      background: var(--surface-hover);
    }

    .row:disabled {
      opacity: 0.5;
    }

    .row:focus-visible {
      outline: 2px solid var(--primary);
      outline-offset: -2px;
    }

    .glyph {
      flex: none;
      display: grid;
      place-items: center;
      width: 36px;
      height: 36px;
      border-radius: var(--radius-md);
      background: var(--surface-inset);
      color: var(--text-muted);
    }

    .glyph.brand {
      background: var(--primary-soft);
      color: var(--primary-text);
    }

    .glyph.accent {
      background: var(--accent-soft);
      color: var(--text);
    }

    .glyph.warning {
      background: color-mix(in srgb, var(--warning) 16%, transparent);
      color: var(--warning);
    }

    .row.danger .glyph,
    .glyph.danger {
      background: color-mix(in srgb, var(--danger) 12%, transparent);
      color: var(--danger-text);
    }

    .row.danger .label {
      color: var(--danger-text);
    }

    .text {
      flex: 1;
      display: grid;
      gap: 2px;
      min-width: 0;
    }

    .label {
      font-weight: var(--font-weight-medium);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      line-height: var(--font-leading-snug);
    }

    .value {
      flex: none;
      max-width: 45%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-muted);
      font-variant-numeric: tabular-nums;
    }

    .trail:empty {
      display: none;
    }

    .trail {
      flex: none;
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }

    .chevron {
      color: var(--text-subtle);
    }
  `,
})
export class MfRow {
  readonly label = input.required<string>();
  readonly sub = input<string | null>(null);
  readonly value = input<string | null>(null);
  readonly icon = input<LucideIconData | null>(null);
  /** The tint behind the icon. */
  readonly tone = input<'plain' | 'brand' | 'accent' | 'warning' | 'danger'>('plain');
  readonly link = input<string | unknown[] | null>(null);
  readonly action = input(false, { transform: booleanAttribute });
  readonly danger = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });
  /** Force the chevron on or off; by default a link has one. */
  readonly chevronShown = input<boolean | null>(null, { alias: 'chevron' });

  readonly pressed = output<void>();

  protected readonly chevron = ChevronRight;

  protected readonly showChevron = computed(() => this.chevronShown() ?? (!!this.link() || this.action()));
}
