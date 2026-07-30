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

async function loginAndSave(browser, loginFn, statePath, label) {
  await respectLoginThrottle();

  // Retry up to 3 times to ride out cold-start races (Filament + Livewire first paint)
  let lastErr;
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
      console.warn(`[globalSetup] ${label} login attempt ${attempt} failed: ${e.message}`);
      await ctx.close().catch(() => {});
      // A retry inside the same window is another attempt against the throttle.
      await respectLoginThrottle();
    }
  }
  throw lastErr;
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

async function ensureState(browser, loginFn, statePath, label, probePath = '/admin') {
  if (await isSessionValid(browser, statePath, probePath)) return;

  if (fs.existsSync(statePath)) {
    console.log(`[globalSetup] ${label}: saved session is stale (reseed?) — logging in again`);
  }

  await loginAndSave(browser, loginFn, statePath, label);
}

// Non-admin RBAC roles — each is a real demo user. Auth states power the
// per-role access-matrix spec (21-rbac-smoke). All log in at /admin.
export const ROLE_USERS = {
  manager: 'manager@mall.test',
  viewer: 'viewer@mall.test',
  leasing: 'leasing@mall.test',
  operations: 'maintenance@mall.test',
  accounting: 'accounting@mall.test',
  marketing: 'marketing@mall.test',
  hr: 'hr@mall.test',
};

export default async function globalSetup() {
  fs.mkdirSync('storage/playwright-state', { recursive: true });

  const browser = await chromium.launch();
  try {
    await ensureState(browser, loginAdmin, 'storage/playwright-state/admin.json', 'admin');
    await ensureState(browser, loginPortal, 'storage/playwright-state/portal.json', 'portal', '/portal');
    await ensureState(browser, loginOwner, 'storage/playwright-state/owner.json', 'owner');

    // Each role's saved session is REUSED while it still authenticates, and
    // rebuilt when it does not — so a reseed costs one extra round of logins
    // instead of seven confusing failures.
    for (const [role, email] of Object.entries(ROLE_USERS)) {
      await ensureState(
        browser,
        (page) => loginAdmin(page, email),
        `storage/playwright-state/role-${role}.json`,
        `role:${role}`,
      );
    }
  } finally {
    await browser.close();
  }
}
