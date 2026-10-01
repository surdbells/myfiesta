import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api-base';
import { EventDetail } from '../../../core/api.types';
import { CheckoutStore } from '../../../core/checkout-store';
import { ShareBannerPart } from './share-banner-part';

/**
 * The welcome for somebody who arrived through a friend's link.
 *
 * Only for a link the server says takes money off, only to a night with an
 * offer, and never adding a box to the column when it has nothing to say.
 */
describe('ShareBannerPart', () => {
  const API = 'https://api.myfiesta.test';
  let http: HttpTestingController;

  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: API }],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function render(event: Partial<EventDetail>) {
    const fixture = TestBed.createComponent(ShareBannerPart);
    fixture.componentRef.setInput('event', { slug: 'afro', ...event } as unknown as EventDetail);
    fixture.detectChanges();

    return fixture;
  }

  function asked(ref: string) {
    return http.expectOne(
      (request) => request.url === `${API}/api/events/afro/friend-discount` && request.params.get('ref') === ref,
    );
  }

  it('draws nothing, adds no box and asks nothing, for a night with no offer', () => {
    TestBed.inject(CheckoutStore).setRef('afro', 'fabcdefgh23');

    const host = render({ share_offer: null }).nativeElement as HTMLElement;

    expect(host.textContent?.trim()).toBe('');
    expect(host.children.length).toBe(0);
    expect(host.classList).toContain('contents');
  });

  it('says what a friend’s link saves, once the server says it is one', () => {
    TestBed.inject(CheckoutStore).setRef('afro', 'fabcdefgh23');

    const fixture = render({ share_offer: { discount_bps: 2000 } });
    expect((fixture.nativeElement as HTMLElement).textContent?.trim()).toBe('');

    // The server's figure, which is what checkout will take off.
    asked('fabcdefgh23').flush({ data: { discount_bps: 1500 } });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('[role="status"]')?.textContent).toContain(
      'A friend sent you this, so you save 15%',
    );
  });

  it('says nothing for a link that only looks like a friend’s', () => {
    // A promoter's slug of the same shape, or a link whose holder was refunded.
    TestBed.inject(CheckoutStore).setRef('afro', 'fridaynight');

    const fixture = render({ share_offer: { discount_bps: 1500 } });
    asked('fridaynight').flush({ message: 'That link takes nothing off this night.' }, { status: 404, statusText: 'Not Found' });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent?.trim()).toBe('');
  });

  it('does not ask about a promoter’s link of another shape', () => {
    TestBed.inject(CheckoutStore).setRef('afro', 'ade-instagram');

    expect((render({ share_offer: { discount_bps: 1500 } }).nativeElement as HTMLElement).textContent?.trim()).toBe('');
    http.expectNone(`${API}/api/events/afro/friend-discount`);
  });

  it('remembers the friend’s link on a visit without it', () => {
    TestBed.inject(CheckoutStore).setRef('afro', 'fabcdefgh23');

    // Another night in between, then back with no ?ref= on the address.
    TestBed.inject(CheckoutStore).loadFor('another-night');

    const fixture = render({ share_offer: { discount_bps: 1000 } });
    asked('fabcdefgh23').flush({ data: { discount_bps: 1000 } });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('you save 10%');
  });
});
