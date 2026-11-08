import { test, expect } from '@playwright/test';
import { signIn } from '../helpers';

test('E2E-09 employee cannot open HR, payroll or compliance administration', async ({ page }) => {
    await signIn(page, 'employee');
    for (const path of ['/admin/employees/create', '/admin/payroll', '/admin/compliance', '/admin/schedule', '/admin/leave']) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(403);
    }
});

test('E2E-10 foreign HR cannot view first company employee or payroll', async ({ browser }) => {
    const first = await browser.newPage();
    await signIn(first, 'hr');
    await first.goto('/employees');
    const employeeLink = first.getByRole('link', { name: /Fictional Employee/ }).first();
    const employeePath = await employeeLink.getAttribute('href');
    expect(employeePath).toBeTruthy();
    const foreign = await browser.newPage();
    await signIn(foreign, 'foreign-hr');
    const response = await foreign.goto(employeePath!);
    expect([403, 404]).toContain(response?.status());
    await foreign.goto('/admin/payroll');
    await expect(foreign.getByText('Fictional Employee')).toHaveCount(0);
    await first.close();
    await foreign.close();
});

test('E2E-07-01/08/09 fictional payroll and compliance remain gated', async ({ browser }) => {
    const page = await browser.newPage();
    await signIn(page, 'hr');
    await page.goto('/admin/payroll');
    await expect(page.getByRole('heading', { name: 'Payroll', exact: true })).toBeVisible();
    await expect(page.getByText(/require legal review/i).first()).toBeVisible();
    await page.goto('/admin/compliance');
    await expect(page.getByRole('heading', { name: /compliance/i })).toBeVisible();
    await page.locator('[name=effective_from]').first().fill('2027-01-01');
    await page.getByRole('button', { name: /Create/i }).click();
    await expect(page.getByRole('heading', { name: /draft/i })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Candidate rules and applicability' }).locator('..').locator('li')).toHaveCount(4);
    const packUrl = page.url();
    await page.getByRole('button', { name: 'Submit for legal review' }).click();
    await expect(page.getByRole('heading', { name: /ready_for_legal_review/i })).toBeVisible();
    const admin = await browser.newPage();
    await signIn(admin, 'admin');
    await admin.goto(packUrl);
    await admin.getByLabel('Qualified reviewer name').fill('Fictional Reviewer');
    await admin.getByLabel('Signed evidence reference').fill('fictional-evidence-reference');
    await admin.getByRole('button', { name: 'Record external review attestation' }).click();
    await expect(admin.getByRole('alert')).toContainText('Independent qualified review');
    await expect(admin.getByRole('heading', { name: /ready_for_legal_review/i })).toBeVisible();
    await page.close();
    await admin.close();
});
