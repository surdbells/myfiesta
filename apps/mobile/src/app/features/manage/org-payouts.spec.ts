import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import type { PayoutOverdraft, PayoutStatement } from '@myfiesta/api-types';
import { describe, expect, it } from 'vitest';
import { Organizer } from '../../core/organizer';
import { OrgPayouts } from './org-payouts';

const cad = (amount: number) => ({ amount, currency: 'CAD' as const });

function statement(balance: number, overdraft: PayoutOverdraft | null): PayoutStatement {
  return {
    currency: 'CAD',
    balance: cad(balance),
    settled: cad(80_000),
    overdraft,
    events: [],
    settlements: [],
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
 * The phone's payouts screen while money is owed back to myFiesta.
 *
 * The same as the console: what is owed reads as owed, the server's sentence
 * says where it stands and what pays it back, and asking to be paid is not
 * offered until the balance is above zero.
 */
describe('the organization’s payouts', () => {
  async function screen(answer: PayoutStatement) {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), { provide: Organizer, useValue: { payouts: async () => answer } }],
    });

    const fixture = TestBed.createComponent(OrgPayouts);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ') as string;
  }

  it('says what is owed back and what pays it back, and offers no payout', async () => {
    const page = await screen(
      statement(-18_000, {
        outstanding: cad(18_000),
        advanced: cad(30_000),
        advanced_at: '2026-10-12T19:00:00+00:00',
        recovered: cad(12_000),
        repaid: cad(0),
        added: cad(0),
        since: '2026-10-12T19:00:00+00:00',
        summary: 'myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $180.00 outstanding.',
        recovery: 'Your next sales in CAD pay this back automatically, before anything is paid out to you.',
      }),
    );

    expect(page).toContain('You owe myFiesta');
    expect(page).not.toContain('-$180.00');
    expect(page).toContain('An advance from myFiesta');
    expect(page).toContain('myFiesta advanced $300.00 on 12 Oct 2026; $120.00 recovered from sales since; $180.00 outstanding.');
    expect(page).toContain('Your next sales in CAD pay this back automatically');
    expect(page).toContain('$300.00 advanced by myFiesta');
    expect(page).not.toContain('Ask to be paid');
  });

  it('offers the payout again once the advance is paid back', async () => {
    const page = await screen(
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

    expect(page).toContain('Owed to you');
    expect(page).toContain('Your advance is paid back');
    expect(page).toContain('Ask to be paid');
  });
});
