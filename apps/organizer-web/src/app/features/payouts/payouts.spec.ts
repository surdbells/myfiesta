import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { PayoutOverdraft, PayoutStatement } from '../../core/api.types';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { Payouts } from './payouts';

const PAYOUTS = 'http://api.test/api/organizer/payouts';

const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

function statement(balance: number, overdraft: PayoutOverdraft | null): PayoutStatement {
  return {
    currency: 'CAD',
    balance: cad(balance),
    settled: cad(80_000),
    overdraft,
    events: [],
    settlements: [
      {
        id: 's-1',
        amount: cad(80_000),
        rail: 'interac',
        type: 'overdraft',
        status: 'success',
        note: 'Advance of $300.00: agreed on the phone.',
        event: null,
        settled_at: '2026-10-12T19:00:00+00:00',
      },
    ],
    destination: {
      rail: 'interac',
      currency: 'CAD',
      interac_email: 'money@lagosnights.test',
      bank_name: null,
      account_name: null,
      account_last_four: null,
      verified_at: '2026-10-01T10:00:00+00:00',
    },
    requests: [
      {
        id: 'r-1',
        amount: cad(50_000),
        paid_amount: cad(80_000),
        advance: cad(30_000),
        status: 'paid',
        note: null,
        decision_note: null,
        requested_by: 'Ada Okafor',
        requested_at: '2026-10-11T19:00:00+00:00',
        decided_at: '2026-10-12T19:00:00+00:00',
      },
    ],
    can_request: true,
    can_change_destination: true,
  };
}

/**
 * The statement while money is owed back to myFiesta.
 *
 * A balance below zero has to read as what it is — money owed, and what pays
 * it back — never as a minus sign, and nobody is offered a payout form that
 * the server would refuse.
 */
describe('Payouts', () => {
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

  function render(answer: PayoutStatement) {
    const fixture = TestBed.createComponent(Payouts);
    backend.expectOne(PAYOUTS).flush(answer);
    fixture.detectChanges();

    return fixture;
  }

  it('says what is owed back, how it is coming back, and offers no payout', () => {
    const fixture = render(
      statement(-18_000, {
        outstanding: cad(18_000),
        advanced: cad(30_000),
        advanced_at: '2026-10-12T19:00:00+00:00',
        recovered: cad(12_000),
        repaid: cad(0),
        added: cad(0),
        since: '2026-10-12T19:00:00+00:00',
        summary: 'myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $180.00 outstanding.',
        recovery: 'Your next sales in CAD pay this back automatically, before anything is paid out to you. You can ask to be paid again once your balance is above zero. To pay it back sooner, contact myFiesta.',
      }),
    );

    const page = text(fixture);

    expect(page).toContain('You owe myFiesta');
    expect(page).toContain('$180.00');
    expect(page).not.toContain('-$180.00');
    expect(page).toContain('An advance from myFiesta');
    expect(page).toContain('myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $180.00 outstanding.');
    expect(page).toContain('Recovered from sales');
    expect(page).toContain('Your next sales in CAD pay this back automatically');
    expect(page).toContain('$300.00 of it advanced by myFiesta');
    expect(page).toContain('includes an advance');

    // Nothing to ask for while the balance is below zero.
    expect(page).not.toContain('Ask to be paid');
    expect(fixture.nativeElement.querySelector('form')).toBeNull();
    expect(fixture.nativeElement.querySelector('[role="status"][aria-labelledby="overdraft-title"]')).not.toBeNull();
  });

  it('says so when the advance has been paid back, and offers the payout again', () => {
    const fixture = render(
      statement(5_000, {
        outstanding: cad(0),
        advanced: cad(30_000),
        advanced_at: '2026-10-12T19:00:00+00:00',
        recovered: cad(30_000),
        repaid: cad(0),
        added: cad(0),
        since: '2026-10-12T19:00:00+00:00',
        summary: 'myFiesta advanced $300.00 on 12 Oct 2026; $300.00 recovered from sales since; nothing outstanding.',
        recovery: null,
      }),
    );

    const page = text(fixture);

    expect(page).toContain('Owed to you');
    expect(page).toContain('Your advance is paid back');
    expect(page).toContain('nothing outstanding.');
    expect(page).toContain('Ask to be paid');
  });

  it('shows no overdraft at all when there is none', () => {
    const fixture = render(statement(20_000, null));

    const page = text(fixture);

    expect(page).toContain('Owed to you');
    expect(page).not.toContain('myFiesta advanced');
    expect(fixture.nativeElement.querySelector('[aria-labelledby="overdraft-title"]')).toBeNull();
  });
});

/**
 * Asking to be paid, withdrawing the ask, and changing where the money goes:
 * each is said back first, with the amount or the destination in it, and
 * saying no sends nothing.
 */
describe('Payouts: asking first', () => {
  let backend: HttpTestingController;

  beforeAll(allowDialogs);

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

  afterEach(() => {
    backend.verify();
    forgetDialogs();
  });

  function render(answer: PayoutStatement) {
    const fixture = TestBed.createComponent(Payouts);
    backend.expectOne(PAYOUTS).flush(answer);
    fixture.detectChanges();

    return fixture.componentInstance;
  }

  it('names the amount and where it goes before asking, and asks nothing when the answer is no', async () => {
    const page = render(statement(20_000, null));
    page.setAskAmount('150.00');

    void page.ask();
    await settle();

    expect(asked()?.title).toBe('Ask to be paid $150.00?');
    expect(asked()?.text).toContain('myFiesta sends $150.00 to Interac at money@lagosnights.test');
    expect(asked()?.text).toContain('The other $50.00 stays owed to you.');
    expect(asked()?.buttons).toEqual(['Cancel', 'Ask for $150.00']);

    await answer('Cancel');

    backend.expectNone(`${PAYOUTS}/requests`);
    expect(page.asking()).toBe(false);
  });

  it('sends the request once it is confirmed', async () => {
    const page = render(statement(20_000, null));
    page.setAskAmount('200.00');

    void page.ask();
    await settle();
    await answer('Ask for $200.00');

    const request = backend.expectOne(`${PAYOUTS}/requests`);
    expect(request.request.body).toEqual({ amount: 20_000, note: null });
    request.flush({ message: 'Payout requested.' });
    backend.expectOne(PAYOUTS).flush(statement(20_000, null));
  });

  it('asks before changing where payouts go, naming the new account by its last four', async () => {
    const page = render(statement(20_000, null));

    page.openForm();
    page.update('rail', 'bank_transfer');
    page.update('account_name', 'Lagos Nights Ltd');
    page.update('bank_name', 'First Bank');
    page.update('account_number', '0123 4567 89');

    void page.save();
    await settle();

    expect(asked()?.title).toBe('Change where payouts go?');
    expect(asked()?.text).toContain('Lagos Nights Ltd at First Bank, account ending 6789');
    expect(asked()?.text).not.toContain('0123456789');
    expect(asked()?.text).toContain('loses the verified mark');
    expect(asked()?.buttons).toEqual(['Cancel', 'Change payout details']);

    await answer('Cancel');

    backend.expectNone('http://api.test/api/organizer/payout-details');
    expect(page.formOpen()).toBe(true);
  });
});
