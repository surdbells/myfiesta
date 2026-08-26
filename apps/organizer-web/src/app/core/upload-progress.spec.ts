import { HttpEventType } from '@angular/common/http';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Api, API_BASE_URL } from './api';
import { EventImage, UploadProgress } from './api.types';

/**
 * What an upload reports while it is happening.
 *
 * Tested here rather than by uploading something, because the interesting
 * cases are decided by timing that a local server never produces: on
 * localhost a three megabyte flyer finishes before the browser emits a single
 * measurable progress event, so driving the real screen only ever exercises
 * the indeterminate branch.
 */
describe('uploadImage progress', () => {
  let api: Api;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    api = TestBed.inject(Api);
    http = TestBed.inject(HttpTestingController);
  });

  function upload(): { seen: (UploadProgress | EventImage)[]; request: ReturnType<HttpTestingController['expectOne']> } {
    const file = new File(['x'.repeat(1024)], 'flyer.jpg', { type: 'image/jpeg' });
    const seen: (UploadProgress | EventImage)[] = [];

    api.uploadImage('event-1', file, 'banner').subscribe((event) => seen.push(event));

    return {
      seen,
      request: http.expectOne('http://api.test/api/organizer/events/event-1/images'),
    };
  }

  it('reports a percentage while the total is known', () => {
    const { seen, request } = upload();

    // A Sent event arrives first, always, and maps to indeterminate
    // progress — so the value being checked is the latest one, not the first.
    request.event({ type: HttpEventType.UploadProgress, loaded: 2_500_000, total: 10_000_000 });

    expect(seen.at(-1)).toEqual({ uploading: true, percent: 25 });
  });

  it('rounds rather than showing a fraction of a percent', () => {
    const { seen, request } = upload();

    request.event({ type: HttpEventType.UploadProgress, loaded: 1, total: 3 });

    expect((seen.at(-1) as UploadProgress).percent).toBe(33);
  });

  it('reports no percentage when the total is unknown', () => {
    const { seen, request } = upload();

    // Some proxies strip the length. A bar that reports a percentage nobody
    // could compute is a bar that jumps; the caller shows an indeterminate
    // one from this null instead.
    request.event({ type: HttpEventType.UploadProgress, loaded: 2_000, total: undefined });

    expect((seen.at(-1) as UploadProgress).percent).toBeNull();
  });

  it('ends with the image itself, not another progress value', () => {
    const { seen, request } = upload();

    request.event({ type: HttpEventType.UploadProgress, loaded: 5, total: 10 });
    request.flush({ id: 'img-1', kind: 'banner', url: '/a.jpg' });

    expect((seen.at(-2) as UploadProgress).uploading).toBe(true);

    // The caller narrows on `uploading` to know it is finished, so the final
    // value must not carry it.
    expect((seen.at(-1) as UploadProgress).uploading).toBeUndefined();
    expect((seen.at(-1) as EventImage).id).toBe('img-1');
  });

  it('treats the sent event as progress rather than as a result', () => {
    const { seen, request } = upload();

    // HttpEventType.Sent arrives first and carries no numbers. Passing it
    // through as a result would end the upload the instant it began.
    request.event({ type: HttpEventType.Sent });

    expect((seen[0] as UploadProgress).uploading).toBe(true);
    expect((seen[0] as UploadProgress).percent).toBeNull();
  });

  afterEach(() => http.verify());
});
