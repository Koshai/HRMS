import { test, expect } from '@playwright/test';
import { signIn } from '../helpers';

test('E2E-12 mobile employee can reach attendance, leave and guide', async ({ page }) => {
    await signIn(page, 'employee');
    await page.goto('/attendance/mine');
    await expect(page.getByRole('heading', { name: 'My Attendance' })).toBeVisible();
    await page.goto('/leave/mine');
    await expect(page.getByRole('heading', { name: 'My leave' })).toBeVisible();
    await page.goto('/guide');
    await expect(page.getByRole('main')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
    expect(overflow).toBe(false);
});

test('E2E-11 keyboard login and accessible primary heading', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').focus();
    await page.keyboard.type('employee@hrms-e2e.example.invalid');
    await page.keyboard.press('Tab');
    await page.keyboard.type(process.env.E2E_PASSWORD!);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});
