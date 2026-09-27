import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import type { WritableSignal } from '@angular/core';
import type { OrganizationOrderPage } from '@myfiesta/api-types';
import { describe, expect, it, vi } from 'vitest';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { OrgOrders } from './org-orders';

/**
 * The orders screen's "When" chips, as the server receives them.
 *
 * The chips count days on the phone's calendar. A date sent on its own is a
 * day in Greenwich to the server, which in Toronto moves "Today" back to the
 * evening before. So the phone's zone goes with the days, to the list and to
 * the spreadsheet alike, and stays off when no day is chosen.
 */
describe('the organization’s orders', () => {
  const empty: OrganizationOrderPage = {
    data: [],
    meta: { total: 0, per_page: 25, current_page: 1, last_page: 1, summary: null },
  };

  function screen() {
    const orders = vi.fn(async (_filters: Record<string, unknown>) => empty);
    const exportOrders = vi.fn(async (_filters: Record<string, unknown>) => 'Reference\n');

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Organizer, useValue: { orders, exportOrders, eventOptions: async () => [] } },
        { provide: SessionStore, useValue: { can: () => true } },
      ],
    });

    const page = TestBed.runInInjectionContext(() => new OrgOrders());
    const range = (page as unknown as { range: WritableSignal<string> }).range;

    return { page, range, orders, exportOrders };
  }

  const zone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

  it('sends the phone’s zone with the days it chose', async () => {
    const { page, range, orders } = screen();

    range.set('today');
    await page.reload();

    expect(orders).toHaveBeenLastCalledWith(expect.objectContaining({ timezone: zone() }));
  });

  it('exports the same days in the same zone', async () => {
    const { page, range, exportOrders } = screen();

    range.set('week');
    await (page as unknown as { export(): Promise<void> }).export().catch(() => undefined);

    expect(exportOrders).toHaveBeenCalledWith(expect.objectContaining({ timezone: zone() }));
  });

  it('names no zone when no day is chosen', async () => {
    const { page, orders } = screen();

    await page.reload();

    expect(orders.mock.lastCall?.[0]['timezone']).toBeUndefined();
  });
});
