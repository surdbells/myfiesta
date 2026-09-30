import { existsSync, readFileSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';

/**
 * What every page of the site downloads before it draws.
 *
 * The bundler puts code in a download by the file it lives in. So the
 * header's one import of @myfiesta/ui counted as using every file the kit's
 * index names that any page uses — the dropdown with all of Angular's forms,
 * the confirmation dialog, the modal — and they came down on every page,
 * including the ones that never ask anything: 500.6 kB against a 500 kB
 * budget. The shell takes the kit a file at a time instead
 * (@myfiesta/ui/icon, …/theme).
 *
 * The budget only warns, and a warning in a build log is easy to miss, so
 * this walks what src/main.ts imports up front and fails on the index. The
 * console has the same check.
 */

const ROOT = process.cwd();
const KIT = resolve(ROOT, '../../packages/ui/src');

/** What a file imports before it runs: not `import type`, not `import()`. */
function importsOf(source: string): string[] {
  const found: string[] = [];

  for (const [, clause, specifier] of source.matchAll(/^\s*import\s+([\s\S]*?)\s*from\s*'([^']+)'/gm)) {
    if (!clause.startsWith('type ')) found.push(specifier);
  }

  for (const [, specifier] of source.matchAll(/^\s*import\s+'([^']+)'/gm)) found.push(specifier);

  return found;
}

/** A specifier as a file on disk, or null for a package this does not follow. */
function fileFor(specifier: string, from: string): string | null {
  const base = specifier.startsWith('.')
    ? resolve(dirname(from), specifier)
    : specifier.startsWith('@myfiesta/ui/')
      ? resolve(KIT, specifier.slice('@myfiesta/ui/'.length))
      : null;

  if (base === null) return null;

  return [`${base}.ts`, resolve(base, 'index.ts')].find((path) => existsSync(path)) ?? null;
}

/** Every file reached from the entry, with what each one imports. */
function firstLoad(entry: string): Map<string, string[]> {
  const reached = new Map<string, string[]>();
  const queue = [resolve(ROOT, entry)];

  while (queue.length > 0) {
    const file = queue.pop()!;

    if (reached.has(file)) continue;

    const specifiers = importsOf(readFileSync(file, 'utf8'));
    reached.set(file, specifiers);

    for (const specifier of specifiers) {
      const next = fileFor(specifier, file);

      if (next !== null) queue.push(next);
    }
  }

  return reached;
}

describe('the first download', () => {
  const reached = firstLoad('src/main.ts');
  const named = (file: string) => relative(ROOT, file).replaceAll('\\', '/');

  it('follows the header into the kit', () => {
    // Guards the guard: a walk that stopped at main.ts would pass anything.
    const files = [...reached.keys()].map(named);

    expect(files).toContain('src/app/app.ts');
    expect(files).toContain('../../packages/ui/src/theme.ts');
  });

  it('takes nothing through the index of @myfiesta/ui', () => {
    const offenders = [...reached]
      .filter(([, specifiers]) => specifiers.includes('@myfiesta/ui'))
      .map(([file]) => named(file));

    expect(offenders, 'import these from @myfiesta/ui/<file> instead').toEqual([]);
  });

  it("brings none of Angular's forms", () => {
    // The pages that ask for something (checkout, finding tickets, the event
    // filters) load with their route and bring their own. Up front, the
    // dropdown alone would add them to every page, including the ones that
    // never ask.
    const offenders = [...reached]
      .filter(([, specifiers]) => specifiers.includes('@angular/forms'))
      .map(([file]) => named(file));

    expect(offenders, 'these bring @angular/forms into the first download').toEqual([]);
  });
});
