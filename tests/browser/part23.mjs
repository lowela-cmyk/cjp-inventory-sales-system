// Requires the synthetic cjp_part23_1116_qa fixtures and a verified --no-reload server on port 8123.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
const base = 'http://127.0.0.1:8123';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
const runTag = Date.now().toString();
page.on('pageerror', e => errors.push(e.message));
const goto = async path => {
    const response = await page.goto(base + path);
    assert.equal(response.status(), 200, path);
    await page.waitForLoadState('networkidle');
    if (path === '/admin/dashboard') await page.waitForTimeout(1100);
};
const confirm = async () => {
    await page.locator('[data-cd-ok]').click();
    await page.waitForLoadState('networkidle');
};
try {
    await goto('/login');
    await page.locator('#username').fill('part23-qa@example.test');
    await page.locator('#password').fill('Part23-QA-Only!');
    await page.locator('.login-submit').click();
    await page.waitForURL('**/admin/dashboard');
    assert.equal(await page.locator('[data-dashboard-export]').count(), 1); // Part 23 #11: Export is back
    assert.equal(await page.locator('[data-revenue-period]').count(), 0);
    assert.ok(await page.getByRole('heading', { name: 'Purchase Reports', exact: true }).isVisible());
    await goto('/admin/sales/customers?search=QA%20Company&per_page=10');
    const panel = page.locator('[data-tab-panel="customers"]');
    assert.equal(await panel.locator('tbody tr').count(), 10);
    assert.equal(await page.locator('input[name="email"]').count(), 0);
    const downloadPromise = page.waitForEvent('download');
    await page.locator('[data-export-table]').click();
    const download = await downloadPromise;
    await download.saveAs('outputs/part23/customers-export.csv');
    const csv = await fs.readFile('outputs/part23/customers-export.csv', 'utf8');
    assert.equal((csv.match(/"QA23-\d+"/g) || []).length, 115, 'Export includes every filtered page');
    await panel.getByRole('link', { name: 'Next', exact: true }).click();
    await page.waitForURL('**customers_page=2**');
    assert.equal(await panel.locator('tbody tr').count(), 10);
    await panel.locator('select[name="payment_status"]').selectOption('pending');
    await page.waitForURL(url => url.searchParams.get('payment_status') === 'pending' && !url.searchParams.has('customers_page'));
    assert.ok((await panel.locator('[data-table-pagination]').textContent()).includes('58 records'));
    await panel.locator('[data-page-size-form] select').selectOption('50');
    await page.waitForURL(url => url.searchParams.get('per_page') === '50');
    assert.equal(await panel.locator('tbody tr').count(), 50);
    await goto('/admin/sales/customers?per_page=10');
    await page.locator('[data-modal-open="so-customer-add"]').click();
    await page.locator('#customer_name').fill('Browser QA Contact');
    await page.locator('#customer_company_name').fill('Browser QA Company ' + runTag);
    await page.locator('button[form="customer-add-form"][type="submit"]').click();
    await confirm();
    assert.ok((await page.locator('body').textContent()).includes('Customer record created successfully.'));
    await page.reload();
    const row = panel.locator('tbody tr').filter({ hasText: 'Browser QA Company ' + runTag });
    await row.getByRole('button', { name: 'Edit', exact: true }).click();
    const editName = page.locator('input[id^="customer_name_"]:visible');
    await editName.fill('Browser QA Updated');
    const formId = await editName.getAttribute('form');
    await page.locator(`button[form="${formId}"][type="submit"]`).click();
    await confirm();
    await page.reload();
    assert.ok((await panel.textContent()).includes('Browser QA Updated'));
    for (const [width, height, label] of [[1440, 1000, 'desktop'], [820, 1180, 'tablet'], [390, 844, 'mobile']]) {
        await page.setViewportSize({ width, height });
        for (const path of ['/admin/sales/customers?per_page=10', '/admin/inventory?per_page=10', '/admin/dashboard', '/admin/imports']) {
            await goto(path);
            await page.screenshot({ path: `outputs/part23/${label}-${path.split('/')[2].split('?')[0]}.png`, fullPage: true });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2), `${label} overflow at ${path}`);
        }
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await goto('/admin/imports');
    await page.locator('input[type="file"]').setInputFiles({ name: 'customers-browser.csv', mimeType: 'text/csv', buffer: Buffer.from('Customer ID,Name,Company Name,Location,Status\nQA23-IMPORTED,Imported QA,Imported Browser Company,QA Lipa,active\n') });
    await page.locator('select[name="dataset_kind"]').selectOption('mock_demo');
    await page.getByRole('button', { name: 'Analyze File', exact: true }).click();
    await page.waitForURL('**/preview');
    assert.ok((await page.locator('body').textContent()).includes('Confirm Import'));
    await page.locator('[data-import-submit]').click();
    await confirm();
    await page.waitForURL('**/admin/imports');
    await goto('/admin/sales/customers?search=Imported%20Browser%20Company');
    assert.equal(await panel.locator('tbody tr').count(), 1);
    assert.ok((await panel.textContent()).includes('Imported Browser Company'));
    assert.deepEqual(errors, []);
    console.log('PASS: login, dashboard controls/chart, pagination/filter/reset, full export, customer add/edit/reload, CSV preview/import, desktop/tablet/mobile overflow, no JavaScript errors.');
} finally { await browser.close(); }
