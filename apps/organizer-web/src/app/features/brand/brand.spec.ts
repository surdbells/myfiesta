import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { Brand as BrandData } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../core/api';
import { answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { SessionStore } from '../../core/session';
import { Brand } from './brand';

const URL = 'http://api.test/api/organizer/brand';

function brand(overrides: Partial<BrandData> = {}): BrandData {
  return {
    name: 'Lagos Nights',
    slug: 'lagos-nights',
    description: null,
    logo_url: null,
    is_verified: false,
    verification_pending_name: false,
    socials: { instagram: 'lagosnights', tiktok: null, x: null, facebook: null, website: null },
    ...overrides,
  };
}

/**
 * Where else to find them, on the brand screen: a form of its own, filled
 * from what is kept, that sends only what changed once the owner agrees, and
 * says under a box what the API refused in it.
 */
describe('Brand, where else to find you', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => {
    // jsdom's <dialog> has no showModal(); the question only needs it not to throw.
    const dialog = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    dialog['showModal'] ??= function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
    dialog['close'] ??= function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    };
  });

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
    session.clear();
  });

  function signIn(permissions: string[]): void {
    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [
        { id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role: 'owner', permissions },
      ],
    } as Parameters<SessionStore['start']>[0]);
  }

  async function render(kept: BrandData) {
    const fixture = TestBed.createComponent(Brand);
    fixture.detectChanges();
    backend.expectOne(URL).flush(kept);
    fixture.detectChanges();
    await settle();

    return fixture;
  }

  function box(fixture: { nativeElement: HTMLElement }, network: string): HTMLInputElement {
    return fixture.nativeElement.querySelector<HTMLInputElement>(`#brand-social-${network}`)!;
  }

  async function type(
    fixture: { nativeElement: HTMLElement; detectChanges(): void },
    network: string,
    value: string,
  ) {
    const input = box(fixture, network);
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await settle();
  }

  async function submit(fixture: { nativeElement: HTMLElement; detectChanges(): void }) {
    fixture.nativeElement
      .querySelector<HTMLFormElement>('form[aria-labelledby="brand-socials-heading"]')!
      .dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await settle();
  }

  it('starts from what is kept', async () => {
    signIn(['organization.brand']);
    const fixture = await render(brand());

    expect(box(fixture, 'instagram').value).toBe('lagosnights');
    expect(box(fixture, 'website').value).toBe('');
  });

  it('sends only what changed, once the owner agrees, and shows what was kept', async () => {
    signIn(['organization.brand']);
    const fixture = await render(brand());

    await type(fixture, 'tiktok', '@lagos.nights');
    await type(fixture, 'instagram', '');
    await submit(fixture);

    const question = asked()!;
    expect(question.title).toBe('Save where else to find you?');
    expect(question.text).toContain('Instagram comes off your page.');
    expect(question.text).toContain('Your organizer page links to them straight away');
    backend.expectNone(URL);

    await answer('Save links');

    const patch = backend.expectOne(URL);
    expect(patch.request.method).toBe('PATCH');
    expect(patch.request.body).toEqual({ socials: { instagram: null, tiktok: '@lagos.nights' } });
    patch.flush(
      brand({
        socials: {
          instagram: null,
          tiktok: 'lagos.nights',
          x: null,
          facebook: null,
          website: null,
        },
      }),
    );
    fixture.detectChanges();
    await settle();

    expect(box(fixture, 'tiktok').value).toBe('lagos.nights');
    expect(box(fixture, 'instagram').value).toBe('');
  });

  it('only says a link comes off when that is all the owner is doing', async () => {
    signIn(['organization.brand']);
    const fixture = await render(brand());

    await type(fixture, 'instagram', '');
    await submit(fixture);

    const question = asked()!;
    expect(question.text).toContain('Instagram comes off your page.');
    expect(question.text).toContain('Your organizer page stops linking there.');
    expect(question.text).not.toContain('links to them straight away');

    await answer('Cancel');
    backend.expectNone(URL);
  });

  it('sends nothing when the owner thinks better of it', async () => {
    signIn(['organization.brand']);
    const fixture = await render(brand());

    await type(fixture, 'x', 'lagosnights');
    await submit(fixture);
    await answer('Cancel');

    backend.expectNone(URL);
    expect(box(fixture, 'x').value).toBe('lagosnights');
  });

  it('says under a box what the API refused in it', async () => {
    signIn(['organization.brand']);
    const fixture = await render(brand());

    await type(fixture, 'website', 'http://lagosnights.com');
    await submit(fixture);
    await answer('Save links');

    backend.expectOne(URL).flush(
      {
        message: 'That is not a website address we can link to.',
        errors: { 'socials.website': ['That is not a website address we can link to.'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();
    await settle();

    const field = box(fixture, 'website').closest('ui-field')!;
    expect(field.textContent).toContain('That is not a website address we can link to.');
  });

  it('shows somebody who cannot change them what is there, and nothing to press', async () => {
    signIn(['events.view']);
    const fixture = await render(brand());

    expect(box(fixture, 'instagram').disabled).toBe(true);
    expect(fixture.nativeElement.textContent).not.toContain('Save links');
  });
});
