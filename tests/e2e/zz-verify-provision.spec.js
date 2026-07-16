import { test, expect } from '@playwright/test';
import { loginAdmin } from './helpers.js';

const SHOT = '/private/tmp/claude-501/-Users-khaled-Herd-mall-management/7c9cb1ea-e30c-445f-852a-110c415dd14f/scratchpad';

// Pick an account in the Nth line of the repeater by typing its code into the search box.
async function pickAccount(page, lineIndex, code) {
  const trigger = page.locator('.fi-fo-repeater-item .fi-fo-select, .fi-fo-repeater-item [role="combobox"]').nth(lineIndex);
  await trigger.click();
  const search = page.locator('input[type="search"]:visible, .fi-dropdown-panel input:visible').first();
  await search.fill(code);
  await page.waitForTimeout(1200); // debounce
  const option = page.locator(`.fi-dropdown-list-item:has-text("${code}"), [role="option"]:has-text("${code}")`).first();
  await option.click();
  await page.waitForTimeout(400);
}

test('provision JE posts via the UI and lands in OPERATING on the cash-flow report', async ({ page }) => {
  test.setTimeout(240000);
  await loginAdmin(page);
  const tenant = new URL(page.url()).pathname.split('/')[2];

  await page.goto(`/admin/${tenant}/journal-entries/create`);
  await page.waitForLoadState('networkidle');

  await page.locator('input[wire\\:model="data.description_en"]').fill('EOS provision — verify');
  await page.locator('input[wire\\:model="data.description_ar"]').fill('مخصص ترك الخدمة');

  // Line 1: Dr Salaries & Wages 3000
  await pickAccount(page, 0, '51101001');
  await page.locator('input[wire\\:model*="debit"]').first().fill('3000');

  // Line 2: Cr Provision — End of Service 3000  (the new 222… account)
  await pickAccount(page, 1, '22201001');
  await page.locator('input[wire\\:model*="credit"]').nth(1).fill('3000');

  await page.locator('body').click();
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${SHOT}/03-je-filled.png`, fullPage: true });

  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${SHOT}/04-je-created.png`, fullPage: true });
  console.log('URL AFTER CREATE:', page.url());

  // Post the draft to the ledger (reports count posted/void, not drafts).
  const postBtn = page.getByRole('button', { name: /^Post/ }).first();
  if (await postBtn.count()) {
    await postBtn.click();
    await page.waitForTimeout(1000);
    const confirm = page.locator('.fi-modal button:has-text("Post"), .fi-modal button:has-text("Confirm")').first();
    if (await confirm.count()) { await confirm.click(); await page.waitForTimeout(1500); }
    await page.screenshot({ path: `${SHOT}/05-je-posted.png`, fullPage: true });
  }

  // ---- The surface under test: the Cash Flow report.
  await page.goto(`/admin/${tenant}/cash-flow`);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${SHOT}/06-cash-flow.png`, fullPage: true });

  const body = await page.locator('body').innerText();
  console.log('=== PROVISION ROW PRESENT:', body.includes('Provision'), '===');
  const i = body.indexOf('Operating');
  console.log(body.slice(i, i + 1800));
});
