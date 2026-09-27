import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL, authInterceptor } from '../../core/api';
import type { OrganizationStanding, Session } from '../../core/api.types';
import { SessionStore } from '../../core/session';
import { SuspensionBanner } from './suspension-banner';

const STANDING = 'http://api.test/api/organizer/standing';

function sessionIn(...ids: string[]): Session {
  return {
    token: 'test-token',
    user: { name: 'Ada Okafor', email: 'ada@example.test' },
    abilities: ['attendee', 'organizer'],
    organizations: ids.map((id) => ({
      id,
      name: id === 'org-1' ? 'Lagos Nights' : 'Toronto Sound',
      slug: id,
      role: 'owner' as const,
      permissions: ['events.view' as const],
    })),
  };
}

function suspended(id = 'org-1', reason: string | null = null, support: string | null = 'help@myfiesta.test'): OrganizationStanding {
  return {
    organization: { id, name: id === 'org-1' ? 'Lagos Nights' : 'Toronto Sound' },
    suspended: true,
    suspension: { since: '2026-09-20T10:00:00+00:00', reason, support_email: support },
  };
}

function active(id = 'org-1'): OrganizationStanding {
  return { organization: { id, name: 'Lagos Nights' }, suspended: false, suspension: null };
}

/**
 * The banner a suspended organization sees on every screen.
 *
 * It has to say, before anything else, that tickets already sold still get
 * people in; show the reason only when myFiesta shared it; always say how to
 * reach support; and never describe one organization while another is on
 * screen.
 */
describe('SuspensionBanner', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  const text = (fixture: { nativeElement: HTMLElement }) =>
    (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  function render(answer: OrganizationStanding) {
    const fixture = TestBed.createComponent(SuspensionBanner);
    fixture.detectChanges();

    const request = backend.expectOne(STANDING);
    expect(request.request.method).toBe('GET');
    request.flush(answer);
    fixture.detectChanges();

    return fixture;
  }

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);
  });

  afterEach(() => {
    backend.verify();
    session.clear();
    localStorage.clear();
  });

  it('draws nothing for an organization in good standing', () => {
    session.start(sessionIn('org-1'));
    const banner = render(active());

    expect((banner.nativeElement as HTMLElement).querySelector('.suspension-banner')).toBeNull();
  });

  it('says it is suspended, what still works and how to reach support', () => {
    session.start(sessionIn('org-1'));
    const banner = render(suspended());
    const said = text(banner);

    expect(said).toContain('Lagos Nights is suspended');
    expect(said).toContain('your door still lets them in');
    expect(said).toContain('refunds can still be made');
    expect(said).toContain('help@myfiesta.test');
    expect((banner.nativeElement as HTMLElement).querySelector('a')?.getAttribute('href')).toBe('mailto:help@myfiesta.test');

    // Not shared, so not shown.
    expect((banner.nativeElement as HTMLElement).querySelector('.suspension-banner__reason')).toBeNull();
  });

  it('shows the reason only when myFiesta shared it', () => {
    session.start(sessionIn('org-1'));
    const banner = render(suspended('org-1', 'Your events used artwork you do not own.'));

    expect(text(banner)).toContain('Why: Your events used artwork you do not own.');
  });

  it('still says how to ask when the deployment has no support address', () => {
    session.start(sessionIn('org-1'));
    const banner = render(suspended('org-1', null, null));

    expect(text(banner)).toContain('reply to the email we sent your owners');
    expect((banner.nativeElement as HTMLElement).querySelector('a')).toBeNull();
  });

  it('asks again for the organization switched to, and never shows the last one’s', () => {
    session.start(sessionIn('org-1', 'org-2'));
    const banner = render(suspended('org-1'));

    expect(text(banner)).toContain('Lagos Nights is suspended');

    session.select('org-2');
    banner.detectChanges();

    // Until the answer arrives, the other organization's banner is not this one's.
    expect(text(banner)).not.toContain('is suspended');

    backend.expectOne(STANDING).flush(active('org-2'));
    banner.detectChanges();

    expect(text(banner)).not.toContain('is suspended');
  });

  it('claims nothing about an organization it could not check', () => {
    session.start(sessionIn('org-1', 'org-2'));
    const banner = render(suspended('org-1'));

    session.select('org-2');
    banner.detectChanges();
    backend.expectOne(STANDING).flush(suspended('org-2'));
    banner.detectChanges();
    expect(text(banner)).toContain('Toronto Sound is suspended');

    session.select('org-1');
    banner.detectChanges();
    backend.expectOne(STANDING).flush({ message: 'Server error' }, { status: 500, statusText: 'Server Error' });
    banner.detectChanges();

    // The answer on hand is about Toronto, and Lagos could not be read, so
    // neither is claimed while Lagos is on screen.
    expect(text(banner)).not.toContain('is suspended');
  });
});
