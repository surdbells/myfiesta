const { readFileSync, readdirSync, existsSync, statSync } = require('node:fs');
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
 *
 * The production images are held to the same rule, read from their
 * Dockerfiles instead. Every Dockerfile in ops/docker has to be built by a
 * job, and everything it copies out of the repository has to be in that
 * job's filter: a lockfile left out is a change that breaks the release
 * build and still comes back green.
 */

const ROOT = join(__dirname, '..');
const WORKFLOW = '.github/workflows/ci.yml';
const IMAGES = 'ops/docker';

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

const lines = readFileSync(join(ROOT, WORKFLOW), 'utf8').split(/\r?\n/);

/** The filters block, read as the lines paths-filter itself will parse. */
function filters() {
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

/** A filter pattern as a regular expression, the way paths-filter matches it. */
function pattern(glob) {
  const source = glob
    .split(/(\*\*\/|\*\*|\*|\?)/)
    .map((part) => {
      if (part === '**/') return '(?:.*/)?';
      if (part === '**') return '.*';
      if (part === '*') return '[^/]*';
      if (part === '?') return '[^/]';

      return part.replace(/[.+^${}()|[\]\\]/g, '\\$&');
    })
    .join('');

  return new RegExp(`^${source}$`);
}

/**
 * Whether a filter takes in the whole of a folder or a file.
 *
 * A folder is taken in by its own `folder/**` or by one above it, so
 * `packages/**` takes in every package. A file can also be named by a pattern
 * of its own. A pattern that takes in part of a folder does not count: a
 * change to the rest of it would run nothing.
 */
function covers(paths, target, folder) {
  return paths.some((path) => {
    if (path === '**') return true;

    if (path.endsWith('/**')) {
      const base = path.slice(0, -3);

      if (target === base || target.startsWith(`${base}/`)) return true;
    }

    return !folder && pattern(path).test(target);
  });
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

/**
 * What a Dockerfile takes out of the repository: the source of every COPY
 * and ADD that is not --from another stage or image. Every image here builds
 * from the root, as its own header says, so these are paths from the root.
 */
function copies(dockerfile) {
  const found = [];
  const instructions = readFileSync(join(ROOT, dockerfile), 'utf8')
    .replace(/\\\r?\n/g, ' ')
    .split(/\r?\n/)
    .map((line) => line.trim());

  for (const instruction of instructions) {
    const copy = instruction.match(/^(?:COPY|ADD)\s+(.*)$/i);

    if (!copy) continue;

    const words = copy[1].split(/\s+/);

    while (words.length > 0 && words[0].startsWith('--')) {
      if (words.shift().startsWith('--from=')) words.length = 0;
    }

    const rest = words.join(' ');
    const args = rest.startsWith('[') ? JSON.parse(rest) : words;

    for (const source of args.slice(0, -1)) {
      if (source.startsWith('<<') || /^[a-z]+:\/\//i.test(source)) continue;

      // Up to the first wildcard, so a pattern is answered for the folder it
      // reaches into.
      const path = source.split(/[*?[]/)[0].replace(/^\.\//, '').replace(/\/+$/, '');

      found.push(path === '' ? '.' : path);
    }
  }

  return found;
}

/**
 * The job that builds each Dockerfile, and the filter it runs on: null when
 * it runs on every change, which no filter can leave anything out of.
 */
function builders() {
  const found = new Map();
  const job = (line) => /^ {2}[\w-]+:\s*$/.test(line);

  lines.forEach((line, i) => {
    const file = line.match(/^\s*file:\s*['"]?([^'"\s]+\.Dockerfile)['"]?\s*$/);

    if (!file) return;

    let start = i;
    let end = i + 1;

    while (start > 0 && !job(lines[start])) start--;
    while (end < lines.length && !job(lines[end])) end++;

    const gate = lines
      .slice(start, end)
      .map((l) => l.match(/^ {4}if:.*\bneeds\.changes\.outputs\.([\w-]+)/))
      .find(Boolean);

    found.set(file[1].replace(/^\.\//, ''), { job: lines[start].trim().replace(/:$/, ''), filter: gate ? gate[1] : null });
  });

  return found;
}

const all = filters();

/** The changes job's outputs, each to the filter it hands on. */
const handed = new Map(
  lines
    .map((line) => line.match(/^\s+([\w-]+):\s*\$\{\{\s*steps\.filter\.outputs\.([\w-]+)\s*\}\}\s*$/))
    .filter(Boolean)
    .map((m) => [m[1], m[2]])
);

const built = builders();
const imageFilters = new Set([...built.values()].map(({ filter }) => handed.get(filter)).filter(Boolean));
const workspaces = new Set(JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8')).workspaces ?? []);
const problems = [];
let checked = 0;

for (const [name, paths] of all) {
  // The images' filter names several apps and is none of theirs. It is held
  // to what the Dockerfiles copy, below.
  if (imageFilters.has(name)) continue;

  const own = paths.map((path) => path.match(/^(apps\/[^/]+)\/\*\*$/)).find(Boolean);

  if (!own) continue;

  checked++;

  for (const [folder, file] of closure(join(ROOT, own[1]))) {
    if (!existsSync(join(ROOT, 'packages', folder))) {
      problems.push(`${name}: ${file} reads packages/${folder}, which does not exist`);
    } else if (!covers(paths, `packages/${folder}`, true)) {
      problems.push(`${name}: ${file} reads packages/${folder}, and the filter leaves out 'packages/${folder}/**'`);
    }
  }

  // An app installed from the root lockfile changes whenever the lockfile does.
  if (workspaces.has(own[1])) {
    for (const path of ['package.json', 'package-lock.json']) {
      if (!covers(paths, path, false)) {
        problems.push(`${name}: installs from the root, and the filter leaves out '${path}'`);
      }
    }
  }
}

if (checked === 0) problems.push(`${WORKFLOW}: no filter names an app, so nothing here was checked`);

let images = 0;

for (const name of readdirSync(join(ROOT, IMAGES)).filter((n) => n.endsWith('.Dockerfile')).sort()) {
  const dockerfile = `${IMAGES}/${name}`;
  const builder = built.get(dockerfile);

  if (!builder) {
    problems.push(`${dockerfile}: no job in ${WORKFLOW} builds it, so nothing finds out when it stops building`);
    continue;
  }

  images++;

  // Runs on every change, so no filter can leave anything out of it.
  if (builder.filter === null) continue;

  const filter = handed.get(builder.filter);
  const paths = all.get(filter);

  // Said once, for every job, below.
  if (!paths) continue;

  // The Dockerfile itself, and .dockerignore, which decides what of each
  // folder copied reaches the build.
  const inputs = new Set([
    dockerfile,
    ...(existsSync(join(ROOT, '.dockerignore')) ? ['.dockerignore'] : []),
    ...copies(dockerfile),
  ]);

  for (const input of inputs) {
    const path = join(ROOT, input);

    if (!existsSync(path)) {
      problems.push(`${filter}: ${dockerfile} copies ${input}, which is not in the repository`);
    } else if (covers(paths, input, statSync(path).isDirectory())) {
      continue;
    } else if (input === dockerfile) {
      problems.push(`${filter}: the filter leaves out ${dockerfile} itself`);
    } else {
      problems.push(`${filter}: ${dockerfile} reads ${input}, and the filter leaves it out`);
    }
  }
}

// A job waiting on an output the changes job does not hand on never runs,
// and never says so: the output is empty, not false.
for (const gate of new Set(lines.flatMap((line) => [...line.matchAll(/needs\.changes\.outputs\.([\w-]+)/g)].map((m) => m[1])))) {
  if (!handed.has(gate)) {
    problems.push(`${WORKFLOW}: a job runs on '${gate}', and the changes job's outputs leave it out`);
  } else if (!all.has(handed.get(gate))) {
    problems.push(`${WORKFLOW}: a job runs on '${gate}', and there is no '${handed.get(gate)}' filter`);
  }
}

if (problems.length > 0) {
  console.error('\nci filters: a job builds from a path its filter does not name\n');

  for (const problem of problems) console.error(`  ${problem}`);

  console.error(
    `\nAdd the path to that filter in ${WORKFLOW}. A change there alone runs\n` +
      'none of the builds or tests that use it, and still comes back green.\n'
  );

  process.exit(1);
}

console.log(`ci filters: ${checked} apps and ${images} images, each filter naming everything its job builds from`);
