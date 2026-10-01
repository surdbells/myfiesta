import { Component, computed, effect, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { UiButton, UiField, UiModal } from '@myfiesta/ui';
import type { CopyAdjustments, CopyTierChange, Money } from '../../core/api.types';
import { longEventTime } from '../../core/event-time';
import { amountProblem, formatMoney, toMajorUnits, toMinorUnits } from '../../core/money';
import { describeZone, zonedWallClockToIso } from '../../core/zoned-time';
import { RichTextEditor } from '../../shared/rich-text-editor';

/** A tier as the dialog offers it: from the event's tiers, or a template's. */
export interface CopyTier {
  id: string;
  name: string;
  price: Money;
  quantity_available: number | null;
}

/** What a new event is made with, and the same said in sentences for the question that follows. */
export interface CopyChoice {
  changes: CopyAdjustments & { starts_at: string };
  title: string;
  /** Each change worth saying back before it is made, one per line. */
  changed: string[];
}

/** What is wrong with each box on a tier's row, or null while it is fine. */
export interface TierProblems {
  name: string | null;
  price: string | null;
  quantity: string | null;
}

/** The most one tier can hold: the API refuses more, as its column cannot keep it. */
const MAX_QUANTITY = 2_147_483_647;

/** One tier's row, as typed. */
interface TierRow {
  tier: CopyTier;
  include: boolean;
  name: string;
  /** Major units, because that is what somebody types. */
  price: string;
  /** Whatever the number control handed back; empty is unlimited. */
  quantity: number | string | null;
}

/**
 * A new event from an old one, with the changes made on the way: when it
 * starts and ends, its title and description, which tiers and at what price
 * and size, and whether the extras, questions and reminders come too.
 *
 * Used twice — copying an event from its Overview (DuplicatePart) and making
 * one from a template (Templates) — so both ask the same questions in the
 * same words. It asks and hands back; the screen that opened it says the
 * choice back and makes the call, because only it knows what is copied from.
 *
 * Only what changed is sent. A tier left as it was is not named, so a price
 * nobody touched is never re-typed from a rounded display.
 */
@Component({
  selector: 'app-copy-dialog',
  imports: [FormsModule, UiButton, UiField, UiModal, RichTextEditor],
  templateUrl: './copy-dialog.html',
})
export class CopyDialog {
  readonly open = input(false);
  readonly heading = input.required<string>();
  readonly intro = input<string | null>(null);
  /** The title the new event starts with. */
  readonly title = input('');
  readonly description = input<string | null>(null);
  /** Whose clock the dates are typed on: the event's. */
  readonly timezone = input('UTC');
  /** How long the night it copies ran, to say what an empty end means. */
  readonly lengthMinutes = input<number | null>(null);
  readonly tiers = input<readonly CopyTier[]>([]);
  /** How many of each it carries, when known (a template knows; an event's Overview does not). */
  readonly counts = input<{ add_ons: number; questions: number; reminders: number } | null>(null);
  readonly busy = input(false);
  readonly error = input<string | null>(null);
  readonly submitLabel = input('Make the copy');

  readonly submitted = output<CopyChoice>();
  readonly dismissed = output<void>();

  readonly draftTitle = signal('');
  readonly starts = signal('');
  readonly ends = signal('');
  readonly rewrite = signal(false);
  readonly draftDescription = signal('');
  readonly rows = signal<TierRow[]>([]);
  readonly addOns = signal(true);
  readonly questions = signal(true);
  readonly reminders = signal(true);
  /** Set once somebody has tried to make it, so a blank form does not open covered in errors. */
  readonly tried = signal(false);

  /** Dates are typed on the event's clock, wherever the organizer is sitting. */
  readonly zoneHint = computed(() => `On the event's clock: ${describeZone(this.timezone())}.`);

  /** "Leave it empty to keep it 6 hours long." */
  readonly endHint = computed(() => {
    const minutes = this.lengthMinutes();

    if (minutes === null) return 'Optional. Leave it empty for no set end.';

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    const length = [hours ? `${hours} ${hours === 1 ? 'hour' : 'hours'}` : '', rest ? `${rest} minutes` : '']
      .filter(Boolean)
      .join(' ');

    return `Leave it empty to keep it ${length || 'the same length'} long.`;
  });

  readonly startProblem = computed(() => {
    if (this.starts() === '') return 'Choose when the new event starts.';
    if (zonedWallClockToIso(this.starts(), this.timezone()) === null) return 'That start is not a date and time.';

    return null;
  });

  readonly endProblem = computed(() => {
    const start = zonedWallClockToIso(this.starts(), this.timezone());
    const end = this.ends() === '' ? null : zonedWallClockToIso(this.ends(), this.timezone());

    if (this.ends() !== '' && end === null) return 'That end is not a date and time.';
    if (start && end && Date.parse(end) <= Date.parse(start)) return 'The new event has to end after it starts.';

    return null;
  });

  /**
   * Why a kept tier cannot be made as typed, by tier id, field by field: each
   * message sits under the box it is about, and that box is the one marked.
   */
  readonly tierProblems = computed(() => {
    const problems = new Map<string, TierProblems>();

    for (const row of this.rows()) {
      if (!row.include) continue;

      const found: TierProblems = {
        name: row.name.trim() === '' ? 'Give it a name.' : null,
        price: amountProblem(row.price) ?? (toMinorUnits(row.price) === null ? 'Type a price, like 25.00.' : null),
        quantity: this.quantityProblem(row),
      };

      if (found.name || found.price || found.quantity) problems.set(row.tier.id, found);
    }

    return problems;
  });

  readonly ready = computed(
    () =>
      this.startProblem() === null &&
      this.endProblem() === null &&
      this.tierProblems().size === 0 &&
      this.draftTitle().trim().length > 0,
  );

  readonly formatMoney = formatMoney;

  constructor() {
    // A fresh form each time it opens, from whatever it copies now.
    effect(() => {
      if (this.open()) untracked(() => this.reset());
    });
  }

  updateRow(id: string, change: Partial<Omit<TierRow, 'tier'>>): void {
    this.rows.update((rows) => rows.map((row) => (row.tier.id === id ? { ...row, ...change } : row)));
  }

  submit(): void {
    this.tried.set(true);

    const startsAt = zonedWallClockToIso(this.starts(), this.timezone());

    if (!this.ready() || this.busy() || !startsAt) return;

    const title = this.draftTitle().trim();
    const changes: CopyAdjustments & { starts_at: string } = {
      starts_at: startsAt,
      title,
      include: { add_ons: this.addOns(), questions: this.questions(), reminders: this.reminders() },
    };

    const endsAt = this.ends() === '' ? null : zonedWallClockToIso(this.ends(), this.timezone());
    if (endsAt) changes.ends_at = endsAt;

    if (this.rewrite()) changes.description = this.draftDescription();

    const tierChanges = this.rows()
      .map((row) => this.changeOf(row))
      .filter((change): change is CopyTierChange => change !== null);

    if (tierChanges.length > 0) changes.ticket_types = tierChanges;

    this.submitted.emit({ changes, title, changed: this.said(title, endsAt) });
  }

  /**
   * Each change, in a sentence, for the question that follows. Everything
   * sent that differs from what is copied is here, so a question that finds
   * nothing to list can say nothing else changes and be right.
   */
  private said(title: string, endsAt: string | null): string[] {
    const lines: string[] = [];
    const left = this.rows().filter((row) => !row.include);

    if (title !== this.title()) lines.push(`Called ${title} instead of ${this.title()}.`);
    if (endsAt) lines.push(`Ends ${longEventTime(endsAt, this.timezone())}.`);

    if (left.length > 0) {
      lines.push(`Left out: ${left.map((row) => row.tier.name).join(', ')}.`);
    }

    for (const row of this.rows()) {
      if (!row.include) continue;

      const name = row.name.trim();
      const price = toMinorUnits(row.price);
      const quantity = this.quantityOf(row);
      const parts: string[] = [];

      if (name !== row.tier.name) parts.push(`renamed ${name}`);
      if (price !== null && price !== row.tier.price.amount) {
        parts.push(`${formatMoney(row.tier.price)} → ${formatMoney({ amount: price, currency: row.tier.price.currency })}`);
      }
      if (quantity !== row.tier.quantity_available) {
        parts.push(`${this.capacity(row.tier.quantity_available)} → ${this.capacity(quantity)}`);
      }

      if (parts.length > 0) lines.push(`${row.tier.name}: ${parts.join(', ')}.`);
    }

    const behind = [
      this.addOns() ? null : 'extras',
      this.questions() ? null : 'questions',
      this.reminders() ? null : 'reminders',
    ].filter((what): what is string => what !== null);

    if (behind.length > 0) {
      const list = behind.length === 1 ? behind[0] : `${behind.slice(0, -1).join(', ')} and ${behind[behind.length - 1]}`;
      lines.push(`No ${list} on the new one.`);
    }

    if (this.rewrite()) lines.push('It gets the new description you wrote.');

    return lines;
  }

  /** A row as the API names it, or null when nothing about it changed. */
  private changeOf(row: TierRow): CopyTierChange | null {
    if (!row.include) return { id: row.tier.id, include: false };

    const change: CopyTierChange = { id: row.tier.id };
    const name = row.name.trim();
    const price = toMinorUnits(row.price);
    const quantity = this.quantityOf(row);

    if (name !== row.tier.name) change.name = name;
    if (price !== null && price !== row.tier.price.amount) change.price_amount = price;
    if (quantity !== row.tier.quantity_available) change.quantity_available = quantity;

    return Object.keys(change).length > 1 ? change : null;
  }

  private quantityProblem(row: TierRow): string | null {
    const quantity = this.quantityOf(row);

    if (quantity === null) return null;
    if (!Number.isInteger(quantity) || quantity < 0) return 'How many is a whole number, or empty for unlimited.';
    if (quantity > MAX_QUANTITY) return 'That is more than one tier can hold. Leave it empty for unlimited.';

    return null;
  }

  private quantityOf(row: TierRow): number | null {
    if (row.quantity === null || row.quantity === '') return null;

    const value = Number(row.quantity);

    return Number.isNaN(value) ? NaN : value;
  }

  private capacity(quantity: number | null): string {
    return quantity === null ? 'unlimited' : `${quantity.toLocaleString('en-CA')} available`;
  }

  private reset(): void {
    this.draftTitle.set(this.title());
    this.starts.set('');
    this.ends.set('');
    this.rewrite.set(false);
    this.draftDescription.set(this.description() ?? '');
    this.addOns.set(true);
    this.questions.set(true);
    this.reminders.set(true);
    this.tried.set(false);
    this.rows.set(
      this.tiers().map((tier) => ({
        tier,
        include: true,
        name: tier.name,
        price: String(toMajorUnits(tier.price.amount)),
        quantity: tier.quantity_available,
      })),
    );
  }
}
