import { TestBed } from '@angular/core/testing';
import { beforeAll, describe, expect, it } from 'vitest';
import { allowDialogs } from '../../core/confirm-testing';
import { CopyDialog, type CopyChoice, type CopyTier } from './copy-dialog';

const TIERS: CopyTier[] = [
  { id: 'ga', name: 'General', price: { amount: 2500, currency: 'CAD' }, quantity_available: 100 },
  { id: 'vip', name: 'VIP', price: { amount: 6000, currency: 'CAD' }, quantity_available: null },
];

/**
 * The questions asked on the way to a new event made from an old one.
 *
 * What matters is what is sent: a date, and only what was changed. A tier
 * nobody touched is not named, so its price is never re-typed from a rounded
 * display; a tier left out is named as left out; and every change is said
 * back in a sentence for the question that follows.
 */
describe('CopyDialog', () => {
  beforeAll(() => allowDialogs());

  function open(overrides: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(CopyDialog);

    fixture.componentRef.setInput('heading', 'Copy Afro Fest');
    fixture.componentRef.setInput('title', 'Afro Fest');
    fixture.componentRef.setInput('description', '<p>Afrobeats until late.</p>');
    fixture.componentRef.setInput('timezone', 'America/Toronto');
    fixture.componentRef.setInput('lengthMinutes', 360);
    fixture.componentRef.setInput('tiers', TIERS);

    for (const [name, value] of Object.entries(overrides)) fixture.componentRef.setInput(name, value);

    fixture.componentRef.setInput('open', true);
    fixture.detectChanges();

    const dialog = fixture.componentInstance;
    const chosen: CopyChoice[] = [];
    dialog.submitted.subscribe((choice) => chosen.push(choice));

    return { fixture, dialog, chosen };
  }

  it('sends the date on the event’s clock and nothing else when nothing changed', () => {
    const { dialog, chosen } = open();

    dialog.starts.set('2026-12-05T21:00');
    dialog.submit();

    expect(chosen).toHaveLength(1);
    expect(chosen[0].changes).toEqual({
      starts_at: '2026-12-06T02:00:00.000Z',
      title: 'Afro Fest',
      include: { add_ons: true, questions: true, reminders: true },
    });
    expect(chosen[0].changed).toEqual([]);
  });

  it('names only the tiers that changed, and says each change back', () => {
    const { dialog, chosen } = open();

    dialog.starts.set('2026-12-05T21:00');
    dialog.updateRow('vip', { price: '75', name: 'VIP table' });
    dialog.submit();

    expect(chosen[0].changes.ticket_types).toEqual([{ id: 'vip', name: 'VIP table', price_amount: 7500 }]);
    expect(chosen[0].changed).toEqual(['VIP: renamed VIP table, $60.00 → $75.00.']);
  });

  it('leaves a tier out, and an emptied quantity is unlimited rather than unchanged', () => {
    const { dialog, chosen } = open();

    dialog.starts.set('2026-12-05T21:00');
    dialog.updateRow('vip', { include: false });
    dialog.updateRow('ga', { quantity: '' });
    dialog.submit();

    expect(chosen[0].changes.ticket_types).toEqual([
      { id: 'ga', quantity_available: null },
      { id: 'vip', include: false },
    ]);
    expect(chosen[0].changed).toContain('Left out: VIP.');
    expect(chosen[0].changed).toContain('General: 100 available → unlimited.');
  });

  it('leaves the extras, questions and reminders behind when asked, and sends a new description only when one was written', () => {
    const { dialog, chosen } = open();

    dialog.starts.set('2026-12-05T21:00');
    dialog.addOns.set(false);
    dialog.reminders.set(false);
    dialog.submit();

    expect(chosen[0].changes.include).toEqual({ add_ons: false, questions: true, reminders: false });
    expect('description' in chosen[0].changes).toBe(false);
    expect(chosen[0].changed).toContain('No extras and reminders on the new one.');

    dialog.rewrite.set(true);
    dialog.draftDescription.set('');
    dialog.submit();

    // Written empty on purpose: the new event has no description.
    expect(chosen[1].changes.description).toBe('');
  });

  it('makes nothing without a start, with an end before it, or with a price it cannot read', () => {
    const { dialog, chosen } = open();

    dialog.submit();
    expect(dialog.startProblem()).toBe('Choose when the new event starts.');

    dialog.starts.set('2026-12-05T21:00');
    dialog.ends.set('2026-12-05T20:00');
    dialog.submit();
    expect(dialog.endProblem()).toBe('The new event has to end after it starts.');

    dialog.ends.set('');
    dialog.updateRow('ga', { price: 'twenty' });
    dialog.submit();
    // Said under the price, not the name.
    expect(dialog.tierProblems().get('ga')).toEqual({ name: null, price: expect.any(String), quantity: null });

    dialog.updateRow('ga', { price: '25.00', quantity: 3_000_000_000 });
    expect(dialog.tierProblems().get('ga')?.quantity).toBe('That is more than one tier can hold. Leave it empty for unlimited.');
    dialog.submit();

    expect(chosen).toHaveLength(0);
  });

  it('marks the price box itself when the price cannot be read', () => {
    const { fixture, dialog } = open();

    dialog.updateRow('ga', { price: 'twenty' });
    fixture.detectChanges();

    // Each tier is a group named for it, so its boxes are read out as its own.
    const general = (fixture.nativeElement as HTMLElement).querySelector('fieldset fieldset') as HTMLFieldSetElement;
    const price = general.querySelector('input[inputmode="decimal"]') as HTMLInputElement;
    const name = general.querySelector('input[maxlength="80"]') as HTMLInputElement;

    expect(general.querySelector('legend')?.textContent?.trim()).toBe('General');
    expect(price.getAttribute('aria-invalid')).toBe('true');
    expect(name.getAttribute('aria-invalid')).toBeNull();
  });

  it('says back a new end and a new title, so nothing changed is never claimed of them', () => {
    const { dialog, chosen } = open();

    dialog.starts.set('2026-12-05T21:00');
    dialog.ends.set('2026-12-06T02:00');
    dialog.draftTitle.set('Afro Fest: Winter');
    dialog.submit();

    expect(chosen[0].changes.ends_at).toBe('2026-12-06T07:00:00.000Z');
    expect(chosen[0].changed).toHaveLength(2);
    expect(chosen[0].changed[0]).toBe('Called Afro Fest: Winter instead of Afro Fest.');
    // On the event's clock, whatever the machine's.
    expect(chosen[0].changed[1]).toMatch(/^Ends Sunday.*6.*2026.*2:00/);
  });

  it('says what an empty end means, and starts afresh each time it opens', () => {
    const { fixture, dialog } = open();

    expect(dialog.endHint()).toBe('Leave it empty to keep it 6 hours long.');

    dialog.starts.set('2026-12-05T21:00');
    dialog.updateRow('ga', { include: false });

    fixture.componentRef.setInput('open', false);
    fixture.detectChanges();
    fixture.componentRef.setInput('open', true);
    fixture.detectChanges();

    expect(dialog.starts()).toBe('');
    expect(dialog.rows().every((row) => row.include)).toBe(true);
  });
});
