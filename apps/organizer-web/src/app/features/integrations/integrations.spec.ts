import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { Integrations } from '../../core/api.types';
import { IntegrationsScreen } from './integrations';

const INTEGRATIONS = 'http://api.test/api/organizer/integrations';

function integrations(events: string[]): Integrations {
  return {
    events: events as Integrations['events'],
    endpoints: [
      {
        id: 'wh-1',
        url: 'https://hooks.example.test/myfiesta',
        events: events as Integrations['events'],
        description: 'Our CRM',
        enabled: true,
        disabled_reason: null,
        consecutive_failures: 0,
        last_delivery: null,
        created_at: '2026-09-20T12:00:00Z',
      },
    ],
    keys: [],
  };
}

/**
 * Every event the server offers is named on the screen.
 *
 * The server added `order.disputed` and the screen had no words for it: the
 * list of addresses threw while drawing one that asked for it, and the form
 * showed a fourth checkbox with nothing beside it.
 */
describe('IntegrationsScreen', () => {
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'http://api.test' }],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  function render(events: string[]): HTMLElement {
    const fixture = TestBed.createComponent(IntegrationsScreen);
    backend.expectOne(INTEGRATIONS).flush(integrations(events));
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('labels a disputed payment, in the list and on the form', () => {
    const page = render(['order.paid', 'order.refunded', 'ticket.checked_in', 'order.disputed']);
    const text = page.textContent ?? '';

    expect(text).toContain('A payment is disputed');

    // One label per checkbox, none of them blank.
    const boxes = Array.from(page.querySelectorAll('fieldset label'));
    expect(boxes).toHaveLength(4);
    for (const box of boxes) {
      expect(box.querySelector('.font-medium')?.textContent?.trim()).not.toBe('');
    }
  });

  it('still draws an address subscribed to an event this screen has no words for yet', () => {
    const page = render(['order.paid', 'order.transferred']);

    expect(page.textContent).toContain('https://hooks.example.test/myfiesta');
    expect(page.textContent).toContain('order.transferred');
  });
});
