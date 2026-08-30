import { chromium } from '@playwright/test';
import { loginAdmin, loginOwner, loginPortal } from './helpers.js';
import fs from 'fs';

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://mall-management.test';

/*
 * The admin panel throttles login at 5 attempts / 60s per IP (Filament's
 * WithRateLimiting, in the Livewire component). A cold start needs up to 10
 * logins — admin, portal, owner, plus 7 roles — so they cannot all be fired
 * back-to-back: the 6th onwards get throttled, and the failure surfaces as an
 * opaque "waitForFunction: Timeout" rather than as "you were rate limited".
 */
const THROTTLE_LIMIT = 4; // stay under 5
const THROTTLE_WINDOW_MS = 65_000;
let loginsThisWindow = 0;

async function respectLoginThrottle() {
  if (loginsThisWindow < THROTTLE_LIMIT) return;

  console.log(`[globalSetup] pausing ${THROTTLE_WINDOW_MS / 1000}s — login throttle window`);
  await new Promise((r) => setTimeout(r, THROTTLE_WINDOW_MS));
  loginsThisWindow = 0;
}

async function loginAndSave(browser, loginFn, statePath, label, account) {
  await respectLoginThrottle();

  // Retry up to 3 times to ride out cold-start races (Filament + Livewire first paint)
  let lastErr;
  let lastUrl = '(never navigated)';
  let sawLoginForm = false;

  for (let attempt = 1; attempt <= 3; attempt++) {
    const ctx = await browser.newContext({ baseURL });
    const page = await ctx.newPage();
    try {
      loginsThisWindow++;
      await loginFn(page);
      await ctx.storageState({ path: statePath });
      await ctx.close();
      return;
    } catch (e) {
      lastErr = e;
      // Captured BEFORE the context closes — it is the whole difference between "the box is
      // slow" and "that account does not exist here", and the raw timeout says neither.
      lastUrl = page.url();
      sawLoginForm = await page
        .locator('input[type="password"]')
        .first()
        .isVisible({ timeout: 1000 })
        .catch(() => false);
      console.warn(`[globalSetup] ${label} login attempt ${attempt} failed: ${e.message}`);
      await ctx.close().catch(() => {});
      // A retry inside the same window is another attempt against the throttle.
      await respectLoginThrottle();
    }
  }

  // ── SAY WHAT ACTUALLY WENT WRONG ────────────────────────────────────────────────────────────
  //
  // Global setup throwing takes the WHOLE suite with it and reports zero tests, which reads as
  // "nobody ran it" rather than as a failure — this project lost a month of browser coverage to
  // exactly that, when a renamed demo account left `page.waitForFunction: Timeout` as the only
  // symptom and it was read three times as a slow page.
  //
  // `E2eHarnessUsersExistTest` guards the harness against the SEEDED test database. It cannot see
  // the database this run points at, so a dev box mid-experiment — restored from `qa:baseline`,
  // part-way through a seeder, sharing a tree with another session — fails here and only here.
  const stillOnLogin = sawLoginForm || /\/login/.test(lastUrl);
  throw new Error(
    `[globalSetup] could not sign ${label} in as ${account ?? '(unknown account)'} after 3 attempts.\n` +
      `  last URL: ${lastUrl}\n` +
      (stillOnLogin
        ? `  The login form was still on screen, so the page loaded and the credentials were refused.\n` +
          `  Does ${account} exist in the database ${baseURL} is pointed at? Check, and reseed with\n` +
          `  \`php artisan migrate:fresh --seed\` if that box is meant to hold the demo data.\n`
        : `  The panel never rendered, so this looks like the app rather than the account.\n`) +
      `  underlying: ${lastErr?.message ?? lastErr}`,
  );
}

/**
 * Is a saved session still good?
 *
 * Existence is not validity. `php artisan migrate:fresh --seed` recreates every
 * user, which invalidates every saved session while leaving the JSON files on
 * disk looking perfectly fine. The old setup skipped any role whose file existed,
 * so after a reseed the RBAC spec failed for all seven roles — and its failure
 * reads as "this role reached a page it should not have", i.e. exactly like a
 * genuine authorization regression. That cost two debugging detours before anyone
 * noticed the sessions were simply dead.
 */
async function isSessionValid(browser, statePath, probePath) {
  if (!fs.existsSync(statePath)) return false;

  const ctx = await browser.newContext({ baseURL, storageState: statePath });
  const page = await ctx.newPage();

  try {
    // Probed on its OWN panel: a portal session checked against /admin would look
    // stale on every run and buy a pointless login (and a throttle slot) each time.
    await page.goto(probePath, { waitUntil: 'domcontentloaded', timeout: 15_000 });

    return !page.url().includes('/login');
  } catch {
    return false; // unreachable or timed out — treat as stale and re-login
  } finally {
    await ctx.close().catch(() => {});
  }
}

async function ensureState(browser, loginFn, statePath, label, probePath = '/admin', account = undefined) {
  if (await isSessionValid(browser, statePath, probePath)) return;

  if (fs.existsSync(statePath)) {
    console.log(`[globalSetup] ${label}: saved session is stale (reseed?) — logging in again`);
  }

  await loginAndSave(browser, loginFn, statePath, label, account);
}

// Non-admin RBAC roles — each is a real demo user. Auth states power the
// per-role access-matrix spec (21-rbac-smoke). All log in at /admin.
export const ROLE_USERS = {
  manager: 'manager@mall.test',
  viewer: 'viewer@mall.test',
  leasing: 'leasing@mall.test',
  // `operations@mall.test`, not `maintenance@mall.test`: the 2026-08-15 rename swept `app/` and
  // left this behind. The user has not existed since, so global setup died here and took the
  // WHOLE suite with it — see `E2eHarnessUsersExistTest`, which now fails fast and by name.
  operations: 'operations@mall.test',
  accounting: 'accounting@mall.test',
  marketing: 'marketing@mall.test',
  hr: 'hr@mall.test',
};

export default async function globalSetup() {
  fs.mkdirSync('storage/playwright-state', { recursive: true });

  const browser = await chromium.launch();
  try {
    await ensureState(browser, loginAdmin, 'storage/playwright-state/admin.json', 'admin', '/admin', 'admin@mall.test');
    await ensureState(browser, loginPortal, 'storage/playwright-state/portal.json', 'portal', '/portal', 'tenant1@atriomwalk.test');
    await ensureState(browser, loginOwner, 'storage/playwright-state/owner.json', 'owner', '/admin', 'owner@atriom.test');

    // Each role's saved session is REUSED while it still authenticates, and
    // rebuilt when it does not — so a reseed costs one extra round of logins
    // instead of seven confusing failures.
    for (const [role, email] of Object.entries(ROLE_USERS)) {
      await ensureState(
        browser,
        (page) => loginAdmin(page, email),
        `storage/playwright-state/role-${role}.json`,
        `role:${role}`,
        '/admin',
        // Threaded through so the failure names the account. Without it the diagnostic reads
        // "could not sign role:operations in as undefined", which is the same dead end as the
        // raw timeout it replaced — and this loop is exactly where the 2026-08 outage happened.
        email,
      );
    }
  } finally {
    await browser.close();
  }
}
