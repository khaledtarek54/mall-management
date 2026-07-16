import { test } from '@playwright/test';
import { loginAdmin } from './helpers.js';

test('probe JE form DOM', async ({ page }) => {
  test.setTimeout(120000);
  await loginAdmin(page);
  const tenant = new URL(page.url()).pathname.split('/')[2];
  await page.goto(`/admin/${tenant}/journal-entries/create`);
  await page.waitForLoadState('networkidle');

  const info = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('input, select, button[role]').forEach(el => {
      const wm = [...el.attributes].find(a => a.name.startsWith('wire:model'));
      out.push({ tag: el.tagName, type: el.type || '', id: el.id || '', wire: wm ? `${wm.name}=${wm.value}` : '' });
    });
    return out;
  });
  console.log(JSON.stringify(info, null, 1));
});
