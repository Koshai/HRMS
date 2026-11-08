import { test, expect } from '@playwright/test';
import { signIn } from '../helpers';

test('E2E-06-01/02/04 fictional BDT pay run stays in draft', async ({ page }) => {
    await signIn(page, 'hr');
    await page.goto('/admin/payroll');
    const policy = page.getByRole('heading', { name: 'Payroll policy version' }).locator('..');
    await policy.locator('[name=effective_from]').fill('2020-01-01');
    await policy.getByLabel('Reason').fill('Fictional browser pay policy');
    await policy.getByRole('button', { name: 'Save policy' }).click();
    await expect(page.getByText(/2020-01-01.*BDT.*calendar_days/)).toBeVisible();

    await page.getByRole('link', { name: /Fictional Employee · E2E-EMPLOYEE/ }).click();
    await page.getByLabel('Effective from').fill('2020-01-01');
    await page.getByLabel('Monthly base (poisha)').fill('1000000');
    await page.getByLabel('Reason').fill('Fictional browser compensation');
    await page.getByRole('button', { name: 'Save version' }).click();
    await expect(page.getByText(/BDT 10,000.00 base/)).toBeVisible();

    await page.getByRole('main').getByRole('link', { name: 'Payroll', exact: true }).click();
    const month = new Date();
    month.setUTCDate(1);
    month.setUTCMonth(month.getUTCMonth() - 1);
    await page.getByLabel('Month', { exact: true }).fill(month.toISOString().slice(0, 7));
    await page.getByRole('checkbox', { name: 'Fictional Employee' }).check();
    await page.getByRole('button', { name: 'Create run' }).click();
    await expect(page.getByRole('heading', { name: /draft/ })).toBeVisible();
    await page.getByRole('button', { name: 'Calculate / recalculate draft' }).click();
    await expect(page.getByRole('heading', { name: /Fictional Employee.*Net/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /draft/ })).toBeVisible();
});
