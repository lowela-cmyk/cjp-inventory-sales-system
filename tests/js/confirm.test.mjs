// Browser tests for the confirmation flow (resources/js/confirm.js + the x-confirm-dialog markup).
// Run: npm run test:js   (Node's test runner + Playwright's Chromium; no server or database is used: the page and
// every form post are served by request interception.)
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { chromium } from 'playwright';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const dialogMarkup = readFileSync(resolve(root, 'resources/views/components/confirm-dialog.blade.php'), 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '');
const confirmJs = readFileSync(resolve(root, 'resources/js/confirm.js'), 'utf8');
const BASE = 'http://confirm.test';

const tomorrow = () => { const d = new Date(Date.now() + 86400000); return d.toISOString().slice(0, 10); };
const today = () => new Date().toISOString().slice(0, 10);

const fixture = `<!doctype html><html><head><meta charset="utf-8"><title>Confirm fixture</title></head><body>
<h1>Inventory</h1>
<div class="modal-backdrop is-open" id="purchase-add"><div class="admin-modal"><div class="modal-titlebar"><h2>Add Purchase Record</h2></div>
<form id="add-form" method="POST" action="/save/add">
  <input type="hidden" name="_token" value="t">
  <div class="form-row"><label for="d">Date</label><input id="d" name="purchase_date" type="date" max="${today()}" value="2026-11-08"></div>
  <div class="form-row"><label for="f">Fuel Type</label><select id="f" name="fuel_type_id" required><option value="">Select</option><option value="3">Unleaded</option></select></div>
  <div class="form-row"><label for="q">Quantity (Liters)</label><input id="q" name="quantity_ordered_liters" type="number" step="0.01" required></div>
  <div class="form-row"><label for="c">Total Cost</label><input id="c" name="total_cost" type="number" step="0.01"></div>
  <div class="form-row"><label for="u">Cost / Liter</label><input id="u" name="unit_cost" type="number" step="0.01"></div>
  <div class="form-row"><label for="s">Lift Date</label><input id="s" name="scheduled_at" type="date" value="2020-01-02"></div>
  <div class="form-row"><label for="n">Notes</label><textarea id="n" name="notes"></textarea></div>
  <button type="submit" id="add-save">Add</button>
</form></div></div>

<form id="edit-form" method="POST" action="/save/edit" data-confirm-item="PUR-UNL-003">
  <input type="hidden" name="_method" value="PATCH">
  <div class="form-row"><label for="e1">Depot</label><select id="e1" name="depot_id"><option value="1" selected>Batangas Energy Terminal</option><option value="2">Manila Depot</option></select></div>
  <div class="form-row"><label for="e2">Cost / Liter</label><input id="e2" name="unit_cost" type="number" step="0.01" value="55"></div>
</form>
<button type="submit" form="edit-form" id="edit-save">Apply Changes</button>

<form id="sale-form" method="POST" action="/save/sale">
  <div class="form-row"><label for="src">Fuel Type</label><select id="src" name="items[0][purchase_item_id]"><option value="9" data-available="1000" selected>PUR-DSL-001 · Diesel</option></select></div>
  <div class="form-row"><label for="sq">Qty Ordered</label><input id="sq" name="items[0][quantity_liters]" type="number" value="1500"></div>
  <button type="submit" id="sale-save">Add Order</button>
</form>

<form id="delete-form" method="POST" action="/save/delete" data-confirm="delete" data-confirm-action="Delete lift"
  data-confirm-item="LFT-DSL-001 (PUR-DSL-001, 15,000.00 L, Batangas Terminal)" data-confirm-typed="LFT-DSL-001"
  data-confirm-undo="This can't be undone." data-confirm-message="Delete lift LFT-DSL-001? This can't be undone.">
  <input type="hidden" name="_method" value="DELETE"><button type="submit" id="delete-btn">Delete</button>
</form>

<form id="status-form" method="POST" action="/save/status" data-confirm="status" data-confirm-item="LFT-DSL-001-01" data-confirm-from="Scheduled" data-confirm-to-field="lifting_status">
  <select name="lifting_status" aria-label="Status"><option value="in_transit">In Transit</option><option value="lifted">Lifted</option></select>
  <button type="submit" id="status-btn">Update</button>
</form>

<form id="none-form" method="POST" action="/save/none" data-confirm="none"><button type="submit" id="none-btn">Export</button></form>
${dialogMarkup}
<script type="module">${confirmJs}</script>
</body></html>`;

let browser;
let page;
let posts;

const open = async () => {
    posts = [];
    page = await browser.newPage();
    await page.route(`${BASE}/**`, async (route) => {
        const request = route.request();
        if (request.method() === 'POST') {
            posts.push({ url: new URL(request.url()).pathname, body: request.postData() || '' });
            await new Promise((r) => setTimeout(r, 300));
            return route.fulfill({ contentType: 'text/html', body: '<p id="done">Saved</p>' });
        }
        return route.fulfill({ contentType: 'text/html', body: fixture });
    });
    await page.goto(`${BASE}/`);
    await page.waitForFunction(() => window.__cjpConfirmInit === true);
};

const dialogText = () => page.locator('[data-confirm-dialog]').innerText();
const isOpen = () => page.locator('[data-confirm-dialog]').evaluate((d) => ! d.hidden);

before(async () => { browser = await chromium.launch(); });
after(async () => { await browser?.close(); });

test('invalid form: browser validation runs first, no dialog', async () => {
    await open();
    await page.click('#add-save');
    assert.equal(await isOpen(), false);
    assert.equal(posts.length, 0);
    await page.close();
});

test('add: review lists entered fields with readable values, Back to edit keeps inputs, focus on the safe button', async () => {
    await open();
    await page.selectOption('#f', '3');
    await page.fill('#q', '120000');
    await page.fill('#c', '5385179');
    await page.fill('#u', '44.88');
    await page.click('#add-save');
    assert.equal(await isOpen(), true);
    const text = await dialogText();
    assert.match(text, /Review: Add Purchase Record/);
    assert.match(text, /Unleaded/);
    assert.doesNotMatch(text, /\b3\b(?! )/, 'shows the name, not the ID');
    assert.match(text, /120,000 L/);
    assert.match(text, /PHP 5,385,179/);
    assert.match(text, /Cost \/ Liter\s*PHP 44\.88/, 'a per-liter price is money, not liters');
    assert.match(text, /Nov 8, 2026/);
    assert.doesNotMatch(text, /Notes/, 'empty optional fields are not listed');
    // Past date flagged only where past dates are unexpected (Date has max=today; Lift Date has none).
    assert.match(text, /Lift Date is in the past/);
    assert.doesNotMatch(text, /^Date is in the past/m);
    await page.waitForFunction(() => document.activeElement?.matches('.confirm-cancel'));
    assert.match(await page.locator('.confirm-cancel').innerText(), /Back to edit/);
    await page.click('.confirm-cancel');
    assert.equal(await isOpen(), false);
    assert.equal(await page.inputValue('#q'), '120000');
    assert.equal(posts.length, 0);
    await page.close();
});

test('confirm & save: button disabled with spinner, one post only (double submit blocked)', async () => {
    await open();
    await page.selectOption('#f', '3');
    await page.fill('#q', '100');
    await page.click('#add-save');
    await page.click('[data-cd-ok]', { noWaitAfter: true });
    const busy = await page.evaluate(() => {
        const d = document.querySelector('[data-confirm-dialog]');
        return { busy: d.classList.contains('is-busy'), disabled: d.querySelector('[data-cd-ok]').disabled, label: d.querySelector('[data-cd-ok-label]').textContent };
    });
    assert.deepEqual(busy, { busy: true, disabled: true, label: 'Saving…' });
    await page.locator('[data-cd-ok]').click({ force: true }).catch(() => {});
    await page.waitForSelector('#done');
    assert.equal(posts.length, 1);
    assert.equal(posts[0].url, '/save/add');
    assert.match(posts[0].body, /quantity_ordered_liters=100/);
    await page.close();
});

test('edit: only changed fields as Old → New; nothing changed says "No changes" and cannot submit', async () => {
    await open();
    await page.focus('#e2'); // first touch takes the snapshot
    await page.click('#edit-save');
    assert.match(await dialogText(), /No changes/);
    assert.equal(await page.locator('[data-cd-ok]').isHidden(), true);
    await page.keyboard.press('Escape');

    await page.selectOption('#e1', '2');
    await page.click('#edit-save');
    const text = await dialogText();
    assert.match(text, /Review changes to PUR-UNL-003/);
    assert.match(text, /Batangas Energy Terminal\s*→\s*Manila Depot/);
    assert.doesNotMatch(text, /Cost \/ Liter/, 'unchanged fields are not listed');
    assert.equal(await page.locator('[data-confirm-dialog] mark.confirm-new').innerText(), 'Manila Depot');
    await page.click('[data-cd-ok]');
    await page.waitForSelector('#done');
    assert.match(posts[0].body, /depot_id=2/);
    await page.close();
});

test('risk: quantity above the stock left in the chosen source is flagged', async () => {
    await open();
    await page.click('#sale-save');
    assert.match(await dialogText(), /1,500 L is more than the stock left in PUR-DSL-001 · Diesel \(1,000 L\)/);
    await page.close();
});

test('delete: names the item, says it cannot be undone, red button, typed ID required', async () => {
    await open();
    await page.click('#delete-btn');
    const text = await dialogText();
    assert.match(text, /Delete lift LFT-DSL-001 \(PUR-DSL-001, 15,000.00 L, Batangas Terminal\)\?/);
    assert.match(text, /This can't be undone\./);
    const ok = page.locator('[data-cd-ok]');
    assert.match(await ok.getAttribute('class'), /btn-danger/);
    assert.equal(await ok.isDisabled(), true);
    await page.fill('[data-cd-typed-input]', 'LFT-DSL-00');
    assert.equal(await ok.isDisabled(), true);
    await page.fill('[data-cd-typed-input]', 'lft-dsl-001');
    assert.equal(await ok.isDisabled(), false);
    await ok.click();
    await page.waitForSelector('#done');
    assert.equal(posts[0].url, '/save/delete');
    await page.close();
});

test('status: short From → To confirm using the chosen option', async () => {
    await open();
    await page.selectOption('#status-form select', 'lifted');
    await page.click('#status-btn');
    const text = await dialogText();
    assert.match(text, /LFT-DSL-001-01/);
    assert.match(text, /Scheduled\s*→\s*Lifted/);
    assert.match(await page.locator('[data-cd-ok-label]').innerText(), /Yes, set to Lifted/);
    await page.close();
});

test('Esc cancels only the dialog (the modal underneath stays open); Back cancels too', async () => {
    await open();
    await page.click('#status-btn');
    await page.keyboard.press('Escape');
    assert.equal(await isOpen(), false);
    assert.equal(await page.locator('#purchase-add').evaluate((m) => m.classList.contains('is-open')), true);

    await page.click('#status-btn');
    assert.equal(await isOpen(), true);
    await page.goBack();
    await page.waitForFunction(() => document.querySelector('[data-confirm-dialog]').hidden);
    assert.equal(posts.length, 0);
    assert.equal(new URL(page.url()).pathname, '/');
    await page.close();
});

test('data-confirm="none" submits straight away', async () => {
    await open();
    await page.click('#none-btn');
    await page.waitForSelector('#done');
    assert.equal(posts[0].url, '/save/none');
    await page.close();
});
