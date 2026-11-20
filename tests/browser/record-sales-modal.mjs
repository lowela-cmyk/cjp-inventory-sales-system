// Record Sales modal (restored layout + Part 24 features) for Sales Officer and Admin: layout at desktop and phone
// widths, scrolling, validation, calculations, saving, a multi-purchase Depot to Client order, and editing.
// Requires a verified --no-reload QA server (DB_DATABASE = a QA copy) seeded with p24qa-* accounts and the purchases in
// QA_IDS (dslB and dslC with Diesel in the depot). Usage: QA_IDS=/path/qa_ids.json node tests/browser/record-sales-modal.mjs
import { chromium } from 'playwright';
import fs from 'node:fs/promises';

const base = process.env.QA_BASE || 'http://127.0.0.1:8124';
const ids = JSON.parse(await fs.readFile(process.env.QA_IDS, 'utf8'));
const run = Date.now().toString(36).slice(-5).toUpperCase();
const results = [];
const pageErrors = [];
const browser = await chromium.launch({ headless: true });

const check = async (name, fn) => {
    try {
        const detail = await fn();
        results.push({ name, pass: detail.pass !== false, ...detail });
    } catch (error) {
        results.push({ name, pass: false, error: error.message.split('\n')[0] });
    }
};

const session = async (role, viewport) => {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    page.on('pageerror', (error) => pageErrors.push(`${role}: ${error.message}`));
    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('#username').fill(`p24qa-${role}@example.test`);
    await page.locator('#password').fill('Part24-QA-Only!');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.login-submit').click()]);
    return { context, page };
};

const openModal = async (page, prefix) => {
    await page.goto(`${base}/${prefix}/sales`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-modal-open="so-sales-add"]').click();
    const modal = page.locator('#so-sales-add');
    await modal.locator('#sale_delivery_type').waitFor({ state: 'visible' });
    return modal;
};

const confirmAndWait = async (page) => {
    const ok = page.locator('[data-confirm-dialog] [data-cd-ok]');
    await ok.waitFor({ state: 'visible', timeout: 5000 });
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), ok.click()]);
};

const text = async (locator) => (await locator.textContent()).replace(/\s+/g, ' ').trim();

for (const [role, prefix] of [['sales-officer', 'sales-officer'], ['admin', 'admin']]) {
    // ----- layout and scrolling at desktop and phone widths --------------------------------------------------------
    for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
        const { context, page } = await session(role, viewport);
        await check(`${role} ${viewport.width}px: original fields in order, scrollable, buttons reachable`, async () => {
            const modal = await openModal(page, prefix);
            const labels = (await modal.locator('label, .f1-breakdown-cell > span, .sale-line-total-row > span').evaluateAll((els) => els
                .filter((el) => el.offsetParent !== null).map((el) => el.textContent.trim()))).filter(Boolean);
            const expected = ['Delivery Type', 'Customer', 'Sales Order', 'Order Date', 'Qty Ordered', 'Fuel Type', 'Fuel Price', 'Ordered Amount', 'Fuel Amount', 'Total Amount', 'Fuel Total', 'Line Total', 'Amount Payment', 'Reference Number', 'Date of Payment', 'Payment Terms', 'Payment Method', 'Due Date', 'Payment Status'];
            const body = modal.locator('.modal-body');
            const scroll = await body.evaluate((el) => ({ scrollable: el.scrollHeight > el.clientHeight, overflowY: getComputedStyle(el).overflowY }));
            await modal.locator('#sale_delivery_type').selectOption('garage_to_client');
            const deliveryLabels = await modal.locator('[data-delivery-details] label').allTextContents();
            await body.evaluate((el) => { el.scrollTop = el.scrollHeight; });
            const lastFieldVisible = await modal.locator('#sale_delivery_date').isVisible();
            const buttons = await page.locator('button[form="record-sale-form"]').boundingBox();
            const cancel = await modal.locator('[data-modal-close]').last().boundingBox();
            const noSideScroll = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
            return {
                pass: JSON.stringify(labels) === JSON.stringify(expected) && scroll.scrollable && ['auto', 'scroll'].includes(scroll.overflowY)
                    && deliveryLabels.map((l) => l.trim()).join('|') === 'Truck ID|Driver Name|Delivery Date' && lastFieldVisible
                    && buttons && buttons.y + buttons.height <= viewport.height && cancel && cancel.y + cancel.height <= viewport.height && noSideScroll,
                labels, scroll, deliveryLabels,
            };
        });
        await context.close();
    }

    const { context, page } = await session(role, { width: 1440, height: 900 });
    const so = `SO-RSM-${role === 'admin' ? 'AD' : 'SO'}-${run}`;

    await check(`${role}: validation blocks an empty or overpaid order`, async () => {
        const modal = await openModal(page, prefix);
        await page.locator('button[form="record-sale-form"]').click();
        const dialogShown = await page.locator('[data-confirm-dialog] [data-cd-ok]').isVisible();
        const invalid = await modal.locator('#record-sale-form').evaluate((form) => [...form.querySelectorAll(':invalid')].map((el) => el.name).filter(Boolean));
        await modal.locator('#sale_quantity_liters_0').fill('1000');
        await modal.locator('#sale_purchase_item_id_0').selectOption(String(ids.dslB));
        await modal.locator('#sale_unit_price_0').fill('60');
        await modal.locator('#sale_amount_paid').fill('60000.01');
        const overpaid = await modal.locator('#sale_amount_paid').evaluate((el) => el.validationMessage);
        await modal.locator('#sale_quantity_liters_0').fill('999999');
        const oversold = await modal.locator('#sale_quantity_liters_0').evaluate((el) => el.validationMessage);
        return {
            pass: !dialogShown && invalid.includes('customer_id') && invalid.includes('items[0][quantity_liters]') && invalid.includes('payment_method')
                && overpaid.includes('cannot exceed') && oversold.includes('available'),
            invalid, overpaid, oversold,
        };
    });

    await check(`${role}: calculations, then saving a Standard order`, async () => {
        const modal = await openModal(page, prefix);
        await modal.locator('#sale_customer_id').selectOption(String(ids.customer));
        await modal.locator('#sales_order_number').fill(so);
        await modal.locator('#sale_quantity_liters_0').fill('1200');
        await modal.locator('#sale_purchase_item_id_0').selectOption(String(ids.dslB));
        await modal.locator('#sale_unit_price_0').fill('61.25');
        await modal.locator('#sale_amount_paid').fill('50000');
        await modal.locator('#sale_payment_method').selectOption('cash_on_delivery');
        const figures = {
            ordered: await text(modal.locator('[data-sale-ordered]')),
            fuel: await text(modal.locator('[data-sale-fuel-amount]')),
            total: await text(modal.locator('[data-sale-total-amount]')),
            fuelTotal: await text(modal.locator('[data-sales-main-total]')),
            lineTotal: await text(modal.locator('[data-sales-line-total]')),
            status: await text(modal.locator('[data-sales-payment-status-preview]')),
        };
        await page.locator('button[form="record-sale-form"]').click();
        await confirmAndWait(page);
        await page.goto(`${base}/${prefix}/sales?search=${so}`, { waitUntil: 'domcontentloaded' });
        const row = await text(page.locator('[data-tab-panel="receivables"] tbody tr').first());
        return {
            pass: figures.ordered === '1,200.00 L' && figures.fuel === '1,200.00 L' && figures.total === '1,200.00 L' && figures.fuelTotal === '73,500.00'
                && figures.lineTotal === '73,500.00' && figures.status === 'Partially Paid' && row.includes(so) && row.includes('73,500.00') && row.includes('50,000.00'),
            figures, row: row.slice(0, 220),
        };
    });

    await check(`${role}: editing the saved order`, async () => {
        await page.goto(`${base}/${prefix}/sales?search=${so}`, { waitUntil: 'domcontentloaded' });
        const modalId = await page.locator('[data-tab-panel="receivables"] tbody tr').first().locator('[data-modal-open^="so-sales-edit-"]').getAttribute('data-modal-open');
        const saleId = modalId.replace('so-sales-edit-', '');
        await page.locator(`[data-modal-open="${modalId}"]`).first().click();
        const editModal = page.locator(`#${modalId}`);
        await editModal.locator(`#sales_order_number_${saleId}`).waitFor({ state: 'visible' });
        await editModal.locator(`#sales_order_number_${saleId}`).fill(`${so}-E`);
        await page.locator(`button[type="submit"][form="sale-update-${saleId}"]`).click();
        await confirmAndWait(page);
        await page.goto(`${base}/${prefix}/sales?search=${so}-E`, { waitUntil: 'domcontentloaded' });
        const row = await text(page.locator('[data-tab-panel="receivables"] tbody tr').first());
        return { pass: row.includes(`${so}-E`) && row.includes('73,500.00'), row: row.slice(0, 160) };
    });

    await check(`${role}: a Depot to Client order from two purchases (same-fuel and duplicate checks)`, async () => {
        const modal = await openModal(page, prefix);
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        await modal.locator('#sale_customer_id').selectOption(String(ids.customer));
        await modal.locator('#sales_order_number').fill(`${so}-DTC`);
        await modal.locator('#sale_quantity_liters_0').fill('1000');
        await modal.locator('#sale_purchase_item_id_0').selectOption(String(ids.dslB));
        await modal.locator('#sale_unit_price_0').fill('62');
        await modal.locator('[data-add-sale-line]').click();
        const line = modal.locator('[data-sale-extra-line]').first();
        // The added line has the original row's fields side by side (same top edge).
        const tops = await Promise.all(['[data-sale-quantity]', '[data-sale-purchase]', '[data-sale-price]'].map(async (sel) => Math.round((await line.locator(sel).boundingBox()).y)));
        await line.locator('[data-sale-quantity]').fill('1500');
        await line.locator('[data-sale-purchase]').selectOption(String(ids.prem));
        const otherFuel = await line.locator('[data-sale-purchase]').evaluate((el) => el.validationMessage);
        await line.locator('[data-sale-purchase]').selectOption(String(ids.dslB));
        const duplicate = await line.locator('[data-sale-purchase]').evaluate((el) => el.validationMessage);
        await line.locator('[data-sale-purchase]').selectOption(String(ids.dslC));
        await line.locator('[data-sale-price]').fill('62');
        const orderTotal = await text(modal.locator('[data-sales-line-total]').last());
        await modal.locator('#sale_payment_method').selectOption('cash_on_delivery');
        await page.locator('button[form="record-sale-form"]').click();
        await confirmAndWait(page);
        await page.goto(`${base}/${prefix}/sales?search=${so}-DTC`, { waitUntil: 'domcontentloaded' });
        const row = await text(page.locator('[data-tab-panel="receivables"] tbody tr').first());
        return {
            pass: new Set(tops).size === 1 && otherFuel.includes('same fuel') && duplicate.includes('already on line 1') && orderTotal === '93,000.00'
                && row.includes(`${so}-DTC`) && row.includes('2,500.00') && row.includes('155,000.00'),
            tops, otherFuel, duplicate, row: row.slice(0, 200),
        };
    });
    await context.close();
}

await browser.close();
results.push({ name: 'no JavaScript page errors', pass: pageErrors.length === 0, errors: pageErrors });
console.log(JSON.stringify(results, null, 2));
process.exitCode = results.every((r) => r.pass) ? 0 : 1;
