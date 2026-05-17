import { chromium } from '@playwright/test';
import { loginAdmin, loginPortal } from './helpers.js';
import fs from 'fs';

export default async function globalSetup() {
  fs.mkdirSync('storage/playwright-state', { recursive: true });

  const browser = await chromium.launch();
  try {
    const adminCtx = await browser.newContext({ baseURL: 'http://mall-management.test' });
    const adminPage = await adminCtx.newPage();
    await loginAdmin(adminPage);
    await adminCtx.storageState({ path: 'storage/playwright-state/admin.json' });
    await adminCtx.close();

    const portalCtx = await browser.newContext({ baseURL: 'http://mall-management.test' });
    const portalPage = await portalCtx.newPage();
    await loginPortal(portalPage);
    await portalCtx.storageState({ path: 'storage/playwright-state/portal.json' });
    await portalCtx.close();
  } finally {
    await browser.close();
  }
}
