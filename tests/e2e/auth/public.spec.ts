import { test, expect } from '@playwright/test';
import { signIn } from '../helpers';

test('E2E-01-01/02/04 guest access and closed registration', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByLabel('Email address')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Forgot your password?' })).toBeVisible();
    await page.goto('/register');
    await expect(page.getByText('404', { exact: true }).first()).toBeVisible();
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('E2E-01-05/08 employee sign-in and logout', async ({ page }) => {
    await signIn(page, 'employee');
    await expect(page.getByRole('heading', { name: /Dashboard/i })).toBeVisible();
    await page.locator('form[action$="/logout"]').first().evaluate((form: HTMLFormElement) => form.submit());
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('E2E-01-06 invalid password stays signed out', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('employee@hrms-e2e.example.invalid');
    await page.getByLabel('Password', { exact: true }).fill('Wrong-Fictional-Password-2026!');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});
