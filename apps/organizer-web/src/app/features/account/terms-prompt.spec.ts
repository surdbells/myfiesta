import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL, authInterceptor } from '../../core/api';
import type { TermsStanding } from '../../core/api.types';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';
import { TermsPrompt } from './terms-prompt';

const TERMS = 'http://api.test/api/auth/terms';

const never: TermsStanding = { current: '2026-09-27', accepted: false, accepted_version: null, accepted_at: null };
const older: TermsStanding = { current: '2026-09-27', accepted: false, accepted_version: '2026-01-01', accepted_at: '2026-01-02T10:00:00+00:00' };
const agreed: TermsStanding = { current: '2026-09-27', accepted: true, accepted_version: '2026-09-27', accepted_at: '2026-09-27T10:00:00+00:00' };

/**
 * "Please accept our terms", for the accounts nobody asked.
 *
 * Organizers from before the box existed, the ones brought over from the
 * previous platform, and those who never buy were never asked. The console
 * asks them once a visit, with the box unticked and the three pages a click
 * away, and never stands between them and the screen they came for.
 */
describe('TermsPrompt', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  const box = (fixture: { nativeElement: HTMLElement }) =>
    fixture.nativeElement.querySelector<HTMLInputElement>('input[type="checkbox"]');

  const button = (fixture: { nativeElement: HTMLElement }, label: string) =>
    Array.from(fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')).find(
      (b) => b.textContent?.trim() === label,
    )!;

  function render(answer: TermsStanding) {
    const fixture = TestBed.createComponent(TermsPrompt);
    fixture.detectChanges();

    const request = backend.expectOne(TERMS);
    expect(request.request.method).toBe('GET');
    request.flush(answer);
    fixture.detectChanges();

    return fixture;
  }

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        { provide: SITE_URL, useValue: 'https://myfiesta.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);

    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [],
    });
  });

  afterEach(() => {
    backend.verify();
    session.clear();
    localStorage.clear();
    sessionStorage.clear();
  });

  it('asks an account that never accepted, with the box unticked and the three pages linked', () => {
    const fixture = render(never);
    const element = fixture.nativeElement as HTMLElement;

    expect(element.textContent).toContain('Please accept our terms');
    expect(element.textContent).toContain('everything here keeps working');
    expect(box(fixture)?.checked).toBe(false);

    const links = Array.from(element.querySelectorAll('a')).map((a) => a.getAttribute('href'));
    expect(links).toEqual(['https://myfiesta.test/terms', 'https://myfiesta.test/privacy', 'https://myfiesta.test/refunds']);

    // Nothing to send until they tick it.
    expect(button(fixture, 'Accept').disabled).toBe(true);
  });

  it('says the words changed for an account that accepted older ones', () => {
    const fixture = render(older);

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Our terms have changed');
  });

  it('says nothing to an account that already accepted these words', () => {
    const fixture = render(agreed);

    expect((fixture.nativeElement as HTMLElement).querySelector('.terms-prompt')).toBeNull();
  });

  it('records the acceptance and goes away', () => {
    const fixture = render(never);

    box(fixture)!.click();
    fixture.detectChanges();
    button(fixture, 'Accept').click();

    const request = backend.expectOne(TERMS);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ accept_terms: true });
    request.flush(agreed);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.terms-prompt')).toBeNull();
  });

  it('keeps the box and says why when it could not be saved', () => {
    const fixture = render(never);

    box(fixture)!.click();
    fixture.detectChanges();
    button(fixture, 'Accept').click();

    backend
      .expectOne(TERMS)
      .flush({ message: 'Tick the box to accept the terms, the privacy policy and the refund policy.' }, { status: 422, statusText: 'Unprocessable' });
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.terms-prompt')).not.toBeNull();
    expect(element.querySelector('.terms-prompt__error')?.textContent).toContain('Tick the box');
  });

  it('can be put off for this visit, and is not asked again until the next', () => {
    const fixture = render(never);

    button(fixture, 'Not now').click();
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('.terms-prompt')).toBeNull();

    // A reload in the same tab: asked of the server, and kept to itself.
    const again = render(never);
    expect((again.nativeElement as HTMLElement).querySelector('.terms-prompt')).toBeNull();

    // A new visit asks again.
    sessionStorage.clear();
    const next = render(never);
    expect((next.nativeElement as HTMLElement).querySelector('.terms-prompt')).not.toBeNull();
  });

  it('is not shown to myFiesta staff acting as an organization', () => {
    session.clear();
    session.startImpersonation({
      token: 'staff-token',
      user: { name: 'Sade Support', email: 'sade@myfiesta.test' },
      abilities: ['organizer'],
      organizations: [],
      impersonation: {
        id: 'imp-1',
        organization: { id: 'org-1', name: 'Lagos Nights' },
        staff: { name: 'Sade Support' },
        reason: 'Helping with a payout',
        started_at: new Date().toISOString(),
        expires_at: new Date(Date.now() + 3_600_000).toISOString(),
        withheld: [],
      },
    });

    const fixture = TestBed.createComponent(TermsPrompt);
    fixture.detectChanges();

    // Not asked, and not shown: agreeing is for the person whose account it is.
    backend.expectNone(TERMS);
    expect((fixture.nativeElement as HTMLElement).querySelector('.terms-prompt')).toBeNull();
  });
});
