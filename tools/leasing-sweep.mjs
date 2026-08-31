/**
 * Drive the WHOLE leasing surface in a real browser and report what breaks.
 *
 * `screen-watch.mjs` walks pages. This walks the surface an operator actually touches: every leasing
 * screen, every header action on a lease (12 of them), every relation-manager tab (13 of them), and
 * every action inside each tab. That matters because a Filament action builds its SCHEMA when it
 * mounts, so a page can render perfectly and fatal the moment somebody clicks — which is how a
 * missing `use` and a missing `use ($get)` each shipped for days past a green suite.
 *
 * It reports four things, and only the first three are failures:
 *   HTTP >= 400 · an uncaught JS error · an exception rendered into a 200 page · a console error.
 *
 *   node tools/leasing-sweep.mjs                 # every screen, every action, every tab
 *   node tools/leasing-sweep.mjs --lease 3       # against a specific lease
 *   node tools/leasing-sweep.mjs --quick         # screens only, no actions or tabs
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';

const BASE = process.env.APP_URL ?? 'https://mall-management.test';
const EMAIL = process.env.E2E_EMAIL ?? 'admin@mall.test';
const PASSWORD = process.env.E2E_PASSWORD ?? 'password';

const args = process.argv.slice(2);
const quick = args.includes('--quick');
const leaseId = args.includes('--lease') ? args[args.indexOf('--lease') + 1] : '3';
const only = args.includes('--only') ? args[args.indexOf('--only') + 1] : null;

const SCREENS = [
    '/admin/AW/leases',
    `/admin/AW/leases/${leaseId}/edit`,
    '/admin/AW/leases/create',
    '/admin/AW/units',
    '/admin/AW/units/create',
    '/admin/AW/rent-roll',
    '/admin/AW/expiration-schedule',
    '/admin/AW/occupancy-map',
    '/admin/AW/rentable-items',
    '/admin/AW/rentable-item-map',
    '/admin/AW/rent-indices',
    '/admin/AW/clause-register',
    '/admin/AW/tenants',
    '/admin/AW/revenue-forecast',
];

const findings = [];
let current = '';

const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1600, height: 1200 } });
const page = await context.newPage();

page.on('response', (r) => {
    if (r.status() >= 400) findings.push({ where: current, what: `HTTP ${r.status()}`, detail: r.url().replace(BASE, '') });
});
page.on('console', (m) => {
    if (m.type() === 'error') findings.push({ where: current, what: 'console', detail: m.text().slice(0, 200) });
});
page.on('pageerror', (e) => findings.push({ where: current, what: 'js', detail: String(e).slice(0, 200) }));

/** A 200 page can still BE an error page — Livewire renders the exception into the response. */
async function rendered() {
    const text = await page.locator('body').innerText().catch(() => '');
    for (const m of ['Internal Server Error', 'BadMethodCallException', 'Undefined variable', 'Call to a member function',
                     'Class "', 'SQLSTATE', 'TypeError', 'ErrorException', 'ArgumentCountError', 'Method Not Allowed',
                     'Too few arguments', 'must be of type', 'Attempt to read property']) {
        if (text.includes(m)) return `${m} — ${text.replace(/\s+/g, ' ').slice(0, 200)}`;
    }
    return null;
}

/** An untranslated key rendered on screen: `admin.foo.bar` where a sentence belongs. */
async function untranslated() {
    const text = await page.locator('body').innerText().catch(() => '');
    const hits = [...new Set(text.match(/\b(admin|portal|filament)\.[a-z0-9_]+(\.[a-z0-9_]+)+/g) ?? [])];
    return hits.length ? hits.slice(0, 6).join(', ') : null;
}

async function check(label) {
    // Every screen re-proves it is signed in. A session that drops mid-sweep would otherwise turn
    // the remaining screens green.
    if (await page.locator('#form\\.password').count()) {
        findings.push({ where: label, what: 'signed-out', detail: 'redirected to the login page' });
    }
    const ex = await rendered();
    if (ex) findings.push({ where: label, what: 'exception', detail: ex });
    const un = await untranslated();
    if (un) findings.push({ where: label, what: 'untranslated', detail: un });
}

await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
await page.locator('#form\\.email').fill(EMAIL);
await page.locator('#form\\.password').fill(PASSWORD);
await page.getByRole('button', { name: /sign in|log in/i }).click();

// `waitForURL(/\/admin\//)` MATCHES `/admin/login` ITSELF, so it returns before the session exists
// and every screen after it is measured while signed out — each one redirects to the login page,
// which is a clean 200, so the whole sweep reports "ok" having examined nothing. That is the exact
// failure this file exists to catch, and the first run of it had the bug.
await page.waitForURL(/\/admin\/[A-Z]/, { timeout: 30000 });
if (await page.locator('#form\\.password').count()) {
    console.error('could not sign in — the sweep would measure the login page for every screen');
    process.exit(2);
}

// ── PASS A · every leasing screen renders ────────────────────────────────────────────────────────
console.log('\n── screens ──');
for (const path of SCREENS) {
    if (only && ! path.includes(only)) continue;
    current = path;
    const before = findings.length;
    process.stdout.write(`  ${path.padEnd(42)}`);
    await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch((e) =>
        findings.push({ where: path, what: 'navigation', detail: String(e).slice(0, 140) }));
    await page.waitForTimeout(800);
    await check(path);
    console.log(findings.length > before ? `✗ ${findings.length - before}` : 'ok');
}

if (quick) {
    report();
}

// ── PASS B · every header action on the lease opens ──────────────────────────────────────────────
const leasePath = `/admin/AW/leases/${leaseId}/edit`;
console.log('\n── lease header actions ──');
await page.goto(`${BASE}${leasePath}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1000);

const headerButtons = await page.locator('.fi-header-ctn button, .fi-header button, header button').all();
const labels = [];
for (const b of headerButtons) {
    const t = (await b.innerText().catch(() => '')).trim();
    if (t && ! labels.includes(t)) labels.push(t);
}

for (const label of labels) {
    current = `${leasePath} → ${label}`;
    const before = findings.length;
    process.stdout.write(`  ${label.padEnd(42).slice(0, 42)}`);

    await page.goto(`${BASE}${leasePath}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    const btn = page.locator('button', { hasText: label }).first();
    await btn.click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1200);
    await check(current);

    // A modal that never opened is a finding too — a header action whose click does nothing.
    const modalOpen = await page.locator('.fi-modal-window, [role="dialog"]').count();
    if (! modalOpen && ! /export|download|print|pdf|statement/i.test(label)) {
        findings.push({ where: current, what: 'no-modal', detail: 'clicked and no modal opened' });
    }

    console.log(findings.length > before ? `✗ ${findings.length - before}` : 'ok');
}

// ── PASS C · every relation-manager tab renders, and its own actions open ────────────────────────
console.log('\n── lease tabs ──');
await page.goto(`${BASE}${leasePath}`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1000);

const tabs = await page.locator('[role="tab"], .fi-tabs button, .fi-tabs a').all();
const tabLabels = [];
for (const t of tabs) {
    const s = (await t.innerText().catch(() => '')).trim().split('\n')[0];
    if (s && ! tabLabels.includes(s)) tabLabels.push(s);
}

for (const tab of tabLabels) {
    current = `${leasePath} → [${tab}]`;
    const before = findings.length;
    process.stdout.write(`  ${tab.padEnd(42).slice(0, 42)}`);

    await page.goto(`${BASE}${leasePath}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await page.locator('[role="tab"], .fi-tabs button, .fi-tabs a').filter({ hasText: tab }).first()
        .click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(1400);
    await check(current);

    // The actions that live INSIDE the tab — a relation manager's own header actions.
    const inTab = await page.locator('.fi-ta-header-ctn button, .fi-resource-relation-manager button').all();
    const seen = [];
    for (const b of inTab.slice(0, 8)) {
        const t = (await b.innerText().catch(() => '')).trim();
        if (! t || seen.includes(t) || /^\d+$/.test(t)) continue;
        seen.push(t);
        await b.click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(900);
        await check(`${current} → ${t}`);
        await page.keyboard.press('Escape').catch(() => {});
        await page.waitForTimeout(300);
    }

    console.log(findings.length > before ? `✗ ${findings.length - before}` : `ok  (${seen.length} action${seen.length === 1 ? '' : 's'})`);
}

// ── PASS D · the SAME lease screen in every STATE ────────────────────────────────────────────────
//
// All 33 demo leases are `active`, so five of the seven states ValueSets allows had never been
// opened in a browser — and three lease actions gate on state (finalAccount needs terminated or
// expired, convertToHoldover needs a term that has run out, releaseRentableItem needs a holding).
// A sweep over the demo data reports "clean" having never mounted them.
//
// `php artisan atriom:seed-lease-states` puts one disposable lease in each state; --drop removes
// them. Skipped silently when they are absent, so the sweep still runs on a bare database.
console.log('\n── lease states ──');

// BY ID, from the map the command writes. This used to search `?tableSearch=SWEEP-…`, which does
// not bind Filament's table state from a plain URL: every search returned the whole list and the
// sweep opened the SAME active lease seven times, reporting seven "ok"s about one record.
let stateSkipReason = null;
const stateActionCounts = [];
const stateLeases = [];
try {
    const map = JSON.parse(readFileSync('storage/app/private/sweep-lease-states.json', 'utf8'));
    for (const [st, id] of Object.entries(map)) stateLeases.push([st, `/admin/AW/leases/${id}/edit`]);
} catch (e) {
    // REPORT the reason, never swallow it. A bare `catch {}` here hid a missing `readFileSync`
    // import: the ReferenceError was caught, the pass printed "no fixture", and the sweep exited
    // 0 having skipped its most valuable pass while looking like it had run.
    stateSkipReason = String(e).slice(0, 160);
}

if (! stateLeases.length) {
    console.log(`  (skipped: ${stateSkipReason ?? 'no SWEEP-* leases — run php artisan atriom:seed-lease-states'})`);
    // A skipped pass is a finding, not a pass. The sweep used to exit 0 here, reporting "clean"
    // over the one pass that reaches the state-gated screens.
    findings.push({ where: 'state pass', what: 'skipped', detail: stateSkipReason ?? 'fixture missing' });
}

for (const [st, href] of stateLeases) {
    current = `state:${st}`;
    const before = findings.length;
    process.stdout.write(`  ${st.padEnd(42)}`);

    await page.goto(href.startsWith('http') ? href : `${BASE}${href}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    await check(current);

    // Every header action this STATE offers — the ones the active-only sweep can never reach.
    const btns = await page.locator('.fi-header-ctn button, .fi-header button, header button').all();
    const seen = [];
    for (const b of btns) {
        const t = (await b.innerText().catch(() => '')).trim();
        if (! t || seen.includes(t) || /^\d+$/.test(t)) continue;
        seen.push(t);
    }

    for (const label of seen) {
        await page.goto(href.startsWith('http') ? href : `${BASE}${href}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await page.locator('button', { hasText: label }).first().click({ timeout: 4000 }).catch(() => {});
        await page.waitForTimeout(1000);
        await check(`${current} → ${label}`);
        await page.keyboard.press('Escape').catch(() => {});
    }

    stateActionCounts.push([st, seen.length]);
    console.log(findings.length > before ? `✗ ${findings.length - before}` : `ok  (${seen.length} action${seen.length === 1 ? '' : 's'})`);
}

// THE PASS MUST PROVE IT SAW DIFFERENT LEASES. Identical action counts across every state is the
// signature of the search bug this pass was written with: seven visits to one record. A draft and
// a terminated lease genuinely differ — Terminate and Renew are state-gated — so if they do not,
// either the navigation is wrong or the actions are not gated at all. Both are findings.
if (stateLeases.length > 1) {
    const distinct = new Set(stateActionCounts.map(([, n]) => n));
    if (distinct.size === 1) {
        findings.push({
            where: 'state pass',
            what: 'vacuous',
            detail: `every state offered the same ${[...distinct][0]} actions — either the sweep `
                + 'opened one lease seven times, or the header actions are not state-gated',
        });
    }
}

report();

function report() {
    browser.close();
    console.log('');
    if (! findings.length) {
        console.log('clean — nothing broke.');
        process.exit(0);
    }
    const hard = findings.filter((f) => f.what !== 'console');
    console.log(`${findings.length} finding(s) — ${hard.length} hard:\n`);
    for (const f of findings) console.log(`  [${f.what}] ${f.where}\n      ${f.detail}\n`);
    process.exit(hard.length ? 1 : 0);
}
