// Part 24 browser checks. Requires a verified --no-reload QA server on port 8124 (DB_DATABASE = a QA copy) seeded with
// temporary p24qa-* accounts and the purchases / lifts / garage order listed in QA_IDS (a JSON file path).
// Usage: QA_IDS=/path/qa_ids.json RECEIPT_PNG=/path/receipt.png node tests/browser/part24-fixes.mjs
import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const base = process.env.QA_BASE || 'http://127.0.0.1:8124';
const ids = JSON.parse(await fs.readFile(process.env.QA_IDS, 'utf8'));
const receiptPng = process.env.RECEIPT_PNG;
const password = 'Part24-QA-Only!';
const results = [];
const pageErrors = [];

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
const page = await context.newPage();
page.on('pageerror', (error) => pageErrors.push(error.message));

// ONLY="#1,#4" reruns some checks (the #2 / #3 flows change the QA data, so they run once per seed).
const only = (process.env.ONLY || '').split(',').map((x) => x.trim()).filter(Boolean);
const check = async (name, fn) => {
    if (only.length && !only.some((prefix) => name.startsWith(prefix))) return;
    try {
        const detail = await fn();
        results.push({ name, pass: detail.pass !== false, ...detail });
    } catch (error) {
        results.push({ name, pass: false, error: error.message.split('\n')[0] });
    }
};
const login = async (role) => {
    await context.clearCookies();
    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('#username').fill(`p24qa-${role}@example.test`);
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.login-submit').click()]);
};
const confirmDialog = async () => {
    const ok = page.locator('[data-confirm-dialog] [data-cd-ok]');
    await ok.waitFor({ state: 'visible', timeout: 5000 });
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), ok.click()]);
};
const code = (id) => ids.codes[String(id)];

try {
    // ----- Sales Officer: truck capacity, multi-purchase depot order, garage delivery dropdown -----------------------
    await login('sales-officer');
    await page.goto(`${base}/sales-officer/sales`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-modal-open="so-sales-add"]').click();
    const modal = page.locator('#so-sales-add');
    await modal.waitFor({ state: 'visible' });

    // Part 25 #1 superseded the Part 24 capacity figures: the truck shows its maximum capacity only. The browser blocks
    // an order above that capacity; the server still checks what is on the truck (Part25SalesModuleTest).
    await check('#1 truck shows max capacity only and blocks an order above it (Garage to Client)', async () => {
        await modal.locator('#sale_delivery_type').selectOption('garage_to_client');
        const truck = modal.locator('#sale_delivery_truck_id');
        const hint = modal.locator('#sale_delivery_truck_capacity');
        const smallLabel = (await truck.locator(`option[value="${ids.smallTruck}"]`).textContent()).trim();
        await modal.locator('#sale_quantity_liters_0').fill('1500');
        await truck.selectOption(String(ids.smallTruck));
        const overText = await hint.textContent();
        const overInvalid = await truck.evaluate((el) => !el.checkValidity());
        const overMessage = await truck.evaluate((el) => el.validationMessage);
        await modal.locator('#sale_quantity_liters_0').fill('1000');
        const fitsValid = await truck.evaluate((el) => el.checkValidity());
        await modal.locator('#sale_quantity_liters_0').fill('500');
        await truck.selectOption(String(ids.bigTruck));
        const bigText = await hint.textContent();
        const noFigures = (await modal.locator('[data-truck-capacity-summary], [data-truck-cap-loaded], [data-truck-cap-available], [data-truck-cap-remaining]').count()) === 0;
        return {
            pass: overInvalid && overText.includes('Maximum capacity: 1,000 L') && overMessage.includes('at most 1,000 L') && fitsValid
                && bigText.trim() === 'Maximum capacity: 30,000 L' && /– Max 1,000 L$/.test(smallLabel) && !/Loaded|available/i.test(smallLabel) && noFigures,
            smallLabel, overText, overMessage, bigText,
        };
    });

    await check('#3 add another purchase, duplicate blocked, multi-purchase depot order saved', async () => {
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        const addRowVisible = await modal.locator('[data-sale-add-line-row]').isVisible();
        const deliveryHidden = await modal.locator('[data-delivery-details]').isHidden();
        await modal.locator('#sale_customer_id').selectOption(String(ids.customer));
        await modal.locator('#sales_order_number').fill('SO-P24-MULTI');
        await modal.locator('#sale_quantity_liters_0').fill('3000');
        await modal.locator('#sale_purchase_item_id_0').selectOption(String(ids.dslA));
        await modal.locator('#sale_unit_price_0').fill('62');
        const firstLabel = await modal.locator(`#sale_purchase_item_id_0 option[value="${ids.dslA}"]`).textContent();
        await modal.locator('[data-add-sale-line]').click();
        const line = modal.locator('[data-sale-extra-line]').first();
        await line.locator('[data-sale-quantity]').fill('2000');
        await line.locator('[data-sale-purchase]').selectOption(String(ids.dslA));
        const duplicateMessage = await line.locator('[data-sale-purchase]').evaluate((el) => el.validationMessage);
        await line.locator('[data-sale-purchase]').selectOption(String(ids.dslB));
        const fixed = await line.locator('[data-sale-purchase]').evaluate((el) => el.checkValidity());
        await line.locator('[data-sale-price]').fill('62');
        await modal.locator('#sale_payment_method').selectOption('cash_on_delivery');
        await page.locator('button[form="record-sale-form"]').click();
        await confirmDialog();
        const toast = await page.locator('.cjp-toast-success, [role="status"]').first().textContent().catch(() => '');
        await page.goto(`${base}/sales-officer/sales?search=SO-P24-MULTI`, { waitUntil: 'domcontentloaded' });
        const rowText = await page.locator('[data-tab-panel="receivables"] tbody tr').first().textContent();
        return {
            pass: addRowVisible && deliveryHidden && firstLabel.includes('3,000 L in depot') && duplicateMessage.includes('already on line 1') && fixed
                && rowText.includes('SO-P24-MULTI') && rowText.replace(/\s+/g, ' ').includes('5,000'),
            firstLabel: firstLabel.trim(), duplicateMessage, toast: (toast || '').trim().slice(0, 120), row: rowText.replace(/\s+/g, ' ').trim().slice(0, 200),
        };
    });

    await check('#2 garage order delivery status dropdown persists (Out for Delivery, then Delivered)', async () => {
        const open = async () => {
            await page.goto(`${base}/sales-officer/sales?search=${ids.garageSaleCode}`, { waitUntil: 'domcontentloaded' });
            await page.locator(`[data-modal-open="so-sales-edit-${ids.garageSale}"]`).first().click();
            const saleModal = page.locator(`#so-sales-edit-${ids.garageSale}`);
            await saleModal.waitFor({ state: 'visible' });
            return saleModal;
        };
        let saleModal = await open();
        const select = saleModal.locator('[data-garage-delivery-select]');
        const options = await select.locator('option').allTextContents();
        const submit = saleModal.locator('[data-garage-delivery-submit]');
        const disabledAtStart = await submit.isDisabled();
        await select.selectOption('out_for_delivery');
        await submit.click();
        await confirmDialog();
        saleModal = await open();
        const afterFirst = await saleModal.locator('[data-garage-delivery-select]').inputValue();
        const badge1 = await page.locator('[data-tab-panel="receivables"] tbody tr').first().textContent();
        await saleModal.locator('[data-garage-delivery-select]').selectOption('delivered');
        await saleModal.locator('[data-garage-delivery-submit]').click();
        const warning = await page.locator('[data-confirm-dialog]').textContent();
        await confirmDialog();
        await page.goto(`${base}/sales-officer/sales?search=${ids.garageSaleCode}`, { waitUntil: 'domcontentloaded' });
        const badge2 = await page.locator('[data-tab-panel="receivables"] tbody tr').first().textContent();
        const dropdownGone = (await page.locator(`#so-sales-edit-${ids.garageSale} [data-garage-delivery-select]`).count()) === 0;
        return {
            pass: options.join('|') === 'Pending|Out for Delivery|Delivered' && disabledAtStart && afterFirst === 'out_for_delivery'
                && /Out For Delivery/i.test(badge1) && /Delivered/.test(badge2) && dropdownGone && warning.includes('garage tank'),
            options, afterFirst,
        };
    });

    // ----- Driver: receipt upload; Inventory: ledger view / download --------------------------------------------------
    await login('driver');
    await check('#4 driver uploads a lifting receipt and the lift shows it', async () => {
        await page.goto(`${base}/driver/fuel-lifting/hauled`, { waitUntil: 'domcontentloaded' });
        await page.locator(`button[data-modal-open="driver-haul-${ids.dslHaul}"]`).last().click();
        const driverModal = page.locator(`#driver-haul-${ids.dslHaul}`);
        await driverModal.waitFor({ state: 'visible' });
        await driverModal.locator('[data-withdrawal-upload] input[type="file"]').setInputFiles(receiptPng);
        await driverModal.locator('[data-upload-button]').click();
        const message = driverModal.locator('[data-upload-message]');
        // The upload is reviewed in the confirm dialog first, then sent in the background (no page load).
        await page.locator('[data-confirm-dialog] [data-cd-ok]').click({ timeout: 5000 }).catch(() => {});
        await page.waitForFunction((id) => document.querySelector(`#driver-haul-${id} [data-upload-message]`)?.classList.contains('is-success') || document.querySelector(`#driver-haul-${id} [data-upload-message]`)?.classList.contains('is-error'), ids.dslHaul, { timeout: 10000 });
        const text = await message.textContent();
        await page.goto(`${base}/driver/fuel-lifting/hauled`, { waitUntil: 'domcontentloaded' });
        const status = await page.locator(`[data-receipt-status="${ids.dslHaul}"]`).first().textContent();
        return { pass: text.includes('uploaded successfully') && status.trim() === 'Uploaded', message: text, status };
    });

    await login('inventory-officer');
    await check('#4 ledger shows the receipt with view and download', async () => {
        await page.goto(`${base}/inventory-officer/ledger/transactions?search=${code(ids.dslB)}`, { waitUntil: 'domcontentloaded' });
        const card = page.locator('.ledger-lift-card', { hasText: ids.dslHaulCode });
        const img = card.locator('img');
        const src = await img.getAttribute('src');
        const download = await card.locator('[data-receipt-download]').getAttribute('href');
        const view = await page.request.get(src);
        const file = await page.request.get(download);
        return {
            pass: view.status() === 200 && view.headers()['content-type'] === 'image/png' && file.status() === 200 && file.headers()['content-disposition'].startsWith('attachment;'),
            view: view.status(), disposition: file.headers()['content-disposition'],
        };
    });

    await check('#5 Stock-In: Sold on delivery only, Reserved and Available columns', async () => {
        await page.goto(`${base}/inventory-officer/inventory/stock-in?search=${code(ids.dslA)}`, { waitUntil: 'domcontentloaded' });
        const headers = (await page.locator('[data-tab-panel="stock-in"] thead th').allTextContents()).map((t) => t.trim());
        const cells = (await page.locator('[data-tab-panel="stock-in"] tbody tr').first().locator('td').allTextContents()).map((t) => t.trim());
        const at = (name) => cells[headers.indexOf(name)];
        // 37,000 L standard + 3,000 L depot order, none delivered: nothing sold, all 40,000 L reserved.
        return {
            pass: at('Sold QTY') === '0.00' && at('Reserved') === '40,000.00' && at('Available') === '0.00' && at('Current QTY') === '40,000.00' && /Fully Reserved/.test(at('Status')),
            headers, row: cells.slice(0, 12),
        };
    });

    await login('admin');
    await check('#6 dashboard reconciliation block and cards render', async () => {
        await page.goto(`${base}/admin/dashboard`, { waitUntil: 'networkidle' });
        const block = page.locator('[aria-label="Inventory reconciliation"]');
        const text = (await block.textContent()).replace(/\s+/g, ' ');
        const canvas = await block.locator('canvas[data-dashboard-chart]').count();
        const kpis = (await page.locator('.dash-kpi span').allTextContents()).map((t) => t.trim());
        return { pass: canvas === 1 && text.includes('Purchased:') && text.includes('Reserved:') && kpis.includes('Reserved (Pending Delivery)') && kpis.includes('Available to Sell'), note: text.slice(0, 400) };
    });
} finally {
    await browser.close();
}

results.push({ name: 'no JavaScript page errors', pass: pageErrors.length === 0, errors: pageErrors });
console.log(JSON.stringify(results, null, 2));
process.exitCode = results.every((r) => r.pass) ? 0 : 1;
