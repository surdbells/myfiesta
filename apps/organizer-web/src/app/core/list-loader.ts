import { computed, signal, type Signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Observable, catchError, distinctUntilChanged, map, of, switchMap } from 'rxjs';

/** What a list screen shows while its rows are fetched, and after. */
export interface ListLoader<T> {
  /** The last answer, kept while the next one loads so the table does not blank. */
  readonly result: Signal<T | null>;
  readonly loading: Signal<boolean>;
  /** Could not reach the server, or it failed: worth trying again. */
  readonly failed: Signal<boolean>;
  /** The server said this person may not see the list. Not worth retrying. */
  readonly refused: Signal<boolean>;
  /** Ask the same question again. */
  retry(): void;
}

/**
 * The rows for a list, fetched whenever its question changes.
 *
 * Every list screen needs the same four things and each used to write them
 * again: a newer question cancels the answer still on its way to an older one
 * (or a slow page 1 lands on top of page 2); a failure is caught per question,
 * so the next filter or a retry still runs instead of the stream having died
 * with the first error; a 403 is told apart from an outage, because "you
 * cannot see this" with a Try again button has somebody retrying something
 * that will never work; and the previous rows stay up while the next arrive.
 *
 * Called in a field initialiser: it subscribes for the life of the screen.
 */
export function loadList<T>(question: Signal<unknown>, fetch: () => Observable<T>): ListLoader<T> {
  const result = signal<T | null>(null);
  const loading = signal(true);
  const failed = signal(false);
  const refused = signal(false);
  const attempt = signal(0);

  const asked = computed(() => JSON.stringify([question(), attempt()]));

  toObservable(asked)
    .pipe(
      distinctUntilChanged(),
      switchMap(() => {
        loading.set(true);
        failed.set(false);

        return fetch().pipe(
          map((value) => ({ value, status: 200 })),
          catchError((error: unknown) => of({ value: null, status: Number((error as { status?: number })?.status ?? 0) })),
        );
      }),
      takeUntilDestroyed(),
    )
    .subscribe(({ value, status }) => {
      loading.set(false);

      if (value === null) {
        if (status === 403) refused.set(true);
        else failed.set(true);
        return;
      }

      refused.set(false);
      result.set(value);
    });

  return {
    result: result.asReadonly(),
    loading: loading.asReadonly(),
    failed: failed.asReadonly(),
    refused: refused.asReadonly(),
    retry: () => attempt.update((n) => n + 1),
  };
}
