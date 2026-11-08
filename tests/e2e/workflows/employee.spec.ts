import { test, expect } from '@playwright/test';
import { signIn } from '../helpers';

test('E2E-03 attendance punch, history and local time zone', async ({ page }) => {
    await signIn(page, 'employee');
    await page.goto('/attendance/mine');
    await expect(page.getByRole('heading', { name: 'My Attendance' })).toBeVisible();
    await page.getByRole('button', { name: 'Check in' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Attendance recorded' })).toBeVisible();
    await expect(page.getByText('Asia/Dhaka').first()).toBeVisible();
    await page.waitForTimeout(1200);
    await page.getByRole('button', { name: 'Check out' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Attendance recorded' })).toBeVisible();
    await expect(page.getByText(/Check-ins: 1/)).toBeVisible();
    await expect(page.getByText(/Check-outs: 1/)).toBeVisible();
});

test('E2E-04 employee and manager schedule visibility', async ({ browser }) => {
    const employee = await browser.newPage();
    await signIn(employee, 'employee');
    await employee.goto('/schedule/mine');
    await expect(employee.getByRole('heading', { name: /My Schedule/i })).toBeVisible();
    await expect(employee.getByText('E2E-EMPLOYEE')).toBeVisible();
    const manager = await browser.newPage();
    await signIn(manager, 'manager');
    await manager.goto('/schedule');
    await expect(manager.getByText('Fictional Employee').first()).toBeVisible();
    await employee.close();
    await manager.close();
});

test('E2E-05 employee requests fictional leave and manager approves', async ({ browser }) => {
    const leaveDate = new Date();
    leaveDate.setUTCDate(leaveDate.getUTCDate() + 7);
    while (leaveDate.getUTCDay() === 0 || leaveDate.getUTCDay() === 6) leaveDate.setUTCDate(leaveDate.getUTCDate() + 1);
    const day = leaveDate.toISOString().slice(0, 10);
    const employee = await browser.newPage();
    await signIn(employee, 'employee');
    await employee.goto('/leave/mine');
    await expect(employee.getByText('Fictional Annual Leave').first()).toBeVisible();
    await employee.locator('[name=starts_on]').fill(day);
    await employee.locator('[name=ends_on]').fill(day);
    await employee.locator('[name=reason]').first().fill('Fictional personal leave');
    await employee.getByRole('button', { name: 'Submit' }).last().click();
    await expect(employee.getByText(/Pending · 1/)).toBeVisible();
    const manager = await browser.newPage();
    await signIn(manager, 'manager');
    await manager.goto('/leave');
    await expect(manager.getByText('Fictional Employee · Fictional Annual Leave')).toBeVisible();
    await manager.getByPlaceholder('Reason').fill('Fictional manager approval');
    await manager.getByRole('button', { name: 'Approve' }).click();
    await employee.reload();
    await expect(employee.getByText(/Approved · 1/)).toBeVisible();
    await employee.close();
    await manager.close();
});
