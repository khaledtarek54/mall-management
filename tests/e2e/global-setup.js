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

export default async function globalSetup() {
  fs.mkdirSync('storage/playwright-state', { recursive: true });

  const browser = await chromium.launch();
  try {
    await loginAndSave(browser, loginAdmin, 'storage/playwright-state/admin.json', 'admin');
    await loginAndSave(browser, loginPortal, 'storage/playwright-state/portal.json', 'portal');
    await loginAndSave(browser, loginOwner, 'storage/playwright-state/owner.json', 'owner');
  } finally {
    await browser.close();
  }
}
