import { existsSync, readFileSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';

/**
 * What the console downloads before its first screen.
 *
 * The bundler puts code in a download by the file it lives in. So one import
 * of @myfiesta/ui from anything the shell draws counted as using every file
 * the kit's index names — the table, the filters, saved views, the date
 * picker, and all of Angular's forms behind the dropdown — and they all came
 * down before the sign-in screen: 556 kB against a 500 kB budget. The shell
 * takes the kit a file at a time instead (@myfiesta/ui/confirm, …/icon).
 *
 * The budget only warns, and a warning in a build log is easy to miss, so
 * this walks what src/main.ts imports up front and fails on the index.
 */

const ROOT = process.cwd();
const KIT = resolve(ROOT, '../../packages/ui/src');

/**
 * What the shell fetches after it draws, not with it.
 *
 * Angular leaves a component out of the first download when the template uses
 * it only inside @defer and its file names it nowhere but its import and the
 * `imports` array. Nothing says so when that stops being true (a
 * viewChild(UiSelect), a second <ui-select> outside the block), and the
 * switcher's own test cannot tell: a block whose code is already there still
 * shows its placeholder for a moment. So these are checked by reading the
 * files, and the walk does not follow them.
 */
const DEFERRED = [
  {
    file: 'src/app/app.ts',
    template: 'src/app/app.html',
    symbol: 'UiSelect',
    tag: 'ui-select',
    from: '@myfiesta/ui/select',
  },
];

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

    const later = DEFERRED.filter((d) => resolve(ROOT, d.file) === file).map((d) => d.from);

    for (const specifier of specifiers) {
      const next = later.includes(specifier) ? null : fileFor(specifier, file);

      if (next !== null) queue.push(next);
    }
  }

  return reached;
}

/** Source with its comments gone, so a comment naming a component is not a use of it. */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

describe('the first download', () => {
  const reached = firstLoad('src/main.ts');
  const named = (file: string) => relative(ROOT, file).replaceAll('\\', '/');

  it('follows the shell into the kit', () => {
    // Guards the guard: a walk that stopped at main.ts would pass anything.
    const files = [...reached.keys()].map(named);

    expect(files).toContain('src/app/app.ts');
    expect(files).toContain('src/app/features/account/verify-email.ts');
    expect(files).toContain('../../packages/ui/src/confirm.ts');
  });

  it('takes nothing through the index of @myfiesta/ui', () => {
    const offenders = [...reached]
      .filter(([, specifiers]) => specifiers.includes('@myfiesta/ui'))
      .map(([file]) => named(file));

    expect(offenders, 'import these from @myfiesta/ui/<file> instead').toEqual([]);
  });

  it('fetches the organization switcher after the shell', () => {
    for (const { file, template, symbol, tag, from } of DEFERRED) {
      const source = withoutComments(readFileSync(resolve(ROOT, file), 'utf8'));
      const word = new RegExp(`\\b${symbol}\\b`);

      const declaration = source.match(new RegExp(`import\\s*\\{([^}]*)\\}\\s*from\\s*'${from.replaceAll('/', '\\/')}';?`));
      expect(declaration, `${file} imports ${symbol} from ${from}`).not.toBeNull();

      // Anything else it takes from there has to be a type, which leaves no
      // code behind: a value would keep the whole file in the first download.
      const values = declaration![1]
        .split(',')
        .map((name) => name.trim())
        .filter((name) => name !== '' && !name.startsWith('type '));
      expect(values, `${file} takes only ${symbol} from ${from}`).toEqual([symbol]);

      const list = source.match(/imports:\s*\[([^\]]*)\]/);
      expect(list?.[1], `${file} lists ${symbol} in its imports`).toMatch(word);

      const elsewhere = source.replace(declaration![0], '').replace(list![0], '');
      expect(elsewhere, `${file} names ${symbol} outside its import and imports, so it loads up front`).not.toMatch(word);

      const html = readFileSync(resolve(ROOT, template), 'utf8');
      const count = (text: string) => text.split(`<${tag}`).length - 1;
      const inDefer = [...html.matchAll(/@defer\b[^{]*\{([\s\S]*?)\}\s*@(?:placeholder|loading|error)\b/g)].reduce(
        (total, [, body]) => total + count(body),
        0,
      );

      expect(count(html), `${template} uses <${tag}>`).toBeGreaterThan(0);
      expect(inDefer, `every <${tag}> in ${template} is inside @defer`).toBe(count(html));
    }
  });

  it("brings none of Angular's forms", () => {
    // What the switcher's @defer is for: the dropdown is the only thing the
    // shell draws that needs them, and most people never see it.
    const offenders = [...reached]
      .filter(([, specifiers]) => specifiers.includes('@angular/forms'))
      .map(([file]) => named(file));

    expect(offenders, 'these bring @angular/forms into the first download').toEqual([]);
  });
});
