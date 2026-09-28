import { isPlatformBrowser } from '@angular/common';
import { DestroyRef, PLATFORM_ID, computed, effect, inject, signal, untracked, type Signal } from '@angular/core';
import { ActivatedRoute, Router, type Params } from '@angular/router';
import type { Sort } from './table';

/**
 * What one filter holds.
 *
 *   text   a search box, or any single free value
 *   one    a single select; '' is "any"
 *   many   a multi-select; [] is "any"
 *   int    a whole number, or null — an amount in minor units, a count
 *   day    a yyyy-mm-dd date, or null
 */
export type FilterKind = 'text' | 'one' | 'many' | 'int' | 'day';

export type FilterValue = string | readonly string[] | number | null;

export interface FilterDef {
  readonly kind: FilterKind;
}

/** One column a reader can show or hide. */
export interface ListColumn {
  readonly id: string;
  readonly label: string;
  /** Always shown: the column that names the row. */
  readonly required?: boolean;
  /** Off until somebody turns it on. */
  readonly hidden?: boolean;
}

export type Density = 'comfortable' | 'compact';

export interface ListConfig<F extends Record<string, FilterDef>> {
  /** Names the list for the columns and density this browser remembers ("orders"). */
  readonly list: string;
  readonly filters: F;
  /** The sort before anybody chooses one; null leaves it to the server. */
  readonly sort?: Sort | null;
  readonly columns?: readonly ListColumn[];
  /**
   * Keep the list's state in the address. On by default: a filtered list
   * whose link opens unfiltered is a list nobody can send to a colleague, and
   * the back button that forgets the filters is the commonest complaint about
   * any admin screen. Off for a list inside a dialog or a second list on the
   * same page, where the address belongs to something else.
   */
  readonly url?: boolean;
}

/** A flat value a saved view keeps; matches SavedViewValue in api-types. */
type Kept = string | number | boolean | null | readonly (string | number | boolean)[];

/** Everything about one filtered, sorted, paged list — held once, for a screen. */
export interface ListState<F extends Record<string, FilterDef>> {
  readonly list: string;
  readonly filters: F;
  readonly columns: readonly ListColumn[];

  /** Every filter's current value. */
  readonly values: Signal<{ [K in keyof F]: FilterValue }>;
  readonly page: Signal<number>;
  readonly sort: Signal<Sort | null>;
  readonly density: Signal<Density>;
  /** The ids of the columns showing, in their order. */
  readonly visible: Signal<readonly string[]>;

  /** How many filters are on (a search counts). */
  readonly active: Signal<number>;
  /**
   * The request: every filter that is on, the page and the sort, ready to
   * hand to an API call. Arrays stay arrays (the server reads `key[]`).
   */
  readonly query: Signal<Record<string, string | number | readonly string[]>>;
  /**
   * The same without the page, for an export and for knowing when the rows
   * shown are a different set (and a selection of them no longer means
   * anything).
   */
  readonly criteria: Signal<Record<string, string | number | readonly string[]>>;

  get<K extends keyof F>(key: K): FilterValue;
  /** Set a filter; the list goes back to its first page. */
  set<K extends keyof F>(key: K, value: FilterValue): void;
  clear<K extends keyof F>(key: K): void;
  clearAll(): void;

  goTo(page: number): void;
  sortBy(sort: Sort | null): void;

  isVisible(column: string): boolean;
  toggleColumn(column: string): void;
  resetColumns(): void;
  setDensity(density: Density): void;

  /** Filters, sort and columns, as a saved view keeps them. */
  snapshot(): Record<string, Kept>;
  /** Put a saved view back. Keys this list does not have are left off. */
  apply(state: Readonly<Record<string, unknown>>): void;
}

const SORT_KEY = 'sort';
const DIR_KEY = 'dir';
const PAGE_KEY = 'page';
const COLUMNS_KEY = 'columns';

/**
 * A list's state, for one screen.
 *
 * Called in a component's field initialiser (it injects the router and the
 * route). The screen reads `query()` to fetch, binds its controls to
 * `get`/`set`, and passes the state to the table's column menu and the saved
 * views; this keeps the address, the page, the sort and the columns in step so
 * that no screen implements any of it again.
 *
 * What is kept where, and why:
 *
 *   - filters, sort and page: the address, so a link or a reload or the back
 *     button brings back exactly the list that was on screen
 *   - columns and density: this browser, per list — they are how somebody
 *     likes to read, not what they are reading, and a link sent to a
 *     colleague should not rearrange their table
 *   - a named combination of all of it: a saved view, on the server
 */
export function createListState<F extends Record<string, FilterDef>>(config: ListConfig<F>): ListState<F> {
  const router = inject(Router);
  const route = inject(ActivatedRoute);
  const browser = isPlatformBrowser(inject(PLATFORM_ID));
  const destroyRef = inject(DestroyRef);
  const useUrl = config.url ?? true;
  const columns = config.columns ?? [];
  const defaultSort = config.sort ?? null;

  const empty = (kind: FilterKind): FilterValue => (kind === 'many' ? [] : kind === 'int' || kind === 'day' ? null : '');

  const blank = () =>
    Object.fromEntries(Object.entries(config.filters).map(([key, def]) => [key, empty(def.kind)])) as {
      [K in keyof F]: FilterValue;
    };

  // --- reading the address -------------------------------------------------

  const read = (params: Params) => {
    const values = blank();

    for (const [key, def] of Object.entries(config.filters) as [keyof F & string, FilterDef][]) {
      values[key] = parse(def.kind, params[key]);
    }

    const page = Math.max(1, Number.parseInt(String(params[PAGE_KEY] ?? '1'), 10) || 1);
    const column = typeof params[SORT_KEY] === 'string' ? params[SORT_KEY] : null;
    const direction = params[DIR_KEY] === 'asc' || params[DIR_KEY] === 'desc' ? params[DIR_KEY] : null;
    const sort: Sort | null = column ? { column, direction: direction ?? 'asc' } : defaultSort;

    return { values, page, sort };
  };

  const initial = useUrl ? read(route.snapshot.queryParams) : { values: blank(), page: 1, sort: defaultSort };

  const values = signal(initial.values);
  const page = signal(initial.page);
  const sort = signal<Sort | null>(initial.sort);

  // --- this browser's preferences -----------------------------------------

  const storageKey = (what: string) => `myfiesta.list.${config.list}.${what}`;
  const defaults = columns.filter((c) => !c.hidden || c.required).map((c) => c.id);

  const stored = <T>(what: string, check: (value: unknown) => value is T): T | null => {
    if (!browser) return null;
    try {
      const raw = localStorage.getItem(storageKey(what));
      if (raw === null) return null;
      const value: unknown = JSON.parse(raw);
      return check(value) ? value : null;
    } catch {
      return null;
    }
  };

  const keep = (what: string, value: unknown) => {
    if (!browser) return;
    try {
      localStorage.setItem(storageKey(what), JSON.stringify(value));
    } catch {
      // Remembered for this visit only.
    }
  };

  const known = (ids: readonly string[]) => {
    const wanted = new Set(ids);
    // Required columns are always there, and the table's own order wins over
    // the order they were ticked in.
    return columns.filter((c) => c.required || wanted.has(c.id)).map((c) => c.id);
  };

  const isStringList = (value: unknown): value is string[] =>
    Array.isArray(value) && value.every((v) => typeof v === 'string');
  const isDensity = (value: unknown): value is Density => value === 'comfortable' || value === 'compact';

  const visible = signal<readonly string[]>(known(stored('columns', isStringList) ?? defaults));
  const density = signal<Density>(stored('density', isDensity) ?? 'comfortable');

  // --- derived ------------------------------------------------------------

  const criteria = computed(() => {
    const out: Record<string, string | number | readonly string[]> = {};

    for (const [key, value] of Object.entries(values())) {
      if (isOn(value)) out[key] = typeof value === 'string' ? value.trim() : value!;
    }

    // The default sort is sent too: the server's own default may differ, and
    // the rows must be in the order the headings say.
    const current = sort();
    if (current) {
      out[SORT_KEY] = current.column;
      out[DIR_KEY] = current.direction;
    }

    return out;
  });

  const query = computed(() => ({ ...criteria(), page: page() }));

  const active = computed(() => Object.values(values()).filter(isOn).length);

  // --- writing the address -------------------------------------------------

  if (useUrl && browser) {
    effect(() => {
      const params: Params = {};

      for (const [key, value] of Object.entries(values())) {
        params[key] = isOn(value) ? (Array.isArray(value) ? [...value] : String(value)) : null;
      }

      const current = sort();
      const custom = current && !sameSort(current, defaultSort);
      params[SORT_KEY] = custom ? current.column : null;
      params[DIR_KEY] = custom ? current.direction : null;
      params[PAGE_KEY] = page() > 1 ? String(page()) : null;

      untracked(() => {
        if (sameParams(route.snapshot.queryParams, params)) return;

        void router.navigate([], {
          relativeTo: route,
          queryParams: params,
          queryParamsHandling: 'merge',
          replaceUrl: true,
        });
      });
    });

    // Back and forward: the address changed under the screen, and the list
    // follows it rather than the other way round.
    const subscription = route.queryParams.subscribe((params) => {
      const next = read(params);

      if (JSON.stringify(next.values) !== JSON.stringify(values())) values.set(next.values);
      if (next.page !== page()) page.set(next.page);
      if (!sameSort(next.sort, sort())) sort.set(next.sort);
    });

    destroyRef.onDestroy(() => subscription.unsubscribe());
  }

  // --- the state -------------------------------------------------------------

  const state: ListState<F> = {
    list: config.list,
    filters: config.filters,
    columns,
    values: values.asReadonly(),
    page: page.asReadonly(),
    sort: sort.asReadonly(),
    density: density.asReadonly(),
    visible: visible.asReadonly(),
    active,
    query,
    criteria,

    get: (key) => values()[key],

    set: (key, value) => {
      const def = config.filters[key];
      values.update((current) => ({ ...current, [key]: normalise(def.kind, value) }));
      page.set(1);
    },

    clear: (key) => state.set(key, empty(config.filters[key].kind)),

    clearAll: () => {
      values.set(blank());
      page.set(1);
    },

    goTo: (next) => page.set(Math.max(1, Math.floor(next))),

    sortBy: (next) => {
      sort.set(next ?? defaultSort);
      page.set(1);
    },

    isVisible: (column) => visible().includes(column),

    toggleColumn: (column) => {
      const definition = columns.find((c) => c.id === column);
      if (!definition || definition.required) return;

      const next = visible().includes(column) ? visible().filter((c) => c !== column) : known([...visible(), column]);
      visible.set(next);
      keep('columns', next);
    },

    resetColumns: () => {
      visible.set(defaults);
      keep('columns', defaults);
    },

    setDensity: (next) => {
      density.set(next);
      keep('density', next);
    },

    snapshot: () => {
      const out: Record<string, Kept> = {};

      for (const [key, value] of Object.entries(values())) {
        if (isOn(value)) out[key] = value as Kept;
      }

      const current = sort();
      if (current) {
        out[SORT_KEY] = current.column;
        out[DIR_KEY] = current.direction;
      }

      if (columns.length > 0) out[COLUMNS_KEY] = [...visible()];

      return out;
    },

    apply: (saved) => {
      const next = blank();

      for (const [key, def] of Object.entries(config.filters) as [keyof F & string, FilterDef][]) {
        if (key in saved) next[key] = parse(def.kind, saved[key]);
      }

      values.set(next);
      page.set(1);

      const column = typeof saved[SORT_KEY] === 'string' ? (saved[SORT_KEY] as string) : null;
      const direction = saved[DIR_KEY] === 'desc' ? 'desc' : 'asc';
      sort.set(column ? { column, direction } : defaultSort);

      if (isStringList(saved[COLUMNS_KEY])) {
        const next = known(saved[COLUMNS_KEY]);
        visible.set(next);
        keep('columns', next);
      }
    },
  };

  return state;
}

// --- helpers -----------------------------------------------------------------

function isOn(value: FilterValue): boolean {
  if (value === null) return false;
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'string') return value.trim() !== '';
  return true;
}

/** A value from the address or a saved view, read as the filter's kind. */
function parse(kind: FilterKind, raw: unknown): FilterValue {
  switch (kind) {
    case 'many': {
      const list = Array.isArray(raw) ? raw : raw === undefined || raw === null || raw === '' ? [] : [raw];
      return [...new Set(list.map(String).filter((v) => v !== ''))];
    }
    case 'int': {
      const number = typeof raw === 'number' ? raw : Number.parseInt(String(raw ?? ''), 10);
      return Number.isFinite(number) ? Math.trunc(number) : null;
    }
    case 'day':
      return typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : null;
    default:
      return typeof raw === 'string' ? raw : typeof raw === 'number' ? String(raw) : '';
  }
}

function normalise(kind: FilterKind, value: FilterValue): FilterValue {
  return parse(kind, value);
}

function sameSort(a: Sort | null, b: Sort | null): boolean {
  return a === b || (!!a && !!b && a.column === b.column && a.direction === b.direction);
}

/** Whether the address already says this, so writing it would be a no-op. */
function sameParams(current: Params, next: Params): boolean {
  for (const [key, value] of Object.entries(next)) {
    const have = current[key];

    if (value === null) {
      if (have !== undefined) return false;
      continue;
    }

    const a = Array.isArray(have) ? have.map(String) : have === undefined ? [] : [String(have)];
    const b = Array.isArray(value) ? value.map(String) : [String(value)];

    if (a.length !== b.length || a.some((v, i) => v !== b[i])) return false;
  }

  return true;
}
