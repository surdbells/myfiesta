import type { Sort } from './table';

/** What each sortable column of a list held in memory reads from a row. */
export type SortKeys<T> = Readonly<Record<string, (row: T) => string | number | null | undefined>>;

/**
 * A list the page already holds in full, in the order its headings say.
 *
 * For the short statements the server sends whole — an organization's events
 * on the payouts screen, the payments made to it — where asking the server to
 * sort would be a round trip for thirty rows. The same rules as the server's
 * sort (App\Support\Listing): text compared as people read it, ignoring case
 * and accents; empty values last whichever way it runs; and rows that tie
 * keep the order they arrived in.
 */
export function sortLocally<T>(rows: readonly T[], sort: Sort | null, keys: SortKeys<T>): T[] {
  const read = sort ? keys[sort.column] : undefined;
  if (!sort || !read) return [...rows];

  const way = sort.direction === 'asc' ? 1 : -1;
  const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });

  return rows
    .map((row, index) => ({ row, index, value: read(row) }))
    .sort((a, b) => {
      const empty = (v: unknown) => v === null || v === undefined || v === '';
      if (empty(a.value) || empty(b.value)) {
        if (empty(a.value) && empty(b.value)) return a.index - b.index;
        return empty(a.value) ? 1 : -1;
      }

      const order =
        typeof a.value === 'number' && typeof b.value === 'number'
          ? a.value - b.value
          : collator.compare(String(a.value), String(b.value));

      return order === 0 ? a.index - b.index : order * way;
    })
    .map(({ row }) => row);
}
