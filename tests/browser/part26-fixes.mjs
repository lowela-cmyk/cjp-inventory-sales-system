// Part 26 browser checks with screenshots in outputs/part26. Requires a verified --no-reload QA server (DB_DATABASE = a
// QA copy) seeded with temporary p26qa-* accounts and QA_IDS: pur002 (PUR-DSL-002, 100,000 L Diesel, all lifted onto
// liftTruck by haul002, nothing sold), pur003 / haul003 / standardSale (a Standard order delivered from a truck: a source
// change on Stock Out). The flow changes QA data: run once per seed. QA_DB names the QA database (mysql CLI, read only).
// RECEIPT_PNG / RECEIPT_PDF are sample receipt files.
// Usage: QA_IDS=/path/p26_qa_ids.json QA_DB=cjp_qa_... RECEIPT_PNG=... RECEIPT_PDF=... node tests/browser/part26-fixes.mjs
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';

const base = process.env.QA_BASE || 'http://127.0.0.1:8126';
const ids = JSON.parse(await fs.readFile(process.env.QA_IDS, 'utf8'));
const qaDb = process.env.QA_DB;
if (!qaDb || !/_qa_/.test(qaDb)) throw new Error('QA_DB must name the QA copy');
const out = 'outputs/part26';
await fs.mkdir(out, { recursive: true });
const password = 'Part26-QA-Only!';
const results = [];
const pageErrors = [];
const mysql = process.env.MYSQL_BIN || 'mysql';
const sql = (query) => execFileSync(mysql, ['-h127.0.0.1', '-P3306', '-uroot', '-N', '-B', qaDb, '-e', query], { encoding: 'utf8' }).trim();

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
page.on('pageerror', (error) => pageErrors.push(error.message));
// The first load of a heavy page on a fresh QA copy can take longer than Playwright's 30 s default.
page.setDefaultNavigationTimeout(120000);
page.setDefaultTimeout(120000);

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
    await page.locator('#username').fill(`p26qa-${role}@example.test`);
    await page.locator('#password').fill(password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('.login-submit').click()]);
};
const confirmDialog = async () => {
    const ok = page.locator('[data-confirm-dialog] [data-cd-ok]');
    await ok.waitFor({ state: 'visible', timeout: 5000 });
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), ok.click()]);
};
const submitAndConfirm = async (button) => {
    await button.click();
    await confirmDialog().catch(async () => { await page.waitForLoadState('domcontentloaded'); });
};
const text = async (locator) => ((await locator.textContent()) || '').replace(/\s+/g, ' ').trim();
const pur = String(ids.pur002);
const liftModal = () => page.locator(`#dispatch-haul-${ids.haul002}`);
const openLift = async () => {
    await page.goto(`${base}/admin/fuel-lifting`, { waitUntil: 'domcontentloaded' });
    // On a phone the row is a card whose actions open from its "⋮" menu first.
    const view = page.locator(`[data-modal-open="dispatch-haul-${ids.haul002}"]`).first();
    if (!(await view.isVisible())) await view.locator('xpath=ancestor::td[1]').locator('.card-menu-toggle').click();
    await view.click();
    await liftModal().waitFor({ state: 'visible' });
    return liftModal();
};

try {
    // ----- 1. Dashboard -------------------------------------------------------------------------------------------
    await login('admin');
    await check('#1 Dashboard: the two circled charts are gone, the rest stays', async () => {
        await page.goto(`${base}/admin/dashboard`, { waitUntil: 'networkidle' });
        const html = await page.content();
        const gone = ['Inventory Reconciliation', 'Purchase Reports'].filter((t) => html.includes(t));
        const kept = ['Current Stock By Fuel Type', 'Fuel Lifting Status', 'Purchased V.S Stock In', 'Garage Tanks', 'Expected Sales Revenue', 'Stock Out V.S Receivables Margin', 'Fuel Sold', 'Peak Demands']
            .filter((t) => !html.includes(t));
        const canvases = await page.locator('.dash canvas').count();
        // Space between the Current Inventory section and the next one (no empty block left where the charts were).
        const gap = await page.evaluate(() => {
            const inv = document.querySelector('#current-inventory').getBoundingClientRect();
            const next = document.querySelector('.dash-revenue-row').getBoundingClientRect();
            return Math.round(next.top - inv.bottom);
        });
        await page.screenshot({ path: `${out}/1-dashboard.png`, fullPage: true });
        return { pass: gone.length === 0 && kept.length === 0 && gap >= 0 && gap <= 40, gone, missing: kept, canvases, gapPx: gap };
    });

    // ----- 2. Sales: the lifted purchase is still offered for Depot to Client ---------------------------------------
    await login('sales-officer');
    await check('#2/#4 Sales lists PUR-DSL-002 (fully lifted) for Depot to Client, not for Standard', async () => {
        await page.goto(`${base}/sales-officer/sales`, { waitUntil: 'domcontentloaded' });
        await page.locator('[data-modal-open="so-sales-add"]').click();
        const modal = page.locator('#so-sales-add');
        await modal.waitFor({ state: 'visible' });
        await modal.locator('#sale_delivery_type').selectOption('standard');
        const option = modal.locator(`#sale_purchase_item_id_0 option[value="${pur}"]`);
        const standardHidden = await option.evaluate((el) => el.hidden && el.disabled);
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        const d2cShown = await option.evaluate((el) => !el.hidden && !el.disabled);
        const label = await text(option);
        const truckHidden = await modal.locator('[data-sale-truck-section]').isHidden();
        await modal.locator('#sale_customer_id').selectOption(String(ids.customer));
        await modal.locator('#sales_order_number').fill('SO-P26-DSL002');
        await modal.locator('#sale_quantity_liters_0').fill('40000');
        await modal.locator('#sale_purchase_item_id_0').selectOption(pur);
        await modal.locator('#sale_unit_price_0').fill('66');
        await modal.locator('#sale_payment_method').selectOption('cash_on_delivery');
        const breakdown = await text(modal.locator('[data-purchase-breakdown]').first());
        await modal.screenshot({ path: `${out}/2-sales-depot-to-client-lifted-purchase.png` });
        await submitAndConfirm(page.locator('button[form="record-sale-form"]'));
        const sale = sql("SELECT CONCAT(sales.id, '|', sale_items.purchase_item_id, '|', sales.delivery_type, '|', sale_items.quantity_liters) FROM sales JOIN sale_items ON sale_items.sale_id = sales.id WHERE sales.sales_order_number = 'SO-P26-DSL002' AND sales.deleted_at IS NULL");
        ids.p26Sale = Number(sale.split('|')[0]);
        return {
            pass: standardHidden && d2cShown && truckHidden && label === 'PUR-DSL-002 – DIESEL – 100,000 L available (lifted fuel on trucks included) – ₱58.00/L'
                && breakdown.includes('Unclaimed on trucks 100,000 L · Available 100,000 L') && sale.endsWith(`|${pur}|depot_to_client|40000.00`),
            label, breakdown, sale,
        };
    });

    // ----- 3/4. Fuel Lifting: figures, deliver from the truck, Stock-In drops on delivery ------------------------------
    await login('admin');
    await check('#3 the lift shows PUR-DSL-002 figures and offers the sale; Deliver from Truck delivers it once', async () => {
        const modal = await openLift();
        const figures = (await modal.locator('[data-lift-purchase-figures="PUR-DSL-002"] > div').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).join(' | ');
        const order = modal.locator(`select[name="sale_id"] option[value="${ids.p26Sale}"]`);
        const orderLabel = await text(order);
        const unloadNote = await text(modal.locator('[data-open-unlifted-orders]').first());
        const details = modal.locator('[data-lift-purchase-figures="PUR-DSL-002"]').locator('xpath=ancestor::div[contains(@class, "modal-card")][1]');
        await details.scrollIntoViewIfNeeded();
        await details.screenshot({ path: `${out}/3a-lift-details-purchase-figures.png` });
        await modal.locator('.truck-leftover-card').scrollIntoViewIfNeeded();
        await modal.locator('.truck-leftover-card').screenshot({ path: `${out}/3b-fuel-left-on-truck-order-and-unload.png` });
        await modal.locator('select[name="sale_id"]').selectOption(String(ids.p26Sale));
        await submitAndConfirm(modal.locator('.truck-leftover-form button[type="submit"]'));
        const state = sql(`SELECT CONCAT(delivery_status, '|', (SELECT COALESCE(SUM(quantity_liters), 0) FROM stock_outs WHERE sale_id = ${ids.p26Sale} AND status = 'released' AND purchase_item_id = ${pur})) FROM sales WHERE id = ${ids.p26Sale}`);
        return {
            pass: figures.includes('Purchased 100,000.00 L') && figures.includes('Reserved (open sales) 40,000.00 L') && figures.includes('Lifted 100,000.00 L')
                && figures.includes('Remaining in depot 0.00 L') && figures.includes('Sellable (Depot to Client) 60,000.00 L') && orderLabel.includes('SO-P26-DSL002')
                && unloadNote.includes('40,000.00 L of PUR-DSL-002') && state === 'delivered|40000.00',
            figures, orderLabel, unloadNote, state,
        };
    });

    await check('#4 Stock-In: PUR-DSL-002 now 60,000 L (dropped only on delivery)', async () => {
        await page.goto(`${base}/admin/inventory/stock-in?search=PUR-DSL-002`, { waitUntil: 'domcontentloaded' });
        const row = await text(page.locator('[data-tab-panel="stock-in"] tbody tr').first());
        await page.locator('[data-tab-panel="stock-in"] table').first().screenshot({ path: `${out}/4-stock-in-60000.png` });
        return { pass: row.includes('PUR-DSL-002') && row.includes('60,000.00') && row.includes('40,000.00'), row: row.slice(0, 260) };
    });

    await check('#4 Trucks tab: fuel inside the truck per Purchase ID', async () => {
        await page.goto(`${base}/admin/fuel-lifting/trucks?search=P26QA`, { waitUntil: 'domcontentloaded' });
        const cells = (await page.locator('[data-truck-fuel]').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
        await page.locator('[data-tab-panel="trucks"] table').screenshot({ path: `${out}/4-trucks-tab-fuel-by-purchase.png` });
        return { pass: cells.some((c) => c.startsWith('PUR-DSL-002 · Diesel · 60,000.00 L')) && cells.some((c) => c.startsWith('PUR-DSL-003 · Diesel · 15,000.00 L')), cells };
    });

    // ----- 5. Receipt preview -------------------------------------------------------------------------------------
    await check('#5 receipt preview: image, PDF, Remove, then upload the PDF', async () => {
        const modal = await openLift();
        const form = modal.locator('form.withdrawal-upload-form').first();
        const input = form.locator('input[type="file"]');
        const preview = form.locator('[data-withdrawal-preview]');
        await input.setInputFiles(process.env.RECEIPT_PNG);
        await form.locator('[data-preview-image]').waitFor({ state: 'visible' });
        const imageShown = await form.locator('[data-preview-image]').evaluate((img) => img.complete && img.naturalWidth > 0);
        const imageName = await text(form.locator('[data-preview-name]'));
        await form.scrollIntoViewIfNeeded();
        await form.screenshot({ path: `${out}/5a-receipt-preview-image.png` });
        await input.setInputFiles(process.env.RECEIPT_PDF);
        await form.locator('[data-preview-pdf]').waitFor({ state: 'visible' });
        const pdfShown = await form.locator('[data-preview-image]').isHidden();
        const pdfName = await text(form.locator('[data-preview-name]'));
        await page.waitForTimeout(800);
        await form.screenshot({ path: `${out}/5b-receipt-preview-pdf.png` });
        await form.locator('[data-preview-remove]').click();
        const removed = (await preview.isHidden()) && (await input.evaluate((el) => el.files.length === 0));
        await input.setInputFiles(process.env.RECEIPT_PDF);
        await submitAndConfirm(form.locator('button[type="submit"]'));
        const path = sql(`SELECT withdrawal_receipt_path FROM hauls WHERE id = ${ids.haul002}`);
        const response = await page.request.get(`${base}/withdrawal-receipts/${ids.haul002}`);
        return {
            pass: imageShown && imageName.startsWith('Selected: receipt-sample.png') && pdfShown && pdfName.includes('· PDF') && removed
                && path.endsWith('.pdf') && response.status() === 200 && response.headers()['content-type'] === 'application/pdf',
            imageName, pdfName, removed, path, contentType: response.headers()['content-type'],
        };
    });

    // ----- 6. Stock Out Source wraps -----------------------------------------------------------------------------
    const stockOutLayout = async (shot) => {
        await page.goto(`${base}/admin/inventory/stock-out?search=PUR-DSL-003`, { waitUntil: 'domcontentloaded' });
        const cell = page.locator('[data-stock-out-source]').first();
        const lines = (await cell.locator('.stock-out-source-line').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
        const box = await cell.evaluate((el) => ({ width: Math.round(el.getBoundingClientRect().width), height: Math.round(el.getBoundingClientRect().height), overflow: el.scrollWidth > el.clientWidth + 1, whiteSpace: getComputedStyle(el).whiteSpace }));
        const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        await page.locator('[data-tab-panel="stock-out"] .table-wrap').first().screenshot({ path: shot });
        return { lines, box, pageOverflow };
    };
    await check('#6 Stock Out Source shows its parts on separate, wrapped lines (1440px)', async () => {
        const { lines, box, pageOverflow } = await stockOutLayout(`${out}/6-stock-out-source-wrapped.png`);
        return {
            pass: lines.length === 2 && lines[0].startsWith('Depot — ') && lines[1].startsWith('Changed from Standard to Depot to Client') && box.whiteSpace === 'normal'
                && !box.overflow && box.width <= 320 && box.height > 40 && !pageOverflow,
            lines, box, pageOverflow,
        };
    });
    await page.setViewportSize({ width: 390, height: 900 });
    await check('#6 Stock Out stays inside the screen on a phone (390px); the card keeps the Source lines together', async () => {
        const { lines, pageOverflow } = await stockOutLayout(`${out}/6b-stock-out-mobile.png`);
        const row = page.locator('[data-tab-panel="stock-out"] tbody tr', { has: page.locator('.stock-out-source-line.is-secondary') }).first();
        await row.evaluate((r) => r.scrollIntoView());
        await row.click({ position: { x: 150, y: 20 } });
        const lefts = await row.locator('.stock-out-source-line').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().left)));
        await row.screenshot({ path: `${out}/m6-stock-out-card-source-mobile.png` });
        return { pass: lines.length === 2 && !pageOverflow && lefts.length === 2 && lefts[0] === lefts[1], pageOverflow, lefts };
    });
    // ----- Mobile (390px): the other Part 26 screens ---------------------------------------------------------------
    const noOverflow = () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
    await check('#1 mobile: dashboard without the two charts, no sideways scroll', async () => {
        await page.goto(`${base}/admin/dashboard`, { waitUntil: 'networkidle' });
        const html = await page.content();
        const fits = await noOverflow();
        await page.screenshot({ path: `${out}/m1-dashboard-mobile.png`, fullPage: true });
        return { pass: !html.includes('Inventory Reconciliation') && !html.includes('Purchase Reports') && html.includes('Garage Tanks') && fits, fits };
    });
    await check('#3/#4 mobile: lift figures, truck card and receipt preview fit the screen', async () => {
        const modal = await openLift();
        const figures = modal.locator('[data-lift-purchase-figures="PUR-DSL-002"]');
        await figures.scrollIntoViewIfNeeded();
        const figuresBox = await figures.boundingBox();
        const form = modal.locator('form.withdrawal-upload-form').first();
        await form.locator('input[type="file"]').setInputFiles(process.env.RECEIPT_PNG);
        await form.locator('[data-preview-image]').waitFor({ state: 'visible' });
        const formBox = await form.boundingBox();
        await figures.screenshot({ path: `${out}/m3-lift-figures-mobile.png` });
        await form.scrollIntoViewIfNeeded();
        await form.screenshot({ path: `${out}/m5-receipt-preview-mobile.png` });
        await form.locator('[data-preview-remove]').click();
        const fits = await noOverflow();
        return { pass: figuresBox.x >= 0 && figuresBox.x + figuresBox.width <= 391 && formBox.x + formBox.width <= 391 && fits, figuresBox, formBox, fits };
    });
    await check('#4 mobile: Trucks tab shows the fuel inside each truck', async () => {
        await page.goto(`${base}/admin/fuel-lifting/trucks?search=P26QA`, { waitUntil: 'domcontentloaded' });
        const cells = (await page.locator('[data-truck-fuel]').allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
        const fits = await noOverflow();
        await page.screenshot({ path: `${out}/m4-trucks-mobile.png`, fullPage: true });
        return { pass: cells.some((c) => c.startsWith('PUR-DSL-002 · Diesel')) && fits, cells, fits };
    });
    await login('sales-officer');
    await check('#2 mobile: Record Sales lists the lifted purchase for Depot to Client', async () => {
        await page.goto(`${base}/sales-officer/sales`, { waitUntil: 'domcontentloaded' });
        await page.locator('[data-modal-open="so-sales-add"]').click();
        const modal = page.locator('#so-sales-add');
        await modal.waitFor({ state: 'visible' });
        await modal.locator('#sale_delivery_type').selectOption('depot_to_client');
        await modal.locator('#sale_purchase_item_id_0').selectOption(pur);
        const label = await text(modal.locator(`#sale_purchase_item_id_0 option[value="${pur}"]`));
        const breakdown = await text(modal.locator('[data-purchase-breakdown]').first());
        const fits = await noOverflow();
        await modal.screenshot({ path: `${out}/m2-sales-mobile.png` });
        return { pass: label.startsWith('PUR-DSL-002 – DIESEL –') && breakdown.startsWith('PUR-DSL-002 · Purchased 100,000 L') && fits, label, breakdown, fits };
    });
    await page.setViewportSize({ width: 1440, height: 1000 });
} finally {
    await browser.close();
}

results.push({ name: 'no JavaScript page errors', pass: pageErrors.length === 0, errors: pageErrors });
console.log(JSON.stringify(results, null, 2));
process.exitCode = results.every((r) => r.pass) ? 0 : 1;
