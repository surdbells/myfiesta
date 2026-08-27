/**
 * Which inputs across the console are unstyled, and why.
 *
 * base.css styles fields by explicit type — input[type='text'] and friends —
 * so an <input> written without a type attribute matches none of them and
 * falls back to the browser's own. It still behaves as a text field, so
 * nothing fails; it just renders as a hairline next to properly boxed
 * siblings.
 */
const puppeteer = require('puppeteer-core');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const API = 'http://127.0.0.1:8000';
const CONSOLE = 'http://localhost:4310';

(async () => {
  const routes = process.argv.slice(2);

  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--disable-gpu', '--no-sandbox'],
    defaultViewport: { width: 1440, height: 1000 },
  });

  const page = await browser.newPage();
  await page.goto(`${CONSOLE}/sign-in`, { waitUntil: 'domcontentloaded' });

  await page.evaluate(async (api) => {
    const r = await fetch(`${api}/api/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ email: 'ada@lagosnights.test', password: 'password123' }),
    });
    localStorage.setItem('myfiesta.organizer.session', JSON.stringify(await r.json()));
  }, API);

  let bad = 0;

  for (const route of routes) {
    await page.goto(`${CONSOLE}${route}`, { waitUntil: 'networkidle2' });
    await new Promise((r) => setTimeout(r, 2000));

    const found = await page.evaluate(() =>
      [...document.querySelectorAll('input, select, textarea')]
        .filter((el) => !['checkbox', 'radio', 'file', 'hidden'].includes(el.type))
        .map((el) => {
          const cs = getComputedStyle(el);
          return {
            tag: el.tagName.toLowerCase(),
            typed: el.hasAttribute('type'),
            name: el.getAttribute('name') || el.id || '(unnamed)',
            height: Math.round(el.getBoundingClientRect().height),
            border: cs.borderTopWidth,
          };
        }),
    );

    const unstyled = found.filter((f) => f.height < 40);

    if (unstyled.length) {
      bad += unstyled.length;
      console.log(`\n${route}`);
      for (const f of unstyled) {
        console.log(
          `   ${f.tag.padEnd(9)} ${f.name.padEnd(22)} type=${String(f.typed).padEnd(5)} ${f.height}px`,
        );
      }
    }
  }

  console.log(`\n${bad} field(s) rendering below 40px`);
  await browser.close();
})();
