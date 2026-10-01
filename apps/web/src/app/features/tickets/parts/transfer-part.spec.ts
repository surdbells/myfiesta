import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';
import { API_BASE_URL } from '../../../core/api-base';
import { HeldTicket, TicketAccess, TicketAnswer } from '../../../core/api.types';
import { TransferPart } from './transfer-part';

const SEND = 'https://api.myfiesta.test/api/tickets/tok-1/transfer/t-1';

function ticket(transferable: boolean | null): HeldTicket {
  return {
    id: 't-1',
    code: 'MF7Q2KAB',
    type: 'General',
    holder: 'Ada Okafor',
    status: 'valid',
    admits: 1,
    admitted: 0,
    qr: '<svg></svg>',
    return: { listed: false, refusal: null },
    perks: [],
    share_link: null,
    transferable,
  };
}

/**
 * "Send to someone" on the tickets page: only where the server says the
 * ticket can go, never without a confirmation that names the address, and
 * one request at a time on the page.
 */
describe('TransferPart', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];
  let yes: boolean;
  let answers: TicketAnswer[];

  beforeEach(() => {
    asked = [];
    yes = false;
    answers = [];

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), yes) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function mount(transferable: boolean | null = true): ComponentFixture<TransferPart> {
    const fixture = TestBed.createComponent(TransferPart);
    fixture.componentRef.setInput('token', 'tok-1');
    fixture.componentRef.setInput('ticket', ticket(transferable));
    fixture.componentInstance.answered.subscribe((answer) => answers.push(answer));
    fixture.detectChanges();

    return fixture;
  }

  function filled(transferable: boolean | null = true): ComponentFixture<TransferPart> {
    const fixture = mount(transferable);
    const part = fixture.componentInstance;
    part.open.set(true);
    part.name.set(' Bola Ade ');
    part.email.set(' bola@example.com ');
    fixture.detectChanges();

    return fixture;
  }

  function text(fixture: ComponentFixture<TransferPart>): string {
    return ((fixture.nativeElement as HTMLElement).textContent ?? '').replace(/\s+/g, ' ').trim();
  }

  it('draws nothing where the server does not say it can go', () => {
    for (const transferable of [false, null]) {
      const fixture = mount(transferable);

      expect(text(fixture)).toBe('');
      expect((fixture.nativeElement as HTMLElement).classList).toContain('contents');
      expect(fixture.componentInstance.working()).toBeNull();
    }
  });

  it('offers to send it where it can go', () => {
    const fixture = mount(true);

    expect(text(fixture)).toBe('Send to someone');
  });

  it('names the address before sending, and sends nothing on no', async () => {
    const fixture = filled();

    await fixture.componentInstance.send();

    expect(asked[0].title).toBe('Send your General ticket to Bola Ade?');
    expect(asked[0].body).toContain('bola@example.com');
    expect(asked[0].body).toContain('stops getting anybody in');
    expect(asked[0].tone).toBe('danger');
    http.expectNone(SEND);
    expect(fixture.componentInstance.working()).toBeNull();
  });

  it('sends it once it is confirmed, and hands the page what the server said', async () => {
    const fixture = filled();
    yes = true;

    await fixture.componentInstance.send();
    expect(fixture.componentInstance.working()).toBe('t-1');

    const request = http.expectOne(SEND);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ email: 'bola@example.com', name: 'Bola Ade' });

    const access = { tickets: [] } as unknown as TicketAccess;
    request.flush({ message: 'Sent to bola@example.com.', access });

    expect(answers).toEqual([{ message: 'Sent to bola@example.com.', access }]);
    expect(fixture.componentInstance.working()).toBeNull();
    expect(fixture.componentInstance.open()).toBe(false);
  });

  it('says a refusal where the page says everything else, and changes nothing', async () => {
    const fixture = filled();
    yes = true;

    await fixture.componentInstance.send();
    http
      .expectOne(SEND)
      .flush(
        { message: 'The event has started, so tickets can no longer be sent to somebody else.' },
        { status: 422, statusText: 'Unprocessable Content' },
      );

    expect(answers).toEqual([
      { message: 'The event has started, so tickets can no longer be sent to somebody else.', access: null },
    ]);
    expect(fixture.componentInstance.working()).toBeNull();
  });

  it('says an address the server cannot take beside the field, and keeps what was typed', async () => {
    const fixture = filled();
    yes = true;

    await fixture.componentInstance.send();
    http
      .expectOne(SEND)
      .flush(
        { message: 'The email field must be a valid email address.', errors: { email: ['The email field must be a valid email address.'] } },
        { status: 422, statusText: 'Unprocessable Content' },
      );
    fixture.detectChanges();

    expect(answers).toEqual([]);
    expect(fixture.componentInstance.open()).toBe(true);
    expect(fixture.componentInstance.email()).toBe(' bola@example.com ');
    expect(text(fixture)).toContain('The email field must be a valid email address.');
  });

  it('waits while another ticket on the page is being worked on', async () => {
    const fixture = filled();
    yes = true;
    fixture.componentInstance.working.set('t-2');
    fixture.detectChanges();

    await fixture.componentInstance.send();

    expect(asked).toEqual([]);
    http.expectNone(SEND);
  });
});
