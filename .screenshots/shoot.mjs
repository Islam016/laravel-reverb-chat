import { chromium } from 'playwright';
import fs from 'node:fs';

const base = 'http://127.0.0.1:8000';
const out = 'screens';
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function login(email) {
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 820 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.goto(`${base}/login`);
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'password');
  await page.click('button:has-text("Sign in")');
  await page.waitForSelector('#conversations button[data-id]', { timeout: 30000 });
  await wait(2500);
  return page;
}

async function open(page, name) {
  await page.click(`#conversations button[data-id]:has-text("${name}")`);
  await wait(1500);
}

async function send(page, text) {
  await page.fill('#composer input[name=body]', text);
  await page.click('#composer button:has-text("Send")');
  await wait(1500);
}

try {
  const sara = await login('sara@example.com');
  await sara.screenshot({ path: `${out}/chat-1-sidebar.png` });

  await open(sara, 'Omar Khalid');
  const omar = await login('omar@example.com');

  await send(sara, 'Morning Omar, the October site report is uploaded. Can you check the totals before the 2 pm meeting?');
  await wait(2000);
  await omar.screenshot({ path: `${out}/chat-2-unread-badge.png` });

  await open(omar, 'Sara Ahmed');
  await send(omar, 'On it. I will confirm in 10 minutes 👍');
  await wait(2500);
  await sara.screenshot({ path: `${out}/chat-3-direct-live.png` });

  await open(sara, 'Operations team');
  await send(sara, 'Team, the new leave policy is live in the HR system. Please review it today.');
  await open(omar, 'Operations team');
  await send(omar, 'Done, thanks Sara.');
  await wait(2500);
  await sara.screenshot({ path: `${out}/chat-4-group.png` });
  await omar.screenshot({ path: `${out}/chat-5-group-other-user.png` });
} catch (e) {
  console.error('chat screenshots failed', e);
}

for (const [name, url] of [
  ['wajiz-1-home-ar', 'https://www.wajiz.sa/ar'],
  ['wajiz-2-home-en', 'https://www.wajiz.sa/en'],
  ['wajiz-3-projects', 'https://www.wajiz.sa/ar/projects'],
]) {
  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
    await wait(2000);
    await page.screenshot({ path: `${out}/${name}.png` });
    await page.evaluate(() => window.scrollTo(0, Math.min(document.body.scrollHeight, 1100)));
    await wait(1500);
    await page.screenshot({ path: `${out}/${name}-b.png` });
    const m = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true });
    const mp = await m.newPage();
    await mp.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
    await wait(1500);
    await mp.screenshot({ path: `${out}/${name}-mobile.png` });
  } catch (e) {
    console.error('site screenshot failed', url, e);
  }
}

try {
  const page = await (await browser.newContext({ viewport: { width: 1200, height: 900 } })).newPage();
  await page.goto('http://127.0.0.1:8100/demo.php');
  await wait(1000);
  await page.screenshot({ path: `${out}/arabic-search-demo.png`, fullPage: true });
} catch (e) {
  console.error('arabic-search demo failed', e);
}

await browser.close();
