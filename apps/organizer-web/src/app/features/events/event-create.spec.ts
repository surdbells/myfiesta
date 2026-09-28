import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import { EventCreate } from './event-create';

/**
 * A new event starts where the form says it is.
 *
 * The country is Canada and the province Ontario until somebody changes them,
 * so the clock is Toronto's too — not the browser's, which on a laptop in
 * Lagos put a Toronto night five hours early.
 */
describe('EventCreate', () => {
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'http://api.test' }],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  function render(): EventCreate {
    const fixture = TestBed.createComponent(EventCreate);
    backend.expectOne('http://api.test/api/event-categories').flush({ data: [] });

    return fixture.componentInstance;
  }

  it('starts in the default country’s zone, whatever the browser’s is', () => {
    const page = render();

    expect(page.country()).toBe('CA');
    expect(page.subdivision()).toBe('ON');
    expect(page.timezone()).toBe('America/Toronto');
  });

  it('still follows the country when it is changed', () => {
    const page = render();

    page.onCountryChange('NG');

    expect(page.timezone()).toBe('Africa/Lagos');
  });
});
