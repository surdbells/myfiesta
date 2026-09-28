import { computed, effect, signal, untracked, type Signal, type WritableSignal } from '@angular/core';
import type { FilterDef, ListState } from '@myfiesta/ui';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Observable, catchError, debounceTime, distinctUntilChanged, map, of, switchMap } from 'rxjs';

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

/**
 * A search box bound to a list's text filter.
 *
 * The box shows what is typed at once; the list follows once typing stops, so
 * "a", "ad", "ada" is one question rather than three. It follows the list
 * back too — a chip removed, a saved view applied, the back button — so the
 * box never says one thing while the list is filtered by another. Called in a
 * field initialiser.
 */
export function searchBox<F extends Record<string, FilterDef>>(list: ListState<F>, key: keyof F & string, wait = 300): WritableSignal<string> {
  const text = signal(String(list.get(key) ?? ''));

  toObservable(text)
    .pipe(debounceTime(wait), distinctUntilChanged(), takeUntilDestroyed())
    .subscribe((value) => {
      if (value !== (list.get(key) ?? '')) list.set(key, value);
    });

  // Only this filter's value: another filter changing mid-word must not put
  // back what the list had before the last keystroke.
  const current = computed(() => String(list.values()[key] ?? ''));

  effect(() => {
    const value = current();
    untracked(() => {
      if (value !== text()) text.set(value);
    });
  });

  return text;
}
