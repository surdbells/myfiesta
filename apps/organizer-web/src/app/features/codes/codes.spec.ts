import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, type TestRequest } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { ToastStore } from '@myfiesta/ui';
import { API_BASE_URL } from '../../core/api';
import type { OrganizationCode } from '../../core/api.types';
import { allowDialogs, answer, asked, forgetDialogs, settle as settleDialogs } from '../../core/confirm-testing';
import { Codes } from './codes';

/**
 * The organization's codes list: its filters as the server reads them, and
 * turning several codes off or on — asked first, naming them, and saying
 * which were left alone because their event is waiting for review.
 */
describe('Codes', () => {
  let backend: HttpTestingController;

  const code = (id: string, overrides: Partial<OrganizationCode> = {}): OrganizationCode => ({
    id,
    code: `CODE${id.toUpperCase()}`,
    label: null,
    discount_type: 'percentage',
    discount_value: 1000,
    discount_currency: null,
    ref_slug: null,
    promoter_name: null,
    redemption_count: 3,
    max_redemptions: 10,
    max_per_customer: null,
    min_quantity: null,
    ticket_types: [],
    unlocks: [],
    sales: [],
    starts_at: null,
    ends_at: null,
    is_active: true,
    event_scoped: true,
    usable: true,
    event: { id: 'ev-1', title: 'Afro Fest', starts_at: '2026-11-14T01:00:00Z', timezone: 'America/Toronto', status: 'published' },
    ...overrides,
  });

  const page = (rows: OrganizationCode[]) => ({
    data: rows,
    meta: { total: rows.length, per_page: 50, current_page: 1, last_page: 1, next: null },
  });

  beforeAll(allowDialogs);

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'codes', component: Codes }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
  });

  const listRequest = (): TestRequest => backend.expectOne((r) => r.url === 'http://api.test/api/organizer/codes');

  async function open(url = '/codes', rows: OrganizationCode[] = []) {
    const harness = await RouterTestingHarness.create();
    const screen = await harness.navigateByUrl(url, Codes);

    const settle = async () => {
      harness.detectChanges();
      await harness.fixture.whenStable();
      harness.detectChanges();
    };

    await settle();
    backend.expectOne('http://api.test/api/organizer/events/options').flush({ data: [] });
    for (const views of backend.match((r) => r.url === 'http://api.test/api/organizer/saved-views')) views.flush({ data: [] });
    const first = listRequest();
    first.flush(page(rows));
    await settle();

    return { screen, settle, first };
  }

  it('asks for several statuses, kinds and events, and the sort, as the link says', async () => {
    const { first } = await open('/codes?state=usable&state=scheduled&kind=promoter&event_id=all-events&sort=used&dir=desc');

    const params = first.request.params;
    expect(params.getAll('state[]')).toEqual(['usable', 'scheduled']);
    expect(params.getAll('kind[]')).toEqual(['promoter']);
    expect(params.getAll('event_id[]')).toEqual(['all-events']);
    expect(params.get('sort')).toBe('used');
    expect(params.get('dir')).toBe('desc');
  });

  it('turns the ticked codes off after naming them, and says which were left alone', async () => {
    const { screen, settle } = await open('/codes', [code('a'), code('b'), code('c')]);
    const toasts = TestBed.inject(ToastStore);

    screen.selection.toggle('a');
    screen.selection.toggle('b');

    const done = screen.setActive(false);
    await settleDialogs();

    expect(asked()?.title).toBe('Turn 2 codes off?');
    expect(asked()?.text).toContain('CODEA and CODEB');

    await answer('Turn them off');

    const request = backend.expectOne('http://api.test/api/organizer/codes/active');
    expect(request.request.body).toEqual({ ids: ['a', 'b'], active: false });
    request.flush({ changed: 1, skipped: [{ id: 'b', code: 'CODEB', reason: 'Its event is waiting for review.' }] });

    await done;
    await settle();
    listRequest().flush(page([code('a', { is_active: false }), code('b'), code('c')]));

    const messages = toasts.toasts().map((toast) => toast.message);
    expect(messages).toContain('1 code turned off.');
    expect(messages.some((m) => m.startsWith('CODEB left as it was'))).toBe(true);
    expect(screen.selection.count()).toBe(0);
  });

  it('changes nothing when the answer is no', async () => {
    const { screen } = await open('/codes', [code('a')]);

    screen.selection.toggle('a');
    const done = screen.setActive(false);
    await settleDialogs();
    await answer('Cancel');
    await done;

    backend.expectNone('http://api.test/api/organizer/codes/active');
    expect(screen.selection.count()).toBe(1);
  });
});
