/**
 * Screenshot console screens, signed in.
 *
 * Written because for most of this project I could not see anything I was
 * building. The browser pane would not composite, so every screen was
 * verified through rendered DOM and computed styles — which catches a
 * missing aria-label and does not catch a card wearing a panel it never
 * asked for, a row sized for six holding one, or a title sitting on its own
 * blurred ghost. All three were there. All three were found in the first
 * five minutes of looking.
 *
 * The console keeps its session in localStorage, so a screenshot of any
 * real screen needs a browser that has signed in first — which the Chrome
 * CLI cannot do, and which is why this drives Chrome rather than shelling
 * out to it.
 *
 *   node tools/screenshot.cjs <outDir> <width> <label:path> [label:path ...]
 *
 * Needs the console, the API, and an organizer account the seed creates.
 */
const puppeteer = require('puppeteer-core');
const path = require('path');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const API = 'http://127.0.0.1:8000';
const CONSOLE = 'http://localhost:4310';

(async () => {
  const [outDir, widthArg, ...targets] = process.argv.slice(2);
  const width = Number(widthArg) || 1440;

  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--disable-gpu', '--hide-scrollbars', '--no-sandbox'],
    defaultViewport: { width, height: 1000 },
  });

  const page = await browser.newPage();

  // Land on the origin first: localStorage is per-origin, so it cannot be
  // written before the browser has been there.
  await page.goto(`${CONSOLE}/sign-in`, { waitUntil: 'domcontentloaded' });

  const session = await page.evaluate(async (api) => {
    const response = await fetch(`${api}/api/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email: 'ada@lagosnights.test', password: 'password123' }),
    });

    if (!response.ok) return { error: response.status };

    const body = await response.json();
    localStorage.setItem('myfiesta.organizer.session', JSON.stringify(body));

    return { ok: true, org: body.organizations?.[0]?.name };
  }, API);

  if (session.error) {
    console.error('sign-in failed:', session.error);
    await browser.close();
    process.exit(1);
  }

  console.log('signed in as', session.org);

  for (const target of targets) {
    const at = target.indexOf(':');
    const label = target.slice(0, at);
    const route = target.slice(at + 1);

    await page.goto(`${CONSOLE}${route}`, { waitUntil: 'networkidle2' });
    // Long enough for the second render after data lands.
    await new Promise((r) => setTimeout(r, 2600));

    const file = path.join(outDir, `${label}.png`);
    await page.screenshot({ path: file, fullPage: true });

    const height = await page.evaluate(() => document.body.scrollHeight);
    console.log(`${label.padEnd(14)} ${route.padEnd(46)} ${width}x${height}`);
  }

  await browser.close();
})();
