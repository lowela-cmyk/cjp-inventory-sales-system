// Part 25 browser checks (Sales + Fuel Lifting) with screenshots in outputs/part25. Requires a verified --no-reload QA
// server (DB_DATABASE = a QA copy) seeded with temporary p24qa-* accounts and the purchases / trucks in QA_IDS, among
// them PUR-DSL-002 (100,000 L Diesel, nothing lifted) and liftTruck (100,000 L). The flow changes QA data: run once per
// seed. QA_DB names the QA database, read with the mysql CLI only to find the new lift's id.
// Usage: QA_IDS=/path/qa_ids.json QA_DB=cjp_qa_... node tests/browser/part25-sales.mjs
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';

const base = process.env.QA_BASE || 'http://127.0.0.1:8124';
const ids = JSON.parse(await fs.readFile(process.env.QA_IDS, 'utf8'));
const qaDb = process.env.QA_DB;
if (!qaDb || !/_qa_/.test(qaDb)) throw new Error('QA_DB must name the QA copy');
const out = 'outputs/part25';
await fs.mkdir(out, { recursive: true });
const password = 'Part24-QA-Only!';
const results = [];
const pageErrors = [];
const sql = (query) => execFileSync('mysql', ['-h127.0.0.1', '-P3306', '-uroot', '-N', '-B', qaDb, '-e', query], { encoding: 'utf8' }).trim();

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
page.on('pageerror', (error) => pageErrors.push(error.message));

const check = async (name, fn) => {
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
const text = async (locator) => ((await locator.textContent()) || '').replace(/\s+/g, ' ').trim();
const openRecordSales = async () => {
    await page.goto(`${base}/sales-officer/sales`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-modal-open="so-sales-add"]').click();
    const modal = page.locator('#so-sales-add');
    await modal.waitFor({ state: 'visible' });
    return modal;
};
const pur = String(ids.pur002);

try {
    await login('sales-officer');
    let modal = await openRecordSales();

    await check('#1 Truck Selection shows the maximum capacity only (Garage to Client)', async () => {
        await modal.locator('#sale_delivery_type').selectOption('garage_to_client');
        const truck = modal.locator('#sale_delivery_truck_id');
        await modal.locator('#sale_quantity_liters_0').fill('500');
        await truck.selectOption(String(ids.bigTruck));
        const labels = (await truck.locator('option').allTextContents()).map((t) => t.trim()).filter((t) => t.startsWith('QA P25QA'));
        const hint = await text(modal.locator('#sale_delivery_truck_capacity'));
        const visible = await modal.locator('[data-sale-truck-section]').isVisible();
        const required = await truck.evaluate((el) => el.required);
        await modal.locator('[data-sale-truck-section]').screenshot({ path: `${out}/1-garage-truck-max-capacity.png` });
        return {
            pass: visible && required && labels.length === 4 && labels.every((l) => / – Max [\d,]+ L$/.test(l)) && !labels.some((l) => /Loaded|available|Available/.test(l))
                && hint === 'Maximum capacity: 30,000 L' && (await modal.locator('[data-truck-cap-loaded], [data-truck-cap-available], [data-truck-cap-remaining]').count()) === 0,
            labels, hint,
        };
    });

    await check('#3 Depot to Client removes Truck Selection; #2 fuel shows its Purchase ID and figures', async () => {
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        const truckHidden = await modal.locator('[data-sale-truck-section]').isHidden();
        const truckDisabled = await modal.locator('#sale_delivery_truck_id').isDisabled();
        const addLine = await modal.locator('[data-sale-add-line-row]').isVisible();
        await modal.locator('#sale_customer_id').selectOption(String(ids.customer));
        await modal.locator('#sales_order_number').fill('SO-P25-DSL002');
        await modal.locator('#sale_quantity_liters_0').fill('40000');
        await modal.locator('#sale_purchase_item_id_0').selectOption(pur);
        await modal.locator('#sale_unit_price_0').fill('62');
        await modal.locator('#sale_payment_method').selectOption('cash_on_delivery');
        const option = await text(modal.locator(`#sale_purchase_item_id_0 option[value="${pur}"]`));
        const breakdown = await text(modal.locator('[data-purchase-breakdown]').first());
        await modal.screenshot({ path: `${out}/2-depot-to-client-no-truck-purchase-id.png` });
        return {
            pass: truckHidden && truckDisabled && addLine && option.startsWith('PUR-DSL-002 – DIESEL – 100,000 L in depot')
                && breakdown === 'PUR-DSL-002 · Purchased 100,000 L · On lifts 0 L · Reserved 0 L · Delivered 0 L · Available 100,000 L',
            option, breakdown,
        };
    });

    await check('#4 40,000 L Depot to Client sale saved from the unlifted purchase', async () => {
        await page.locator('button[form="record-sale-form"]').click();
        await confirmDialog();
        await page.goto(`${base}/sales-officer/sales?search=SO-P25-DSL002`, { waitUntil: 'domcontentloaded' });
        const row = await text(page.locator('[data-tab-panel="receivables"] tbody tr').first());
        await page.locator('[data-tab-panel="receivables"] table').first().screenshot({ path: `${out}/3-sales-row-fuel-with-purchase-id.png` });
        const saleId = sql("SELECT id FROM sales WHERE sales_order_number = 'SO-P25-DSL002' AND deleted_at IS NULL");
        const truck = sql(`SELECT COALESCE(delivery_truck_id, 'NULL') FROM sales WHERE id = ${Number(saleId)}`);
        ids.p25Sale = Number(saleId);
        modal = await openRecordSales();
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        await modal.locator('#sale_purchase_item_id_0').selectOption(pur);
        const breakdown = await text(modal.locator('[data-purchase-breakdown]').first());
        return {
            pass: row.includes('SO-P25-DSL002') && row.includes('Diesel · PUR-DSL-002') && row.includes('40,000.00') && truck === 'NULL'
                && breakdown === 'PUR-DSL-002 · Purchased 100,000 L · On lifts 0 L · Reserved 40,000 L · Delivered 0 L · Available 60,000 L',
            row: row.slice(0, 220), breakdown, saleId,
        };
    });

    // ----- Fuel Lifting ---------------------------------------------------------------------------------------------
    await login('dispatch-officer');
    await page.goto(`${base}/dispatch/fuel-lifting`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-modal-open="dispatch-lift-add"]').click();
    const lift = page.locator('#dispatch-lift-add');
    await lift.waitFor({ state: 'visible' });
    const form = lift.locator('#lift-schedule-form');
    const submit = page.locator('button[form="lift-schedule-form"]');

    await check('#4 Fuel Lifting sees the sale, its purchase, and the reserved liters', async () => {
        await form.locator('[name="truck_id"]').selectOption(String(ids.liftTruck));
        await form.locator('[name="driver_user_id"]').selectOption(String(ids.driverId));
        await form.locator('[name="scheduled_at"]').fill('2026-11-21T10:00');
        await form.locator('[data-lifting-purchase]').first().selectOption(pur);
        const summary = await text(form.locator('[data-purchase-summary]').first());
        // More than the 60,000 L not reserved, without the sale, would take the sale's fuel: blocked in the browser.
        await form.locator('[data-lifting-quantity]').first().fill('60000.01');
        const blockedWarning = await text(form.locator('[data-lifting-warning]'));
        const blocked = await submit.isDisabled();
        await lift.locator('.modal-body').screenshot({ path: `${out}/4-lift-blocked-reserved-fuel.png` });
        await form.locator('[data-lifting-quantity]').first().fill('40000');
        await form.locator('[data-delivery-add]').click();
        const delivery = form.locator('[data-delivery-sale]').first();
        const saleLabel = await text(delivery.locator(`option[value="${ids.p25Sale}"]`));
        await delivery.selectOption(String(ids.p25Sale));
        const ordered = await text(form.locator('[data-delivery-ordered]').first());
        const allowedWarning = await text(form.locator('[data-lifting-warning]'));
        const allowed = await submit.isEnabled();
        await form.screenshot({ path: `${out}/5-lift-carries-sale-from-purchase.png` });
        return {
            pass: summary === 'DIESEL - 100,000.00 L · 40,000.00 L reserved for sales · 60,000.00 L free to lift'
                && blocked && blockedWarning.includes('not reserved for sales') && saleLabel.endsWith('· SO-P25-DSL002 · PUR-DSL-002')
                && ordered === 'PUR-DSL-002 · DIESEL - 40,000.00 L' && allowed && allowedWarning === '',
            summary, blockedWarning, saleLabel, ordered,
        };
    });

    await check('#4 the lift is saved with the sale on it', async () => {
        await submit.click();
        await confirmDialog().catch(async () => { await page.waitForLoadState('domcontentloaded'); });
        const haul = sql(`SELECT hauls.id FROM hauls JOIN haul_allocations ON haul_allocations.haul_id = hauls.id WHERE haul_allocations.sale_id = ${ids.p25Sale} AND haul_allocations.status != 'cancelled' AND hauls.deleted_at IS NULL`);
        ids.p25Haul = Number(haul);
        return { pass: ids.p25Haul > 0, haul };
    });

    // ----- Driver completes the lift: the sale is delivered ---------------------------------------------------------
    await login('driver');
    await check('#4 driver completes the lift; the sale is Delivered', async () => {
        for (const status of ['in_transit', 'lifted']) {
            await page.goto(`${base}/driver/fuel-lifting`, { waitUntil: 'domcontentloaded' });
            await page.locator(`button[data-modal-open="driver-haul-${ids.p25Haul}"]`).first().click();
            const driverModal = page.locator(`#driver-haul-${ids.p25Haul}`);
            await driverModal.waitFor({ state: 'visible' });
            await driverModal.locator('select[name="lifting_status"]').selectOption(status);
            await driverModal.locator('form[action*="/status"] button[type="submit"]').click();
            await confirmDialog();
        }
        const state = sql(`SELECT CONCAT(delivery_status, '|', (SELECT COALESCE(SUM(quantity_liters), 0) FROM stock_outs WHERE sale_id = ${ids.p25Sale} AND status = 'released')) FROM sales WHERE id = ${ids.p25Sale}`);
        return { pass: state === 'delivered|40000.00', state };
    });

    await login('sales-officer');
    await check('#4 after delivery: 40,000 L deducted, 60,000 L remain available', async () => {
        await page.goto(`${base}/sales-officer/sales?search=SO-P25-DSL002`, { waitUntil: 'domcontentloaded' });
        const row = await text(page.locator('[data-tab-panel="receivables"] tbody tr').first());
        modal = await openRecordSales();
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        await modal.locator('#sale_purchase_item_id_0').selectOption(pur);
        const option = await text(modal.locator(`#sale_purchase_item_id_0 option[value="${pur}"]`));
        const breakdown = await text(modal.locator('[data-purchase-breakdown]').first());
        await modal.locator('#sale_purchase_item_id_0').locator('xpath=..').screenshot({ path: `${out}/6-purchase-after-delivery.png` });
        return {
            pass: /Delivered/.test(row) && option.startsWith('PUR-DSL-002 – DIESEL – 60,000 L in depot')
                && breakdown === 'PUR-DSL-002 · Purchased 100,000 L · On lifts 40,000 L · Reserved 0 L · Delivered 40,000 L · Available 60,000 L',
            row: row.slice(0, 220), option, breakdown,
        };
    });

    await login('dispatch-officer');
    await check('Fuel Lifting: the 60,000 L left are free to lift', async () => {
        await page.goto(`${base}/dispatch/fuel-lifting`, { waitUntil: 'domcontentloaded' });
        const label = await page.locator(`#lift-schedule-form [data-lifting-purchase] option[value="${pur}"]`).first().getAttribute('data-remaining-label');
        await page.locator('table').first().screenshot({ path: `${out}/7-fuel-lifting-table.png` });
        return { pass: label === 'DIESEL - 60,000.00 L', label };
    });
} finally {
    await browser.close();
}

results.push({ name: 'no JavaScript page errors', pass: pageErrors.length === 0, errors: pageErrors });
console.log(JSON.stringify(results, null, 2));
process.exitCode = results.every((r) => r.pass) ? 0 : 1;
