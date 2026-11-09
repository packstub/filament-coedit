// Filament Co-Edit screenshots → docs/images, from the workbench (`composer serve`).
// Two people, two incognito contexts: Ion and Ana open the same product, Ana works in a
// field, saves, and Ion's save lands on top. Every step is asserted, so the rig doubles as
// an end-to-end check of the browser side (heartbeats, field markers, notice, dialog).
//
//   PHP_CLI_SERVER_WORKERS=8 vendor/bin/testbench serve --port=8765 &
//   node scripts/screenshots.mjs       (rebuilds the workbench database first; --no-seed to skip)
//
// It logs in three times; Filament allows five logins a minute per IP, so leave a minute between runs.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const require = createRequire(process.env.PUPPETEER_FROM ?? new URL('../../../demos/packstub-demo/package.json', import.meta.url).pathname);
const puppeteer = require('puppeteer-core');

const BASE = process.env.BASE ?? 'http://127.0.0.1:8765';
const OUT = process.env.OUT ?? new URL('../docs/images/', import.meta.url).pathname;
const W = 1440, H = 900;
fs.mkdirSync(OUT, { recursive: true });

// Every run starts from the seeded catalogue, or Ana's "changes" may already be saved.
if (!process.argv.includes('--no-seed')) {
  execFileSync('vendor/bin/testbench', ['workbench:build'], { cwd: new URL('..', import.meta.url).pathname, stdio: 'ignore' });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const browser = await puppeteer.launch({
  executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: process.env.HEADED ? false : true,
  defaultViewport: { width: W, height: H, deviceScaleFactor: 2 },
});

const errors = [];

async function person(email) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);
  page.on('pageerror', (e) => errors.push(`${email}: ${e.message}`));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' });
  await page.type('input[type=email]', email);
  await page.type('input[type=password]', 'password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
  return page;
}

function check(condition, message) {
  if (!condition) {
    throw new Error(`Check failed: ${message}`);
  }
  console.log(`✓ ${message}`);
}

const save = (page) => page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.innerText.trim() === 'Save changes').click());
// Replace a field's text the way a person would: select all, type over it.
const retype = async (page, selector, text) => {
  await page.click(selector);
  await page.$eval(selector, (el) => el.select());
  await page.keyboard.type(text);
};
const field = (name) => `[id="form.${name}"]`;

try {
  const ion = await person('ion@example.com');
  const ana = await person('ana@example.com');
  const mihai = await person('mihai@example.com');

  const product = `${BASE}/admin/products/3/edit`;
  await Promise.all([ion, ana, mihai].map((page) => page.goto(product, { waitUntil: 'networkidle0' })));

  // Ana works on the price; Mihai is in the description.
  await ana.click(field('price'));
  await mihai.click(field('description'));
  await sleep(5500);

  const avatars = await ion.$$eval('.fi-coedit-stack .fi-coedit-avatar:not(.fi-coedit-more)', (els) => els.map((el) => el.title));
  check(avatars.length === 2 && avatars.some((t) => t.startsWith('Ana Popescu')), `Ion sees two others: ${avatars.join(' | ')}`);

  const tags = await ion.$$eval('.fi-coedit-tag', (els) => els.map((el) => el.textContent));
  check(tags.includes('Ana Popescu') && tags.includes('Mihai Rusu'), `field markers on Ion's form: ${tags.join(', ')}`);
  await ion.screenshot({ path: `${OUT}presence.png` });

  // The table shows who is on which row.
  await ion.goto(`${BASE}/admin/products`, { waitUntil: 'networkidle0' });
  const rowAvatars = await ion.$$eval('.fi-coedit-column .fi-coedit-avatar', (els) => els.map((el) => el.title));
  check(rowAvatars.length >= 2, `table column shows ${rowAvatars.length} people on the row`);
  await ion.screenshot({ path: `${OUT}table.png` });
  await ion.goto(product, { waitUntil: 'networkidle0' });
  await mihai.close();

  // Ion edits the description while Ana changes status and price, and saves first.
  await retype(ion, field('description'), 'Waxed canvas body, vegetable-tanned leather handles, fits a 16" laptop.');

  await retype(ana, field('price'), '139');
  await ana.select('select[id="form.status"]', 'published').catch(() => {});
  await ana.evaluate(() => {
    const status = document.querySelector('select[id="form.status"]');
    if (status) { status.value = 'published'; status.dispatchEvent(new Event('change', { bubbles: true })); }
  });
  await save(ana);
  await sleep(1500);
  await sleep(5000);

  // The notice is a Filament notification, sent by the presence component from the heartbeat.
  const notice = await ion.$$eval('.fi-no-notification', (els) => els.map((el) => el.innerText).find((text) => text.includes('saved this record')) ?? '').catch(() => '');
  check(notice.includes('Ana Popescu saved this record'), `Ion gets the notice: ${notice.split('\n')[0]}`);
  check(notice.includes('Get their changes') && notice.includes('Dismiss'), 'the notice has its two buttons');
  await ion.screenshot({ path: `${OUT}notice.png` });

  // Ion dismisses it and saves: Ana's price and status are kept, his description goes in.
  await ion.evaluate(() => [...document.querySelectorAll('.fi-no-notification button')].find((b) => b.innerText.trim() === 'Dismiss').click());
  await sleep(1000);
  const gone = await ion.$$eval('.fi-no-notification', (els) => els.every((el) => !el.innerText.includes('saved this record')));
  check(gone, 'Dismiss closes the notice');
  await save(ion);
  await sleep(2000);
  const toast = await ion.$eval('.fi-no', (el) => el.innerText).catch(() => '');
  check(toast.includes("Kept Ana Popescu's changes"), `merge toast: ${toast.split('\n').slice(0, 2).join(' / ')}`);
  await ion.screenshot({ path: `${OUT}merged.png` });

  // Now a real conflict: both change the name.
  await ana.reload({ waitUntil: 'networkidle0' });
  await ion.reload({ waitUntil: 'networkidle0' });
  await retype(ana, field('name'), 'Canvas weekender bag');
  await retype(ion, field('name'), 'Waxed canvas weekender');
  await save(ana);
  await sleep(1500);
  await save(ion);
  await sleep(2000);

  const modal = await ion.$eval('.fi-modal-window', (el) => el.innerText).catch(() => '');
  check(modal.includes('saved this record while you were editing') && modal.includes('Canvas weekender bag'), 'conflict dialog shows both versions');
  await ion.screenshot({ path: `${OUT}conflict.png` });

  // Modal edits (a simple resource, no edit page): both open the same supplier in a modal.
  const openSupplier = async (page) => {
    await page.goto(`${BASE}/admin/suppliers`, { waitUntil: 'networkidle0' });
    await page.evaluate(() => {
      const row = [...document.querySelectorAll('tr')].find((tr) => tr.innerText.includes('Okayama Denim'));
      [...row.querySelectorAll('button, a')].find((el) => el.innerText.trim() === 'Edit').click();
    });
    await page.waitForSelector('.fi-modal-window [id$=".contact"]', { visible: true });
  };
  await openSupplier(ion);
  await openSupplier(ana);
  await ana.click('.fi-modal-window [id$=".lead_time_days"]');
  await ion.waitForFunction(() => [...document.querySelectorAll('.fi-coedit-tag')].some((el) => el.textContent === 'Ana Popescu'), { timeout: 15000 }).catch(() => {});

  const modalAvatars = await ion.$$eval('.fi-modal-window .fi-coedit-stack .fi-coedit-avatar:not(.fi-coedit-more)', (els) => els.map((el) => el.title));
  check(modalAvatars.some((t) => t.startsWith('Ana Popescu')), `the modal shows who else is on the record: ${modalAvatars.join(' | ')}`);
  const modalTags = await ion.$$eval('.fi-coedit-tag', (els) => els.map((el) => el.textContent));
  check(modalTags.includes('Ana Popescu'), 'and which field she is in');
  await ion.screenshot({ path: `${OUT}modal.png` });

  await retype(ana, '.fi-modal-window [id$=".lead_time_days"]', '30');
  await ana.evaluate(() => [...document.querySelectorAll('.fi-modal-window button')].find((b) => b.innerText.trim() === 'Save changes' || b.innerText.trim() === 'Save').click());
  await sleep(1500);

  await retype(ion, '.fi-modal-window [id$=".contact"]', 'Kenji Mori (sales)');
  await ion.evaluate(() => [...document.querySelectorAll('.fi-modal-window button')].find((b) => b.innerText.trim() === 'Save changes' || b.innerText.trim() === 'Save').click());
  await sleep(2000);
  const modalToast = await ion.$$eval('.fi-no', (els) => els.map((el) => el.innerText).join(' / ')).catch(() => '');
  check(modalToast.includes("Kept Ana Popescu's changes"), `modal save keeps Ana's lead time: ${modalToast.split('\n').slice(0, 2).join(' ')}`);

  const supplierRow = await ion.evaluate(() => [...document.querySelectorAll('tr')].find((tr) => tr.innerText.includes('Okayama Denim'))?.innerText ?? '');
  check(supplierRow.includes('Kenji Mori (sales)') && supplierRow.includes('30 days'), 'the table shows his contact and her lead time');

  check(errors.length === 0, `no page errors${errors.length ? `: ${errors.join('; ')}` : ''}`);
} finally {
  await browser.close();
}
