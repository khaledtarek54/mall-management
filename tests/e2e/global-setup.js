import { chromium } from '@playwright/test';
import { loginAdmin, loginOwner, loginPortal } from './helpers.js';
import fs from 'fs';

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://mall-management.test';

async function loginAndSave(browser, loginFn, statePath, label) {
  // Retry up to 3 times to ride out cold-start races (Filament + Livewire first paint)
  let lastErr;
  for (let attempt = 1; attempt <= 3; attempt++) {
    const ctx = await browser.newContext({ baseURL });
    const page = await ctx.newPage();
    try {
      await loginFn(page);
      await ctx.storageState({ path: statePath });
      await ctx.close();
      return;
    } catch (e) {
      lastErr = e;
      console.warn(`[globalSetup] ${label} login attempt ${attempt} failed: ${e.message}`);
      await ctx.close().catch(() => {});
    }
  }
  throw lastErr;
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
    await loginAndSave(browser, loginAdmin, 'storage/playwright-state/admin.json', 'admin');
    await loginAndSave(browser, loginPortal, 'storage/playwright-state/portal.json', 'portal');
    await loginAndSave(browser, loginOwner, 'storage/playwright-state/owner.json', 'owner');

    // Role states change rarely (stable demo users) — only (re)create a role's
    // state if it's missing, so the common run doesn't pay 7 extra logins.
    // Delete storage/playwright-state/role-*.json to force a refresh.
    for (const [role, email] of Object.entries(ROLE_USERS)) {
      const statePath = `storage/playwright-state/role-${role}.json`;
      if (fs.existsSync(statePath)) continue;
      await loginAndSave(browser, (page) => loginAdmin(page, email), statePath, `role:${role}`);
    }
  } finally {
    await browser.close();
  }
}
