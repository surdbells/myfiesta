const { readFileSync, readdirSync, existsSync } = require('node:fs');
const { join, relative } = require('node:path');

/**
 * Each app's path filter in CI, against what the app actually reads.
 *
 * CI decides which jobs to run from the paths a change touches, and an app's
 * job runs only when one of its own paths is in the change. The packages are
 * compiled as source by the apps that import them and have no jobs of their
 * own, so a package missing from an app's filter is a change to that package
 * which runs nothing that uses it — and nothing about the green tick says so.
 *
 * It has happened: the door's hash fixture in packages/contract is read by a
 * console spec and by an API test, and neither app's filter named it, so a
 * change to the fixture alone ran an OpenAPI lint and nothing else. The
 * console's copy of the permission list, in packages/api-types, was the same
 * for the API test that checks it.
 *
 * So this reads each app and every package it reaches, the way the build and
 * the tests reach them — a relative path into packages/, or an import of
 * @myfiesta/something — and fails with the filter to fix. A mention in a
 * comment is not a dependency, and is not counted.
 */

const ROOT = join(__dirname, '..');
const WORKFLOW = '.github/workflows/ci.yml';

/** Folders that are output, caches, other people's code, or native shells. */
const SKIP = new Set([
  'node_modules',
  'vendor',
  'dist',
  'www',
  '.angular',
  'android',
  'ios',
  'storage',
  'coverage',
  'public',
  'bootstrap',
]);

const EXTENSIONS = /\.(?:ts|mts|js|mjs|cjs|json|css|scss|html|php)$/;

/** A quoted relative path into packages/: an import, an @import, a tsconfig path, a file a test reads. */
const RELATIVE = /['"]\/?(?:\.\.\/)+packages\/([a-z0-9-]+)(?=[/'"])/g;

/** An import of a workspace package by its name. */
const NAMED = /(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)['"]@myfiesta\/([a-z0-9-]+)/g;

/** The filters block, read as the lines paths-filter itself will parse. */
function filters() {
  const lines = readFileSync(join(ROOT, WORKFLOW), 'utf8').split(/\r?\n/);
  const start = lines.findIndex((line) => /^\s*filters:\s*\|\s*$/.test(line));

  if (start < 0) throw new Error(`${WORKFLOW}: no "filters: |" block to read`);

  const indent = lines[start].match(/^\s*/)[0].length;
  const found = new Map();

  for (const line of lines.slice(start + 1)) {
    if (line.trim() === '') continue;
    if (line.match(/^\s*/)[0].length <= indent) break;

    const entry = line.match(/^\s*([\w-]+):\s*\[(.*)\]\s*$/);

    if (entry) found.set(entry[1], [...entry[2].matchAll(/'([^']*)'/g)].map((m) => m[1]));
  }

  return found;
}

/** Every source file under a folder, left to right, skipping what is not source. */
function* files(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name) || entry.name.startsWith('.git')) continue;

    const path = join(dir, entry.name);

    if (entry.isDirectory()) yield* files(path);
    else if (EXTENSIONS.test(entry.name)) yield path;
  }
}

/** Workspace package names to their folders under packages/. */
const named = new Map();

for (const folder of readdirSync(join(ROOT, 'packages'))) {
  const manifest = join(ROOT, 'packages', folder, 'package.json');

  if (existsSync(manifest)) named.set(JSON.parse(readFileSync(manifest, 'utf8')).name, folder);
}

/** Which packages a folder reaches directly, and the first file that does, for the message. */
function reaches(dir) {
  const found = new Map();

  for (const file of files(dir)) {
    const text = readFileSync(file, 'utf8');
    const hits = [
      ...[...text.matchAll(RELATIVE)].map((m) => m[1]),
      ...[...text.matchAll(NAMED)].map((m) => named.get(`@myfiesta/${m[1]}`) ?? m[1]),
    ];

    for (const folder of hits) {
      if (!found.has(folder)) found.set(folder, relative(ROOT, file).replace(/\\/g, '/'));
    }
  }

  return found;
}

/**
 * An app's packages, and the packages those reach in turn: api-types hands on
 * the door's shapes, so an app that compiles api-types compiles the door.
 *
 * Only what is compiled is followed. The API's tests read a file out of a
 * package as text, and whatever that file imports is nothing the API runs.
 */
function closure(dir) {
  const found = reaches(dir);
  const queue = [...found].filter(([, file]) => !file.endsWith('.php')).map(([folder]) => folder);

  while (queue.length > 0) {
    const folder = queue.shift();
    const path = join(ROOT, 'packages', folder);

    if (!existsSync(path)) continue;

    for (const [next, file] of reaches(path)) {
      if (next === folder || found.has(next)) continue;

      found.set(next, `${found.get(folder)} → ${file}`);
      queue.push(next);
    }
  }

  return found;
}

const workspaces = new Set(JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).workspaces ?? []);
const problems = [];
let checked = 0;

for (const [name, paths] of filters()) {
  const own = paths.map((path) => path.match(/^(apps\/[^/]+)\/\*\*$/)).find(Boolean);

  if (!own) continue;

  checked++;

  for (const [folder, file] of closure(join(ROOT, own[1]))) {
    if (!existsSync(join(ROOT, 'packages', folder))) {
      problems.push(`${name}: ${file} reads packages/${folder}, which does not exist`);
    } else if (!paths.includes(`packages/${folder}/**`)) {
      problems.push(`${name}: ${file} reads packages/${folder}, and the filter leaves out 'packages/${folder}/**'`);
    }
  }

  // An app installed from the root lockfile changes whenever the lockfile does.
  if (workspaces.has(own[1])) {
    for (const path of ['package.json', 'package-lock.json']) {
      if (!paths.includes(path)) {
        problems.push(`${name}: installs from the root, and the filter leaves out '${path}'`);
      }
    }
  }
}

if (checked === 0) problems.push(`${WORKFLOW}: no filter names an app, so nothing here was checked`);

if (problems.length > 0) {
  console.error('\nci filters: an app reads a path its filter does not name\n');

  for (const problem of problems) console.error(`  ${problem}`);

  console.error(
    `\nAdd the path to that filter in ${WORKFLOW}. A change there alone runs\n` +
      'none of the builds or tests that use it, and still comes back green.\n'
  );

  process.exit(1);
}

console.log(`ci filters: ${checked} apps, each filter naming every package the app reads`);
