// Run against synthetic fixtures on a verified --no-reload QA server only.
// Fixture builder: tests/browser/fixtures/part23-customer-tables.php.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';

const base = process.env.QA_BASE_URL;
assert.equal(base, 'http://127.0.0.1:8137', 'Use the separately verified Customer Tables QA server');
const out = 'outputs/part23-customer-tables';
await fs.mkdir(out, { recursive: true });
const browser = await chromium.launch({ headless: true });
const results = [], errors = [];
async function run(name, task) {
    try { await task(); results.push({ name, pass: true }); }
    catch (error) { results.push({ name, pass: false, error: error.message }); }
}
try {
    for (const [role, prefix] of [['admin', 'admin'], ['sales_officer', 'sales-officer'], ['inventory_officer', 'inventory-officer']]) {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        const goto = async path => { const response = await page.goto(base + path, { waitUntil: 'load' }); assert.equal(response.status(), 200, path); };
        await goto('/login');
        await page.locator('#username').fill(`customer-qa-${role}@example.test`);
        await page.locator('#password').fill('Customer-QA-Only!');
        await Promise.all([page.waitForNavigation(), page.locator('.login-submit').click()]);
        if (role !== 'inventory_officer') {
            await run(`${role}: create and edit optional contact name`, async () => {
                await goto(`/${prefix}/sales/customers`);
                await page.locator('[data-modal-open="so-customer-add"]').click();
                assert.equal(await page.locator('#customer_name').getAttribute('required'), null);
                const company = `Browser Blank ${role} ${Date.now()}`;
                await page.locator('#customer_company_name').fill(company);
                await page.locator('button[form="customer-add-form"][type="submit"]').click();
                await Promise.all([page.waitForNavigation(), page.locator('[data-cd-ok]').click()]);
                const panel = page.locator('[data-tab-panel="customers"]');
                let row = panel.locator('tbody tr').filter({ hasText: company });
                assert.equal((await row.locator('td').nth(1).textContent()).trim(), '—');
                const code = (await row.locator('td').nth(0).textContent()).trim();
                assert.ok(code.startsWith('CSM-'));
                await row.getByRole('button', { name: 'Edit', exact: true }).click();
                let input = page.locator('input[id^="customer_name_"]:visible');
                assert.equal(await input.inputValue(), '');
                assert.equal(await input.getAttribute('required'), null);
                await input.fill('Temporary QA contact');
                let form = await input.getAttribute('form');
                await page.locator(`button[form="${form}"][type="submit"]`).click();
                await Promise.all([page.waitForNavigation(), page.locator('[data-cd-ok]').click()]);
                row = panel.locator('tbody tr').filter({ hasText: company });
                assert.equal((await row.locator('td').nth(1).textContent()).trim(), 'Temporary QA contact');
                await row.getByRole('button', { name: 'Edit', exact: true }).click();
                input = page.locator('input[id^="customer_name_"]:visible');
                await input.fill(''); form = await input.getAttribute('form');
                await page.locator(`button[form="${form}"][type="submit"]`).click();
                await Promise.all([page.waitForNavigation(), page.locator('[data-cd-ok]').click()]);
                await goto(`/${prefix}/sales/customers?search=${encodeURIComponent(code)}`);
                assert.equal((await panel.locator('tbody tr td').nth(1).textContent()).trim(), '—');
                assert.ok((await panel.textContent()).includes(company));
            });
        }
        const modules = role === 'inventory_officer'
            ? [['stock-out', `/${prefix}/inventory/stock-out`, 'stock_out_page', 'code', 'CUSTOMER-QA-SALE-']]
            : [['customers', `/${prefix}/sales/customers`, 'customers_page', 'company', 'CUSTOMER-QA-'], ['receivables', `/${prefix}/sales`, 'sales_page', 'company', 'CUSTOMER-QA-SALE-'], ...(role === 'admin' ? [['stock-out', '/admin/inventory/stock-out', 'stock_out_page', 'code', 'CUSTOMER-QA-SALE-']] : [])];
        for (const [tab, path, param, sort, idPrefix] of modules) {
            const search = tab === 'stock-out' ? 'CUSTOMER-QA-SALE-' : 'Customer QA Company';
            const filter = tab === 'customers' ? '&status=active&location=Lipa' : tab === 'receivables' ? '&delivery_status=delivered' : '&delivery_type=standard';
            const url = `${path}?search=${encodeURIComponent(search)}&per_page=10&sort=${sort}&direction=asc${filter}`;
            const panel = page.locator(`[data-tab-panel="${tab}"]`);
            await run(`${role}: ${tab} headers, search, filtering, pagination, sorting and full export`, async () => {
                await goto(url);
                const headers = await panel.locator('thead th').allTextContents();
                assert.equal(headers.some(h => h.trim() === 'Customer Name'), false);
                assert.ok(headers.includes('Company Name'));
                if (tab === 'customers') assert.ok(headers.includes('Name'));
                assert.equal(await panel.locator('tbody > tr').count(), 10);
                const aligned = await panel.locator('table').first().evaluate(table => [...table.tBodies[0].rows].every(row => row.cells.length === table.tHead.rows[0].cells.length));
                assert.ok(aligned, 'Header and data cells align');
                const keys = await panel.locator('tbody tr td:first-child').allTextContents();
                await Promise.all([page.waitForNavigation(), panel.locator('[data-table-pagination] a[rel="next"]').click()]);
                assert.equal(new URL(page.url()).searchParams.get(param), '2');
                assert.equal(new URL(page.url()).searchParams.get('sort'), sort);
                assert.equal(new URL(page.url()).searchParams.get('search'), search);
                assert.equal(await panel.locator('tbody > tr').count(), 10);
                const nextKeys = await panel.locator('tbody tr td:first-child').allTextContents();
                assert.ok(!keys.some(k => nextKeys.includes(k)));
                await Promise.all([page.waitForNavigation(), panel.locator('select[name="direction"]').selectOption('desc')]);
                assert.equal(new URL(page.url()).searchParams.get('direction'), 'desc');
                const descending = await panel.locator('tbody tr td:first-child').allTextContents();
                assert.notDeepEqual(descending, keys);
                const promise = page.waitForEvent('download');
                await page.locator('[data-export-table]').click();
                const download = await promise;
                const file = `${out}/${role}-${tab}.csv`;
                await download.saveAs(file);
                const csv = await fs.readFile(file, 'utf8');
                const lines = csv.trimEnd().split('\n');
                assert.equal(lines.length - 5, 24, 'Export includes all filtered records');
                assert.ok(lines[4].includes('"Company Name"'));
                assert.ok(!lines[4].includes('"Customer Name"'));
                if (tab === 'customers') assert.ok(lines[4].includes('"Name"'));
                assert.ok(lines.slice(5).every(line => line.includes(idPrefix)));
                assert.ok(lines.slice(5).every(line => (line.match(/","/g) || []).length === (lines[4].match(/","/g) || []).length), 'CSV column alignment');
                await goto(path + '?search=no-such-customer-qa-record');
                assert.equal(await panel.locator('.empty-cell').count(), 1);
            });
            for (const [width, height, size] of [[1440, 1000, 'desktop'], [390, 844, 'mobile']]) {
                await run(`${role}: ${tab} ${size} layout`, async () => {
                    await page.setViewportSize({ width, height }); await goto(url);
                    assert.ok(await panel.isVisible());
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'No page overflow');
                    const first = panel.locator('tbody tr').first();
                    assert.ok((await first.textContent()).includes('Customer QA Company'));
                    if (size === 'mobile') {
                        assert.ok(await first.locator('.card-primary').isVisible());
                        await first.locator('.card-primary').click();
                        assert.equal(await first.getAttribute('aria-expanded'), 'true');
                    }
                    await page.screenshot({ path: `${out}/${role}-${tab}-${size}.png`, fullPage: true });
                });
            }
            await page.setViewportSize({ width: 1440, height: 1000 });
        }
        if (role === 'inventory_officer') await run('Inventory Officer cannot manage customers or access sales', async () => { assert.equal((await page.goto(base + '/sales-officer/sales/customers')).status(), 403); });
        await context.close();
    }
} finally { await browser.close(); }
await fs.writeFile(`${out}/browser-results.json`, JSON.stringify({ results, javascriptErrors: errors }, null, 2));
console.log(JSON.stringify({ checks: results.length, passed: results.filter(r => r.pass).length, failures: results.filter(r => !r.pass), javascriptErrors: errors }, null, 2));
if (results.some(r => !r.pass) || errors.length) process.exitCode = 1;
