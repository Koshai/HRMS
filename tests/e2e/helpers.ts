import { expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

export const account = (role: string) => `${role}@hrms-e2e.example.invalid`;

export async function signIn(page: Page, role: string) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(account(role));
    await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_PASSWORD!);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

export async function latestMailLink(path: string, afterLength = 0): Promise<string> {
    const log = process.env.E2E_MAIL_LOG!;
    for (let attempt = 0; attempt < 40; attempt++) {
        let body = '';
        try { body = readFileSync(log, 'utf8').slice(afterLength); } catch {}
        const normalized = body.replace(/=\r?\n/g, '').replace(/&amp;/g, '&');
        const urls = normalized.match(/https?:\/\/127\.0\.0\.1:\d+\/[^\s<>"']+/g) ?? [];
        const link = urls.findLast((url) => url.includes(path));
        if (link) return link.replace(/[).,;]+$/, '');
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    throw new Error(`Captured test email did not contain the expected ${path} link.`);
}
