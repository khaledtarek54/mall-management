import { test, expect } from '@playwright/test';
import { loginAdmin, loginPortal, expectNoLaravelError, captureConsoleErrors } from './helpers.js';

test.describe('Authentication', () => {
  test('admin login form renders and authenticates', async ({ page }) => {
    const errors = await captureConsoleErrors(page);
    await page.goto('/admin/login');
    await expectNoLaravelError(page);
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[wire\\:model="data.password"]')).toBeVisible();

    await loginAdmin(page);
    await expect(page).toHaveURL(/\/admin$/);
    expect(errors, 'No JS errors during login').toEqual([]);
  });

  test('portal login form renders and authenticates', async ({ page }) => {
    const errors = await captureConsoleErrors(page);
    await page.goto('/portal/login');
    await expectNoLaravelError(page);
    await loginPortal(page);
    await expect(page).toHaveURL(/\/portal$/);
    expect(errors, 'No JS errors during portal login').toEqual([]);
  });

  test('admin login rejects bad credentials', async ({ page }) => {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill('admin@mall.test');
    await page.locator('input[wire\\:model="data.password"]').fill('wrongpassword');
    await page.locator('button[type="submit"]').click();
    await page.waitForTimeout(2000);
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('protected admin routes redirect to login when unauthenticated', async ({ page }) => {
    await page.goto('/admin/invoices');
    await expect(page).toHaveURL(/\/admin\/login/);
  });

  test('protected portal routes redirect to login when unauthenticated', async ({ page }) => {
    await page.goto('/portal/invoices');
    await expect(page).toHaveURL(/\/portal\/login/);
  });
});
