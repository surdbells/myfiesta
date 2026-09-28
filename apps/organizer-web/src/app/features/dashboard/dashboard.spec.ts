import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { Overview } from '../../core/api.types';
import { Dashboard } from './dashboard';

const OVERVIEW = 'http://api.test/api/organizer/overview';

const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

function overview(balance: number): Overview {
  return {
    organization: { id: 'o-1', name: 'Lagos Nights' },
    currency: 'CAD',
    money: { balance: cad(balance), settled: cad(80_000), sold_7d: cad(0), sold_30d: cad(0), orders_7d: 0 },
    selling: { upcoming_events: 0, draft_events: 0, tickets_upcoming: 0 },
    next_event: null,
    attention: [],
    sales_by_day: [],
    recent_orders: [],
    selling_events: [],
  };
}

/**
 * The money tile the console opens on.
 *
 * After an advance, or refunds after a payout, the balance is below zero:
 * money owed back to myFiesta. It reads as that, with where to see how it is
 * paid back, never as "Owed to you" with a minus sign.
 */
describe('Dashboard', () => {
  let backend: HttpTestingController;

  const text = (fixture: { nativeElement: HTMLElement }) =>
    (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  function render(answer: Overview) {
    const fixture = TestBed.createComponent(Dashboard);
    backend.expectOne(OVERVIEW).flush(answer);
    fixture.detectChanges();

    return fixture;
  }

  it('says what is owed back to myFiesta, not a negative amount owed to them', () => {
    const fixture = render(overview(-18_000));
    const page = text(fixture);

    expect(page).toContain('You owe myFiesta');
    expect(page).toContain('$180.00');
    expect(page).not.toContain('-$180.00');
    expect(page).not.toContain('Owed to you');
    expect(page).toContain('Paid back from your next sales');
    expect(fixture.nativeElement.querySelector('a[href="/payouts"]')).not.toBeNull();
  });

  it('reads as before when they are owed money', () => {
    const page = text(render(overview(20_000)));

    expect(page).toContain('Owed to you');
    expect(page).toContain('$200.00');
    expect(page).not.toContain('You owe myFiesta');
  });
});
