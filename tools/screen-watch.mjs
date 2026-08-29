/**
 * Walk screens in a real browser and report what breaks.
 *
 * **Why this exists.** Every defect found while working through the modules this week surfaced from
 * the SCREEN, not from the suite: a 500 on a lease page, a picker that opened empty, a forecast row
 * built from two different truths. The Pest suite cannot see any of them — it drives services and
 * mounts components, and a fatal in a render or an Alpine race lives past all of it.
 *
 * The full Playwright suite exists and takes ~25 minutes, which is why nobody runs it mid-task. This
 * is the same idea aimed at whatever we are working on right now: give it paths, get back the
 * server errors, console errors and rendered exceptions.
 *
 * It does NOT try to drive pickers. `20-functional-create.spec.js` already established the honest
 * pattern — fill what can be filled, and let the 5xx listener be the assertion — because precisely
 * automating a Filament combobox is brittle enough to cost more than it finds.
 *
 *   node tools/screen-watch.mjs                       # the default sweep
 *   node tools/screen-watch.mjs /admin/AW/leases/1/edit /admin/AW/invoices
 *   node tools/screen-watch.mjs --actions /admin/AW/leases/1/edit
 */
import { chromium } from '@playwright/test';

const BASE = process.env.APP_URL ?? 'https://mall-management.test';
const EMAIL = process.env.E2E_EMAIL ?? 'admin@mall.test';
const PASSWORD = process.env.E2E_PASSWORD ?? 'password';

const args = process.argv.slice(2);
const openActions = args.includes('--actions');
const paths = args.filter((a) => a.startsWith('/'));

const DEFAULT_PATHS = [
    '/admin/AW',
    '/admin/AW/leases',
    '/admin/AW/invoices',
    '/admin/AW/payments',
    '/admin/AW/credit-notes',
    '/admin/AW/deposit-transactions',
    '/admin/AW/billing-run-preview',
    '/admin/AW/rent-indices',
    '/admin/AW/trial-balance',
    '/admin/AW/general-ledger',
];

const targets = paths.length ? paths : DEFAULT_PATHS;
const findings = [];

const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1500, height: 1100 } });
const page = await context.newPage();

// Everything that counts as "this broke", collected per page rather than per run so a finding names
// the screen it came from.
let current = '';
page.on('response', (r) => {
    if (r.status() >= 400) findings.push({ where: current, what: `HTTP ${r.status()}`, detail: r.url().replace(BASE, '') });
});
page.on('console', (m) => {
    if (m.type() === 'error') findings.push({ where: current, what: 'console', detail: m.text().slice(0, 160) });
});
page.on('pageerror', (e) => findings.push({ where: current, what: 'js', detail: String(e).slice(0, 160) }));

async function signIn() {
    current = '/admin/login';
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.locator('#form\\.email').fill(EMAIL);
    await page.locator('#form\\.password').fill(PASSWORD);
    await page.getByRole('button', { name: /sign in|log in/i }).click();
    await page.waitForURL(/\/admin\//, { timeout: 20000 });
}

/** The exception Laravel renders — a 200 page can still be an error page. */
async function renderedException() {
    const text = await page.locator('body').innerText().catch(() => '');

    for (const marker of ['Internal Server Error', 'BadMethodCallException', 'Undefined variable', 'Call to a member function', 'Class "', 'SQLSTATE']) {
        if (text.includes(marker)) return `${marker} — ${text.slice(0, 140).replace(/\s+/g, ' ')}`;
    }

    return null;
}

await signIn();

for (const path of targets) {
    current = path;
    process.stdout.write(`  ${path.padEnd(44)}`);

    const before = findings.length;
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch((e) => {
        findings.push({ where: path, what: 'navigation', detail: String(e).slice(0, 120) });
    });
    await page.waitForTimeout(900);

    const rendered = await renderedException();
    if (rendered) findings.push({ where: path, what: 'exception', detail: rendered });

    // Header actions are where the fatals hide: an action's schema is built when it MOUNTS, so a
    // page can render perfectly and blow up the moment somebody clicks.
    if (openActions) {
        const buttons = await page.locator('header button, .fi-header button, .fi-ac button').all();

        for (const b of buttons.slice(0, 12)) {
            const label = (await b.innerText().catch(() => '')).trim().slice(0, 24);
            if (! label) continue;

            await b.click({ timeout: 4000 }).catch(() => {});
            await page.waitForTimeout(700);

            const inModal = await renderedException();
            if (inModal) findings.push({ where: `${path} → ${label}`, what: 'exception', detail: inModal });

            await page.keyboard.press('Escape').catch(() => {});
            await page.waitForTimeout(250);
        }
    }

    console.log(findings.length > before ? `✗ ${findings.length - before}` : 'ok');
}

await browser.close();

console.log('');
if (! findings.length) {
    console.log(`clean — ${targets.length} screen(s), nothing broke.`);
} else {
    console.log(`${findings.length} finding(s):`);
    for (const f of findings) console.log(`  [${f.what}] ${f.where}\n      ${f.detail}`);
}

process.exit(findings.some((f) => f.what !== 'console') ? 1 : 0);
