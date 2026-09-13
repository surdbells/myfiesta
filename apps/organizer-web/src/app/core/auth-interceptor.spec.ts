import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL, authInterceptor } from './api';
import { SessionStore } from './session';
import { DoorPassStore } from './door-pass';

/**
 * What every console request carries.
 *
 * The organization header matters more than it looks. Without it the server
 * falls back to somebody's first organization, and before it was sent, switching
 * organization in the sidebar changed the name at the top and nothing else —
 * the dashboard, orders and payouts all kept showing the first one.
 */
describe('authInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;
  let current: { id: string } | null;
  let token: string | null;

  beforeEach(() => {
    current = { id: 'org-2' };
    token = 'secret-token';

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        {
          provide: SessionStore,
          useValue: {
            get token() {
              return token;
            },
            current: () => current,
            signedIn: () => token !== null,
            clear: () => undefined,
          },
        },
      ],
    });

    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  it('says which organization is selected', () => {
    http.get('http://api.test/api/organizer/orders').subscribe();

    const request = backend.expectOne('http://api.test/api/organizer/orders').request;
    expect(request.headers.get('Authorization')).toBe('Bearer secret-token');
    expect(request.headers.get('X-Organization')).toBe('org-2');
  });

  it('follows the selection when it changes', () => {
    current = { id: 'org-3' };
    http.get('http://api.test/api/organizer/payouts').subscribe();

    expect(backend.expectOne('http://api.test/api/organizer/payouts').request.headers.get('X-Organization')).toBe('org-3');
  });

  it('sends neither the token nor the organization to any other host', () => {
    http.get('https://maps.example.com/tiles').subscribe();

    const request = backend.expectOne('https://maps.example.com/tiles').request;
    expect(request.headers.has('Authorization')).toBe(false);
    expect(request.headers.has('X-Organization')).toBe(false);

    // A host that merely starts with the API's name is another host.
    http.get('http://api.test.attacker.example/steal').subscribe();
    expect(backend.expectOne('http://api.test.attacker.example/steal').request.headers.has('Authorization')).toBe(false);
  });

  it('sends no organization before anybody is signed in', () => {
    token = null;
    current = null;
    http.post('http://api.test/api/auth/login', {}).subscribe();

    const request = backend.expectOne('http://api.test/api/auth/login').request;
    expect(request.headers.has('X-Organization')).toBe(false);
    expect(request.headers.has('Authorization')).toBe(false);
  });

  describe('with a door pass on this phone', () => {
    let cleared: boolean;

    beforeEach(() => {
      cleared = false;
      localStorage.clear();
      TestBed.inject(SessionStore).clear = () => {
        cleared = true;
      };
      TestBed.inject(DoorPassStore).start({
        token: 'door-token',
        label: 'Front gate',
        expires_at: new Date(Date.now() + 3_600_000).toISOString(),
        event: { id: 'evt-1', title: 'Afro Fest', starts_at: '', ends_at: null, timezone: 'UTC', venue: null, city: null, min_age: null, id_required: false },
      });
    });

    afterEach(() => localStorage.clear());

    it('sends the pass, and no organization, to its own door', () => {
      for (const path of ['scan', 'door-list', 'scans/sync']) {
        http.get(`http://api.test/api/events/evt-1/${path}`).subscribe();
        const request = backend.expectOne(`http://api.test/api/events/evt-1/${path}`).request;
        expect(request.headers.get('Authorization')).toBe('Bearer door-token');
        expect(request.headers.has('X-Organization')).toBe(false);
      }
    });

    it('keeps the pass away from everything else, including another event', () => {
      http.get('http://api.test/api/events/evt-2/scan').subscribe();
      expect(backend.expectOne('http://api.test/api/events/evt-2/scan').request.headers.get('Authorization')).toBe('Bearer secret-token');

      http.get('http://api.test/api/organizer/events/evt-1/guests').subscribe();
      expect(backend.expectOne('http://api.test/api/organizer/events/evt-1/guests').request.headers.get('Authorization')).toBe('Bearer secret-token');
    });

    it('ends the pass when it is refused, and leaves the organizer signed in', () => {
      http.post('http://api.test/api/events/evt-1/scan', {}).subscribe({ error: () => undefined });
      backend.expectOne('http://api.test/api/events/evt-1/scan').flush({}, { status: 401, statusText: 'Unauthorized' });

      expect(TestBed.inject(DoorPassStore).pass()).toBeNull();
      expect(TestBed.inject(DoorPassStore).ended()).toContain('stopped working');
      expect(cleared).toBe(false);
    });
  });
});
