// Part 2 Button Revisions (October 22, 2026): Schedule Lift. Lift Details lists the purchases on the truck (Purchase
// ID, Fuel / Remaining in Depot, Amount to Lift, ATL); Delivery Details lists the open Depot to Client orders it
// delivers. Fuel Remaining in Truck = Amount to Lift - deliveries, updated live. The server re-checks everything.

const liters = (value) => `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L`;

// Submit buttons of a form, including the modal footer button tied to it with form="...".
const submitButtons = (form) => [
    ...form.querySelectorAll('button[type="submit"]'),
    ...(form.id ? document.querySelectorAll(`button[type="submit"][form="${form.id}"]`) : []),
];

const reindex = (form) => {
    form.querySelectorAll('[data-lifting-item]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`); });
        row.querySelectorAll('[id]').forEach((field) => {
            const label = row.querySelector(`label[for="${field.id}"]`);
            field.id = field.id.replace(/_\d+$/, `_${index}`);
            label?.setAttribute('for', field.id);
        });
    });
    form.querySelectorAll('[data-delivery-row]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replace(/deliveries\[[^\]]*\]/, `deliveries[${index}]`); });
        row.querySelectorAll('[id]').forEach((field) => {
            const label = row.querySelector(`label[for="${field.id}"]`);
            field.id = field.id.replace(/_[^_]+$/, `_${index}`);
            label?.setAttribute('for', field.id);
        });
    });
};

const update = (form) => {
    if (!form) return;
    reindex(form);
    const rows = [...form.querySelectorAll('[data-lifting-item]')];
    const truckOption = form.querySelector('[name="truck_id"]')?.selectedOptions?.[0];
    const capacity = Number(truckOption?.dataset.capacity || 0);
    const purchases = new Map();
    const fuels = new Set();
    const mainFuels = new Set();
    let total = 0;
    let depotId = null;
    let depotLabel = '';
    let error = '';

    // Part 15: one lift carries one fuel (plus F1, the blend portion) from one pickup depot. Once the first Purchase ID
    // is chosen, the other rows list only purchases of its fuel (and F1) and depot; a row holding another fuel (the first
    // one changed) is cleared. An F1 first row restricts the depot only.
    const first = rows[0]?.querySelector('[data-lifting-purchase]')?.selectedOptions?.[0];
    const firstFuel = first?.value && !('f1' in first.dataset) ? first.dataset.fuelTypeId : '';
    const firstDepot = first?.value ? first.dataset.depotId : '';
    let cleared = false;
    rows.slice(1).forEach((row) => {
        const select = row.querySelector('[data-lifting-purchase]');
        if (!select) return;
        [...select.options].forEach((option) => {
            if (!option.value) return;
            const otherFuel = Boolean(firstFuel) && option.dataset.fuelTypeId !== firstFuel && !('f1' in option.dataset);
            const other = Boolean(firstDepot) && (otherFuel || option.dataset.depotId !== firstDepot);
            option.hidden = other;
            option.disabled = other;
        });
        if (select.selectedOptions[0]?.disabled) {
            select.value = '';
            cleared = true;
        }
    });
    const fuelNote = form.querySelector('[data-lifting-fuel-note]');
    if (fuelNote) {
        if (cleared) fuelNote.textContent = 'The other purchases were cleared: a lift carries one fuel type. Choose them again.';
        fuelNote.hidden = !cleared && (fuelNote.hidden || rows.slice(1).every((row) => row.querySelector('[data-lifting-purchase]')?.value));
    }

    rows.forEach((row) => {
        const option = row.querySelector('[data-lifting-purchase]')?.selectedOptions?.[0];
        const quantityInput = row.querySelector('[data-lifting-quantity]');
        const quantity = Number(quantityInput?.value || 0);
        const summary = row.querySelector('[data-purchase-summary]');
        total += Number.isFinite(quantity) ? quantity : 0;

        if (option?.value) {
            if (purchases.has(option.value)) error ||= 'The same Purchase ID cannot be added twice.';
            purchases.set(option.value, (purchases.get(option.value) || 0) + quantity);
            fuels.add(option.dataset.fuelTypeId);
            if (!('f1' in option.dataset)) mainFuels.add(option.dataset.fuelTypeId);
            if (depotId && option.dataset.depotId !== depotId) error ||= 'All purchases must use the same pickup depot.';
            if (mainFuels.size > 1) error ||= 'All purchases in one lift must be the same fuel type (F1 may be added).';
            depotId ||= option.dataset.depotId;
            depotLabel ||= option.dataset.pickup || '';
            const remaining = Number(option.dataset.remaining || 0);
            if (quantity > remaining) error ||= `An Amount to Lift is more than the ${liters(remaining)} remaining in the depot.`;
            if (quantityInput) quantityInput.max = String(remaining);
            if (summary) summary.textContent = option.dataset.remainingLabel || '';
        } else if (summary) {
            summary.textContent = 'Select Purchase ID';
        }
        const remove = row.querySelector('[data-lifting-item-remove]');
        if (remove) remove.disabled = rows.length === 1;
    });

    // Delivery Details: only orders supplied from a purchase on this lift, each order once.
    const deliveryRows = [...form.querySelectorAll('[data-delivery-row]')];
    const chosen = deliveryRows.map((row) => row.querySelector('[data-delivery-sale]')?.value).filter(Boolean);
    let delivered = 0;
    const deliveredByPurchase = new Map();
    deliveryRows.forEach((row) => {
        const select = row.querySelector('[data-delivery-sale]');
        if (!select) return;
        [...select.options].forEach((option) => {
            if (!option.value) return;
            const supplied = option.dataset.purchaseItemId ? purchases.has(option.dataset.purchaseItemId) : fuels.has(option.dataset.fuelTypeId);
            const takenElsewhere = option.value !== select.value && chosen.includes(option.value);
            option.hidden = !supplied || takenElsewhere;
            option.disabled = !supplied || takenElsewhere;
        });
        if (select.selectedOptions[0]?.disabled) select.value = '';
        const option = select.selectedOptions[0];
        const ordered = row.querySelector('[data-delivery-ordered]');
        if (ordered) ordered.textContent = option?.value ? option.dataset.ordered : 'Select Order ID';
        delivered += option?.value ? Number(option.dataset.liters || 0) : 0;
        if (option?.value && option.dataset.purchaseItemId) {
            deliveredByPurchase.set(option.dataset.purchaseItemId, (deliveredByPurchase.get(option.dataset.purchaseItemId) || 0) + Number(option.dataset.liters || 0));
        }
    });
    // A purchase line can only deliver what it carries.
    deliveredByPurchase.forEach((liters, purchaseId) => {
        if (Math.round(liters * 100) > Math.round((purchases.get(purchaseId) || 0) * 100)) {
            error ||= `The orders from one purchase add up to ${liters.toLocaleString(undefined, { minimumFractionDigits: 2 })} L, more than its Amount to Lift.`;
        }
    });

    const remainingInTruck = Math.round((total - delivered) * 100) / 100;
    const overDelivered = remainingInTruck < 0;
    const set = (selector, value) => { const element = form.querySelector(selector); if (element) element.textContent = value; };
    set('[data-truck-remaining]', overDelivered ? `−${liters(-remainingInTruck)}` : liters(remainingInTruck));
    form.querySelector('[data-truck-remaining-line]')?.classList.toggle('is-over', overDelivered);
    set('[data-deliveries-warning]', overDelivered ? `The deliveries add up to ${liters(delivered)}, more than the Amount to Lift of ${liters(total)}.` : '');
    const title = form.querySelector('[data-deliveries-title]');
    if (title) title.hidden = deliveryRows.length === 0;

    if (capacity > 0 && total > capacity) error ||= 'Combined quantity exceeds the selected truck capacity.';
    set('[data-truck-capacity]', liters(capacity));
    set('[data-pickup-depot]', depotLabel || 'Select a purchase to derive the official depot and address.');
    set('[data-lifting-warning]', error);

    const submit = submitButtons(form)[0];
    if (submit) {
        submit.disabled = Boolean(error) || overDelivered || rows.some((row) => !row.querySelector('[data-lifting-purchase]')?.value)
            || capacity <= 0 || total <= 0 || deliveryRows.some((row) => !row.querySelector('[data-delivery-sale]')?.value);
    }
};

document.addEventListener('click', (event) => {
    const form = event.target.closest('[data-lifting-schedule-form]');
    if (!form) return;

    const addPurchase = event.target.closest('[data-lifting-item-add]');
    if (addPurchase) {
        const items = form.querySelector('[data-lifting-items]');
        const source = items?.querySelector('[data-lifting-item]:last-child');
        if (items && source) {
            const clone = source.cloneNode(true);
            clone.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
            clone.querySelectorAll('input').forEach((input) => { input.value = ''; });
            clone.querySelectorAll('.field-error').forEach((message) => message.remove());
            clone.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
            items.appendChild(clone);
        }
    }

    const removePurchase = event.target.closest('[data-lifting-item-remove]');
    if (removePurchase && form.querySelectorAll('[data-lifting-item]').length > 1) {
        removePurchase.closest('[data-lifting-item]')?.remove();
    }

    if (event.target.closest('[data-delivery-add]')) {
        const template = form.querySelector('template[data-delivery-template]');
        const rows = form.querySelector('[data-delivery-rows]');
        const row = template?.content.querySelector('[data-delivery-row]')?.cloneNode(true);
        if (row && rows) rows.appendChild(row);
    }

    const removeDelivery = event.target.closest('[data-delivery-remove]');
    if (removeDelivery) removeDelivery.closest('[data-delivery-row]')?.remove();

    if (addPurchase || removePurchase || removeDelivery || event.target.closest('[data-delivery-add]')) update(form);
});

['input', 'change'].forEach((type) => document.addEventListener(type, (event) => {
    const form = event.target.closest('[data-lifting-schedule-form]');
    if (form) update(form);
}));

document.querySelectorAll('[data-lifting-schedule-form]').forEach(update);
