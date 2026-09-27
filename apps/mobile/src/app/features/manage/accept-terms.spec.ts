import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import type { TermsStanding } from '@myfiesta/api-types';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { Discover } from '../../core/discovery';
import { SessionStore } from '../../core/session';
import { MfAcceptTerms } from './accept-terms';

vi.mock('@capacitor/browser', () => ({ Browser: { open: async () => undefined } }));

const never: TermsStanding = { current: '2026-09-27', accepted: false, accepted_version: null, accepted_at: null };
const older: TermsStanding = { current: '2026-09-27', accepted: false, accepted_version: '2026-01-01', accepted_at: '2026-01-02T10:00:00+00:00' };
const agreed: TermsStanding = { current: '2026-09-27', accepted: true, accepted_version: '2026-09-27', accepted_at: '2026-09-27T10:00:00+00:00' };

/**
 * "Please accept our terms", at the top of Manage.
 *
 * The console's prompt, on the phone: for organizers nobody asked, with the
 * box unticked and the three pages a tap away, asked once a run of the app
 * and never in the way of what they opened Manage to do.
 */
describe('the terms card', () => {
  let terms: ReturnType<typeof vi.fn>;
  let acceptTerms: ReturnType<typeof vi.fn>;
  let who: ReturnType<typeof signal<{ email: string } | null>>;

  beforeEach(() => {
    terms = vi.fn(async () => never);
    acceptTerms = vi.fn(async () => agreed);
    who = signal<{ email: string } | null>({ email: 'ada@example.test' });

    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: { terms, acceptTerms } },
        { provide: Discover, useValue: { siteBase: () => 'https://myfiesta.test' } },
        { provide: SessionStore, useValue: { canSeeSales: () => true, session: who } },
      ],
    });
  });

  async function render() {
    const fixture = TestBed.createComponent(MfAcceptTerms);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    return fixture;
  }

  const button = (fixture: { nativeElement: HTMLElement }, label: string) =>
    Array.from(fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')).find((b) => b.textContent?.trim() === label)!;

  async function tickAndAccept(fixture: Awaited<ReturnType<typeof render>>) {
    fixture.nativeElement.querySelector('input[type="checkbox"]').click();
    fixture.detectChanges();
    button(fixture, 'Accept').click();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('asks an organizer who never accepted, with the box unticked and the pages linked', async () => {
    const card = await render();
    const element = card.nativeElement as HTMLElement;

    expect(element.textContent).toContain('Please accept our terms');
    expect(element.querySelector<HTMLInputElement>('input[type="checkbox"]')?.checked).toBe(false);
    expect(Array.from(element.querySelectorAll('a')).map((a) => a.getAttribute('href'))).toEqual([
      'https://myfiesta.test/terms',
      'https://myfiesta.test/privacy',
      'https://myfiesta.test/refunds',
    ]);
    expect(acceptTerms).not.toHaveBeenCalled();
  });

  it('says the words changed to somebody who accepted older ones', async () => {
    terms.mockResolvedValueOnce(older);

    expect((await render()).nativeElement.textContent).toContain('Our terms have changed');
  });

  it('says nothing to somebody who already accepted these words', async () => {
    terms.mockResolvedValueOnce(agreed);

    expect((await render()).nativeElement.querySelector('.terms')).toBeNull();
  });

  it('records the acceptance and goes', async () => {
    const card = await render();

    await tickAndAccept(card);

    expect(acceptTerms).toHaveBeenCalledTimes(1);
    expect(card.nativeElement.querySelector('.terms')).toBeNull();
  });

  it('stays, and says why, when it could not be saved', async () => {
    acceptTerms.mockRejectedValueOnce(new ApiError('Tick the box to accept the terms, the privacy policy and the refund policy.', 422));
    const card = await render();

    await tickAndAccept(card);

    expect(card.nativeElement.querySelector('.terms')).not.toBeNull();
    expect(card.nativeElement.querySelector('.error')?.textContent).toContain('Tick the box');
  });

  it('asks once a run of the app, and "Not now" holds until the next', async () => {
    const card = await render();

    button(card, 'Not now').click();
    card.detectChanges();
    expect(card.nativeElement.querySelector('.terms')).toBeNull();

    // Back to Manage: the card is made again, and neither asks nor shows.
    const again = await render();
    expect(terms).toHaveBeenCalledTimes(1);
    expect(again.nativeElement.querySelector('.terms')).toBeNull();

    // Somebody else signed in on this phone is asked for themselves.
    who.set({ email: 'bola@example.test' });
    const next = await render();
    expect(terms).toHaveBeenCalledTimes(2);
    expect(next.nativeElement.querySelector('.terms')).not.toBeNull();
  });
});
