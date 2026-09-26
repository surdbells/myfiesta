/**
 * IndexedDB in memory, for the specs of both apps' doors. Never imported by
 * either app, only by their specs, by path.
 *
 * jsdom has no IndexedDB, so the door's queue used to be stood in for by
 * mocking the store's own methods. That shows what the store was asked to do,
 * never what a phone still holds once the app has been killed and opened
 * again — and what survives that is the whole point of keeping the queue in
 * IndexedDB. The rows here live in the database, not in the store reading
 * them, so a spec can drop a scan, throw the store away, open a new one on the
 * same database and look.
 *
 * Only as much of IndexedDB as `DoorOfflineStore` uses: object stores with a
 * key path and an index, get, put, delete by key or by range, getAll through
 * an index, and transactions that complete once their requests have answered.
 * Rows are copied in and out, as a real database copies them, so a scan held
 * in memory cannot quietly change what is saved.
 */

type Row = Record<string, unknown>;

interface Table {
  keyPath: string;
  indexes: Map<string, string>;
  rows: Map<IDBValidKey, Row>;
}

interface Database {
  version: number;
  tables: Map<string, Table>;
}

/** A key range, as bound() makes one: every key from lower to upper, both included. */
class MemoryRange {
  constructor(
    private readonly lower: string,
    private readonly upper: string,
  ) {}

  includes(key: IDBValidKey): boolean {
    return typeof key === 'string' && key >= this.lower && key <= this.upper;
  }
}

/** A request whose answer arrives on a later microtask, after its handlers are attached. */
class MemoryRequest<T> {
  result!: T;
  error: DOMException | null = null;
  onsuccess: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onupgradeneeded: ((event: { oldVersion: number; newVersion: number }) => void) | null = null;

  static answering<T>(result: T): MemoryRequest<T> {
    const request = new MemoryRequest<T>();

    request.result = result;
    queueMicrotask(() => request.onsuccess?.());

    return request;
  }
}

class MemoryStore {
  constructor(private readonly table: Table) {}

  get(key: IDBValidKey): MemoryRequest<Row | undefined> {
    return MemoryRequest.answering(copy(this.table.rows.get(key)));
  }

  put(row: Row): MemoryRequest<IDBValidKey> {
    const saved = copy(row)!;
    const key = saved[this.table.keyPath] as IDBValidKey;

    this.table.rows.set(key, saved);

    return MemoryRequest.answering(key);
  }

  delete(query: IDBValidKey | MemoryRange): MemoryRequest<undefined> {
    for (const key of [...this.table.rows.keys()]) {
      if (query instanceof MemoryRange ? query.includes(key) : key === query) this.table.rows.delete(key);
    }

    return MemoryRequest.answering(undefined);
  }

  index(name: string) {
    const path = this.table.indexes.get(name);

    if (!path) throw new DOMException(`No index called ${name}.`, 'NotFoundError');

    return {
      getAll: (value: IDBValidKey): MemoryRequest<Row[]> =>
        MemoryRequest.answering([...this.table.rows.values()].filter((row) => row[path] === value).map((row) => copy(row)!)),
    };
  }
}

class MemoryTransaction {
  error: DOMException | null = null;
  oncomplete: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;

  constructor(private readonly tables: Map<string, Table>) {
    // Two hops, so every request made in the same synchronous burst as the
    // transaction has answered before it completes.
    queueMicrotask(() => queueMicrotask(() => this.oncomplete?.()));
  }

  objectStore(name: string): MemoryStore {
    const table = this.tables.get(name);

    if (!table) throw new DOMException(`No object store called ${name}.`, 'NotFoundError');

    return new MemoryStore(table);
  }
}

class MemoryConnection {
  constructor(private readonly database: Database) {}

  createObjectStore(name: string, { keyPath }: { keyPath: string }) {
    const table: Table = { keyPath, indexes: new Map(), rows: new Map() };

    this.database.tables.set(name, table);

    return {
      createIndex: (index: string, path: string) => void table.indexes.set(index, path),
    };
  }

  transaction(_names: string | string[], _mode?: IDBTransactionMode): MemoryTransaction {
    return new MemoryTransaction(this.database.tables);
  }

  close(): void {}
}

/**
 * A fresh IndexedDB, empty, with the key range the store deletes a list by.
 * Install both as globals before the store under test is created: it asks
 * whether IndexedDB exists as it is constructed.
 */
export function memoryIndexedDB(): { indexedDB: IDBFactory; IDBKeyRange: typeof IDBKeyRange } {
  const databases = new Map<string, Database>();

  const factory = {
    open(name: string, version = 1): MemoryRequest<MemoryConnection> {
      const opening = new MemoryRequest<MemoryConnection>();

      queueMicrotask(() => {
        const database = databases.get(name) ?? { version: 0, tables: new Map() };
        const oldVersion = database.version;

        databases.set(name, database);
        opening.result = new MemoryConnection(database);

        if (oldVersion < version) {
          database.version = version;
          opening.onupgradeneeded?.({ oldVersion, newVersion: version });
        }

        opening.onsuccess?.();
      });

      return opening;
    },
  };

  const keyRange = {
    bound: (lower: string, upper: string) => new MemoryRange(lower, upper),
  };

  return {
    indexedDB: factory as unknown as IDBFactory,
    IDBKeyRange: keyRange as unknown as typeof IDBKeyRange,
  };
}

function copy(row: Row | undefined): Row | undefined {
  return row === undefined ? undefined : (JSON.parse(JSON.stringify(row)) as Row);
}
