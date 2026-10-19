import './bootstrap';
import Chart from 'chart.js/auto';
import '../css/part9.css';
import '../css/select-picker.css';
import './select-picker';

// True while a dropdown list rendered in-page (appearance: base-select) is open. Browsers without the :open
// selector keep the native list, which never passes Escape to the page.
const isOpenSelectList = (target) => {
    const select = target?.closest?.('select');
    if (select?.dataset.pickerOpen === 'true') return true;
    try {
        return Boolean(select?.matches(':open'));
    } catch {
        return false;
    }
};

const closeModal = (modal) => {
    modal?.classList.remove('is-open');
    modal?.setAttribute('aria-hidden', 'true');
};

// Receipt lightbox: show one slide at a time; images load on first open (they are private, role-gated files).
const showReceiptSlide = (lightbox, index) => {
    const slides = [...lightbox.querySelectorAll('[data-receipt-slide]')];
    if (slides.length === 0) return;
    const next = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => { slide.hidden = i !== next; });
    lightbox.querySelectorAll('[data-receipt-go]').forEach((thumb, i) => thumb.classList.toggle('is-active', i === next));
    lightbox.dataset.receiptIndex = String(next);
};

const openModal = (modal) => {
    if (!modal) {
        return;
    }

    if (modal.matches('[data-receipt-lightbox]')) {
        modal.querySelectorAll('img[data-src]').forEach((img) => { img.src = img.dataset.src; img.removeAttribute('data-src'); });
        showReceiptSlide(modal, 0);
    }

    document.querySelectorAll('.modal-backdrop.is-open').forEach((openModalElement) => {
        if (openModalElement !== modal) {
            closeModal(openModalElement);
        }
    });

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    modal.querySelectorAll('[data-sales-items]').forEach(updateSalesTotals);
};

let pendingConfirmForm = null;

document.addEventListener('click', (event) => {
    const passwordToggle = event.target.closest('[data-password-toggle]');
    if (passwordToggle) {
        event.preventDefault();
        const targetId = passwordToggle.dataset.passwordToggle;
        const input = (targetId ? document.getElementById(targetId) : null) || passwordToggle.closest('.password-input-wrap')?.querySelector('input');
        if (input) {
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            passwordToggle.classList.toggle('is-visible', isPassword);
            passwordToggle.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
            passwordToggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            passwordToggle.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
            input.focus();
            if (typeof input.selectionStart === 'number') {
                const len = input.value.length;
                input.setSelectionRange(len, len);
            }
        }
        return;
    }

    const receiptStep = event.target.closest('[data-receipt-step], [data-receipt-go]');
    if (receiptStep) {
        const lightbox = receiptStep.closest('[data-receipt-lightbox]');
        const current = Number(lightbox.dataset.receiptIndex || 0);
        showReceiptSlide(lightbox, receiptStep.dataset.receiptGo !== undefined ? Number(receiptStep.dataset.receiptGo) : current + Number(receiptStep.dataset.receiptStep));
    }

    const modalButton = event.target.closest('[data-modal-open]');
    if (modalButton) {
        const modal = document.getElementById(modalButton.dataset.modalOpen);
        openModal(modal);
    }

    if (event.target.matches('[data-modal-close]') || event.target.classList.contains('modal-backdrop')) {
        closeModal(event.target.closest('.modal-backdrop') || event.target);
    }

    const modalSwap = event.target.closest('[data-modal-swap]');
    if (modalSwap) {
        closeModal(modalSwap.closest('.modal-backdrop'));
        const modal = document.getElementById(modalSwap.dataset.modalSwap);
        openModal(modal);
    }

    // "+ Add Payment" in the Edit Sale modal: jump to Payment History with the form already open.
    const openPaymentForm = event.target.closest('[data-open-payment-form]');
    if (openPaymentForm) {
        const paymentModal = document.getElementById(openPaymentForm.dataset.openPaymentForm);
        openModal(paymentModal);
        const toggle = paymentModal?.querySelector('[data-payment-form-toggle]');
        if (toggle && ! toggle.disabled) toggle.click();
    }

    const paymentFormToggle = event.target.closest('[data-payment-form-toggle], [data-payment-form-cancel]');
    if (paymentFormToggle) {
        const modal = paymentFormToggle.closest('.modal-backdrop');
        const showForm = paymentFormToggle.matches('[data-payment-form-toggle]');
        const form = modal?.querySelector('[data-payment-form]');
        const toggleRow = modal?.querySelector('[data-payment-form-toggle-row]');

        if (form) form.hidden = ! showForm;
        if (toggleRow) toggleRow.hidden = showForm;
        if (showForm) form?.querySelector('input[name="amount"]')?.focus();
        if (! showForm) form?.reset();
    }

    const tabButton = event.target.closest('[data-tab-target]');
    if (tabButton) {
        const scope = tabButton.closest('[data-tabs]');
        const target = tabButton.dataset.tabTarget;
        scope.querySelectorAll('[data-tab-target]').forEach((button) => {
            button.classList.toggle('is-active', button === tabButton);
        });
        scope.querySelectorAll('[data-tab-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.tabPanel !== target;
        });
        const heading = scope.querySelector('[data-driver-heading], [data-tab-heading]');
        if (heading && tabButton.dataset.heading) {
            heading.textContent = tabButton.dataset.heading;
        }

        const exportLink = scope.querySelector('[data-tab-export-url]');
        if (exportLink) {
            exportLink.href = exportLink.dataset.tabExportUrl.replace('__TAB__', encodeURIComponent(target));
        }

        // Keep the address bar on the active tab so a reload, filter or form redirect returns to it.
        if (tabButton.dataset.tabUrl) {
            window.history.replaceState(null, '', tabButton.dataset.tabUrl);
        }
    }

    if (event.target.closest('[data-sidebar-toggle]')) {
        document.body.classList.toggle('sidebar-open');
    }

    const printButton = event.target.closest('[data-print-page]');
    if (printButton) {
        window.print();
    }

    const sortButton = event.target.closest('[data-sort-table]');
    if (sortButton) {
        sortVisibleTable(sortButton, Number(sortButton.dataset.sortTable || 0));
    }

    const exportButton = event.target.closest('[data-export-table]');
    if (exportButton) {
        event.preventDefault();
        exportVisibleTable(exportButton);
    }

    const toastDismiss = event.target.closest('[data-toast-dismiss]');
    if (toastDismiss) {
        toastDismiss.closest('.cjp-toast')?.remove();
    }

    const confirmAccept = event.target.closest('[data-confirm-accept]');
    if (confirmAccept && pendingConfirmForm) {
        const form = pendingConfirmForm;

        pendingConfirmForm = null;
        form.dataset.confirmed = 'true';
        closeModal(confirmAccept.closest('.modal-backdrop'));
        form.requestSubmit();
    }

    const addSaleItemButton = event.target.closest('[data-sales-item-add]');
    if (addSaleItemButton) {
        const modal = addSaleItemButton.closest('.modal-backdrop');
        const items = modal?.querySelector('[data-sales-items]');
        // Edit forms carry a Stock Source row template: a new line must be sold from a stock lot.
        const template = modal?.querySelector('template[data-sales-item-template]');
        const source = template?.content.querySelector('[data-sales-item]') || items?.querySelector('[data-sales-item]:last-child');

        if (!items || !source) {
            return;
        }

        const clone = source.cloneNode(true);
        clone.querySelectorAll('input').forEach((input) => {
            input.value = '';
        });
        clone.querySelectorAll('select').forEach((select) => {
            select.selectedIndex = 0;
        });
        items.appendChild(clone);
        reindexSaleItems(items);
        updateSalesTotals(items);
    }

    const removeSaleItemButton = event.target.closest('[data-sales-item-remove]');
    if (removeSaleItemButton) {
        const items = removeSaleItemButton.closest('[data-sales-items]');

        if (!items || items.querySelectorAll('[data-sales-item]').length <= 1) {
            return;
        }

        removeSaleItemButton.closest('[data-sales-item]')?.remove();
        reindexSaleItems(items);
        updateSalesTotals(items);
    }

    const addLiftingItemButton = event.target.closest('[data-lifting-item-add]');
    if (addLiftingItemButton) {
        const form = addLiftingItemButton.closest('[data-lifting-schedule-form]');
        const items = form?.querySelector('[data-lifting-items]');
        const source = items?.querySelector('[data-lifting-item]:last-child');
        if (items && source) {
            const clone = source.cloneNode(true);
            // A new purchase starts with one Depot to Garage lift row.
            clone.querySelectorAll('[data-lift-row]').forEach((liftRow, index) => { if (index > 0) liftRow.remove(); });
            clone.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
            clone.querySelectorAll('input').forEach((input) => { input.value = ''; delete input.dataset.mirror; });
            clone.querySelectorAll('.field-error:not([data-lift-rows-warning])').forEach((message) => message.remove());
            clone.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
            const summary = clone.querySelector('[data-purchase-summary]');
            if (summary) summary.textContent = 'Select Purchase ID';
            items.appendChild(clone);
            updateLiftingSchedule(form);
        }
    }

    // Part 9: lift rows (Depot to Garage / Depot to Client) under each purchase.
    const addLiftRowButton = event.target.closest('[data-lift-row-add]');
    if (addLiftRowButton) {
        const container = addLiftRowButton.closest('[data-lift-rows]');
        const source = [...(container?.querySelectorAll('[data-lift-row]') || [])].pop();
        if (container && source) {
            const clone = source.cloneNode(true);
            clone.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
            clone.querySelectorAll('input').forEach((input) => { input.value = ''; input.dataset.mirror = '0'; });
            clone.querySelectorAll('.field-error').forEach((message) => message.remove());
            clone.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
            source.after(clone);
            // Once there are several rows, none of them follows the Amount to Lift automatically.
            container.querySelectorAll('[data-lift-amount]').forEach((input) => { input.dataset.mirror = '0'; });
            updateLiftingSchedule(addLiftRowButton.closest('[data-lifting-schedule-form]'));
        }
    }

    const removeLiftRowButton = event.target.closest('[data-lift-row-remove]');
    if (removeLiftRowButton) {
        const container = removeLiftRowButton.closest('[data-lift-rows]');
        if (container?.querySelectorAll('[data-lift-row]').length > 1) {
            removeLiftRowButton.closest('[data-lift-row]')?.remove();
            updateLiftingSchedule(container.closest('[data-lifting-schedule-form]'));
        }
    }

    const removeLiftingItemButton = event.target.closest('[data-lifting-item-remove]');
    if (removeLiftingItemButton) {
        const form = removeLiftingItemButton.closest('[data-lifting-schedule-form]');
        const items = form?.querySelector('[data-lifting-items]');
        if (items?.querySelectorAll('[data-lifting-item]').length > 1) {
            removeLiftingItemButton.closest('[data-lifting-item]')?.remove();
            updateLiftingSchedule(form);
        }
    }
});

const formatLitersText = (value) => `${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L`;

// Part 9: a lift row is Depot to Garage (tank + its stock) or Depot to Client (an open sales order). Only tanks and
// orders of the purchase's fuel type can be chosen; the fields of the other kind are hidden and not submitted.
const syncLiftRow = (liftRow, fuel) => {
    const isClient = liftRow.querySelector('[data-lift-type]')?.value === 'client';
    liftRow.querySelectorAll('[data-lift-garage]').forEach((field) => { field.hidden = isClient; });
    liftRow.querySelectorAll('[data-lift-client]').forEach((field) => { field.hidden = !isClient; });
    const tank = liftRow.querySelector('[data-allocation-tank]');
    const sale = liftRow.querySelector('[data-lift-sale]');
    [[tank, !isClient], [sale, isClient]].forEach(([select, active]) => {
        if (!select) return;
        select.disabled = !active;
        select.required = active;
        [...select.options].forEach((option) => {
            if (!option.value) return;
            const matches = fuel !== '' && option.dataset.fuelTypeId === fuel;
            option.hidden = !matches;
            option.disabled = !matches;
        });
        if (select.selectedOptions[0]?.disabled) select.value = '';
    });
    if (tank && !isClient && !tank.value) {
        const first = [...tank.options].find((option) => option.value && !option.disabled);
        if (first) tank.value = first.value;
    }
    const stock = liftRow.querySelector('[data-tank-stock]');
    const tankOption = tank?.selectedOptions?.[0];
    if (stock) stock.textContent = tankOption?.value ? formatLitersText(tankOption.dataset.stock) : 'Select a Tank';
};

const filterAllocationOptions = (row) => {
    const fuel = row.querySelector('[data-lifting-purchase]')?.selectedOptions?.[0]?.dataset.fuelTypeId || '';
    row.querySelectorAll('[data-lift-row]').forEach((liftRow) => syncLiftRow(liftRow, fuel));
};

document.addEventListener('input', (event) => {
    if (event.target.closest('[data-sales-item]')) {
        updateSalesTotals(event.target.closest('[data-sales-items]'));
    }

    if (event.target.matches('[data-sales-amount-paid]')) {
        const modal = event.target.closest('.modal-backdrop');
        updateSalesTotals(modal?.querySelector('[data-sales-items]'));
    }

    // A lift amount typed by hand no longer follows the Amount to Lift.
    if (event.target.matches('[data-lift-amount]')) event.target.dataset.mirror = '0';

    const liftingForm = event.target.closest('[data-lifting-schedule-form]');
    if (liftingForm) updateLiftingSchedule(liftingForm);
});

document.addEventListener('change', (event) => {
    // Part 9 Purchases: Bank Selection is required for Bank Transfer / Cheque, and disabled and cleared for Cash.
    if (event.target.matches('[data-payment-mode]')) syncBankSelect(event.target);

    // Part 9 Record Sales: the delivery type switches each line between Stock Source and Fuel Type.
    if (event.target.matches('[data-delivery-type]')) {
        const items = event.target.closest('[data-sale-form]')?.querySelector('[data-sales-items]');
        if (items) {
            items.dataset.deliveryTypeValue = event.target.value;
            updateSalesTotals(items);
        }
    }

    if (event.target.matches('[data-stock-lot], [data-f1-percentage], select[name$="[fuel_type_id]"]') && event.target.closest('[data-sales-item]')) {
        updateSalesTotals(event.target.closest('[data-sales-items]'));
    }

    // Record Sales: a stock lot caps the line's quantity at what the lot has available (the server re-checks).
    if (event.target.matches('[data-stock-lot]')) {
        const available = event.target.selectedOptions?.[0]?.dataset.available;
        const quantity = event.target.closest('[data-sales-item]')?.querySelector('input[name$="[quantity_liters]"]');
        if (quantity) {
            if (available) quantity.max = available; else quantity.removeAttribute('max');
        }
    }

    if (event.target.matches('[data-lifting-purchase]')) {
        filterAllocationOptions(event.target.closest('[data-lifting-item]'));
    }

    const liftingForm = event.target.closest('[data-lifting-schedule-form]');
    if (liftingForm) updateLiftingSchedule(liftingForm);
});

// Submit buttons of a form, including ones placed in a modal footer via the form="..." attribute.
const submitButtonsFor = (form) => [
    ...form.querySelectorAll('button[type="submit"]'),
    ...(form.id ? document.querySelectorAll(`button[type="submit"][form="${form.id}"]`) : []),
];

const updateLiftingSchedule = (form) => {
    if (!form) return;
    const rows = [...form.querySelectorAll('[data-lifting-item]')];
    const truckOption = form.querySelector('[name="truck_id"]')?.selectedOptions?.[0];
    const capacity = Number(truckOption?.dataset.capacity || 0);
    const selectedIds = new Set();
    let total = 0;
    let depotId = null;
    let depotLabel = '';
    let error = '';
    // Part 9: an order's F1 is loaded at the garage with its first lift row only; F1 tanks are shared across rows.
    const f1SalesSeen = new Set();
    const f1ByTank = {};

    rows.forEach((row, index) => {
        row.querySelectorAll('[name]:not([data-lift-field])').forEach((field) => {
            field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`);
        });
        row.querySelectorAll('[id]:not([data-lift-field])').forEach((field) => {
            const label = row.querySelector(`label[for="${field.id}"]`);
            field.id = field.id.replace(/_\d+$/, `_${index}`);
            label?.setAttribute('for', field.id);
        });
        const select = row.querySelector('[data-lifting-purchase]');
        const option = select?.selectedOptions?.[0];
        const quantityInput = row.querySelector('[data-lifting-quantity]');
        const quantity = Number(quantityInput?.value || 0);
        const remaining = Number(option?.dataset.remaining || 0);
        const currentDepot = option?.dataset.depotId || null;
        const summary = row.querySelector('[data-purchase-summary]');
        total += Number.isFinite(quantity) ? quantity : 0;

        if (option?.value) {
            if (selectedIds.has(option.value)) error ||= 'The same Purchase ID cannot be added twice.';
            selectedIds.add(option.value);
            if (depotId && currentDepot !== depotId) error ||= 'All purchases must use the same pickup depot.';
            depotId ||= currentDepot;
            // The depot label is built server-side, so no separator character is ever encoded in JS.
            depotLabel ||= option.dataset.pickup || '';
            if (quantity > remaining) error ||= 'An amount to lift exceeds its remaining purchase quantity.';
            if (quantityInput) quantityInput.max = String(remaining);
            if (summary) summary.textContent = `${option.dataset.fuel} \u00b7 ${remaining.toLocaleString()} L remaining`;
        } else if (summary) {
            summary.textContent = 'Select Purchase ID';
        }

        // Part 9: the lift rows must add up exactly to the Amount to Lift.
        const liftRows = [...row.querySelectorAll('[data-lift-row]')];
        let liftTotal = 0;
        let rowError = '';
        liftRows.forEach((liftRow, liftIndex) => {
            liftRow.querySelectorAll('[data-lift-field]').forEach((field) => {
                const label = liftRow.querySelector(`label[for="${field.id}"]`);
                field.name = `items[${index}][lifts][${liftIndex}][${field.dataset.liftField}]`;
                field.id = field.id.replace(/_\d+_\d+$/, `_${index}_${liftIndex}`);
                label?.setAttribute('for', field.id);
            });
            syncLiftRow(liftRow, option?.dataset.fuelTypeId || '');
            const amountInput = liftRow.querySelector('[data-lift-amount]');
            // A single row follows the Amount to Lift until the user types its amount.
            if (amountInput && liftRows.length === 1 && (amountInput.value === '' || amountInput.dataset.mirror === '1') && quantityInput?.value) {
                amountInput.value = quantityInput.value;
                amountInput.dataset.mirror = '1';
            }
            const amount = Number(amountInput?.value || 0);
            liftTotal += Number.isFinite(amount) ? amount : 0;
            const saleOption = liftRow.querySelector('[data-lift-sale]:not(:disabled)')?.selectedOptions?.[0];
            if (saleOption?.value && amount > Number(saleOption.dataset.open || 0)) {
                rowError ||= `A Depot to Client amount is more than the ${formatLitersText(saleOption.dataset.open)} left on its sales order.`;
            }

            const f1Liters = saleOption?.value && !f1SalesSeen.has(saleOption.value) ? Number(saleOption.dataset.f1 || 0) : 0;
            if (saleOption?.value) f1SalesSeen.add(saleOption.value);
            const f1Block = liftRow.querySelector('[data-lift-f1]');
            const f1Tank = liftRow.querySelector('[data-lift-f1-tank]');
            if (f1Block) f1Block.hidden = f1Liters <= 0;
            if (f1Tank) {
                f1Tank.disabled = f1Liters <= 0;
                f1Tank.required = f1Liters > 0;
                if (f1Liters > 0 && !f1Tank.value) {
                    const first = [...f1Tank.options].find((option) => option.value);
                    if (first) f1Tank.value = first.value;
                }
                const f1Option = f1Tank.selectedOptions?.[0];
                const setF1Text = (selector, value) => { const element = liftRow.querySelector(selector); if (element) element.textContent = value; };
                setF1Text('[data-lift-f1-liters]', formatLitersText(f1Liters));
                setF1Text('[data-lift-f1-stock]', f1Option?.value ? formatLitersText(f1Option.dataset.stock) : 'Select an F1 tank');
                if (f1Liters > 0 && f1Option?.value) {
                    f1ByTank[f1Option.value] = (f1ByTank[f1Option.value] || 0) + f1Liters;
                    if (f1ByTank[f1Option.value] > Number(f1Option.dataset.stock || 0)) {
                        rowError ||= `${f1Option.textContent.trim()} has only ${formatLitersText(f1Option.dataset.stock)} of F1 for the ${formatLitersText(f1ByTank[f1Option.value])} to load at the garage.`;
                    }
                }
            }
            const removeButton = liftRow.querySelector('[data-lift-row-remove]');
            if (removeButton) removeButton.disabled = liftRows.length === 1;
        });
        if (liftRows.length > 0 && Math.round(liftTotal * 100) !== Math.round(quantity * 100)) {
            rowError ||= `The lift rows add up to ${formatLitersText(liftTotal)} but the Amount to Lift is ${formatLitersText(quantity)}. They must be equal.`;
        }
        const setRowText = (selector, value) => { const element = row.querySelector(selector); if (element) element.textContent = value; };
        setRowText('[data-lift-rows-total]', formatLitersText(liftTotal));
        setRowText('[data-lift-rows-target]', formatLitersText(quantity));
        setRowText('[data-lift-rows-warning]', rowError);
        if (rowError) error ||= 'Fix the lift rows of each purchase before scheduling.';
    });

    if (capacity > 0 && total > capacity) error ||= 'Combined quantity exceeds the selected truck capacity.';
    const format = (value) => `${Math.max(0, value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L`;
    const setText = (selector, value) => { const element = form.querySelector(selector); if (element) element.textContent = value; };
    setText('[data-truck-capacity]', format(capacity));
    setText('[data-lifting-total]', format(total));
    setText('[data-lifting-remaining]', format(capacity - total));
    setText('[data-pickup-depot]', depotLabel || 'Select a purchase to derive the official depot and address.');
    setText('[data-lifting-warning]', error);
    rows.forEach((row) => { const button = row.querySelector('[data-lifting-item-remove]'); if (button) button.disabled = rows.length === 1; });
    const submit = submitButtonsFor(form)[0];
    if (submit) submit.disabled = Boolean(error) || rows.some((row) => !row.querySelector('[data-lifting-purchase]')?.value) || capacity <= 0 || total <= 0;
};

document.querySelectorAll('[data-lifting-item]').forEach(filterAllocationOptions);
document.querySelectorAll('[data-lifting-schedule-form]').forEach(updateLiftingSchedule);

// Reopen a modal after a redirect (e.g. Payment History after recording a payment).
const modalToReopen = document.querySelector('[data-open-modal-on-load]')?.dataset.openModalOnLoad;
if (modalToReopen) openModal(document.getElementById(modalToReopen));

const reindexSaleItems = (items) => {
    const rows = items.querySelectorAll('[data-sales-item]');

    rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`);
        });

        row.querySelectorAll('[id]').forEach((field) => {
            const oldId = field.id;
            const newId = oldId.replace(/_(\d+|new)$/, `_${index}`);
            field.id = newId;

            const label = row.querySelector(`label[for="${oldId}"]`);
            if (label) {
                label.setAttribute('for', newId);
            }
        });

        const removeButton = row.querySelector('[data-sales-item-remove]');
        if (removeButton) {
            removeButton.disabled = rows.length === 1;
        }
    });
};

const updateSalesTotals = (items) => {
    if (!items) {
        return;
    }

    let total = 0;

    items.querySelectorAll('[data-sales-item]').forEach((row) => {
        syncSaleItemRow(row, items);
        const quantity = Number(row.querySelector('input[name*="[quantity_liters]"]')?.value || 0);
        const unitPrice = Number(row.querySelector('input[name*="[unit_price]"]')?.value || 0);
        const lineTotal = Number.isFinite(quantity * unitPrice) ? quantity * unitPrice : 0;
        const output = row.querySelector('[data-sales-line-total]');

        total += lineTotal;

        if (output) {
            output.textContent = lineTotal.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }
    });

    const scope = items.closest('.modal-backdrop') || items.parentElement;
    const amountPaidInput = scope?.querySelector('[data-sales-amount-paid]');
    const amountPaid = Number(amountPaidInput?.value || 0);
    const normalizedAmountPaid = Number.isFinite(amountPaid) ? amountPaid : 0;
    const remainingBalance = Math.max(0, total - normalizedAmountPaid);
    const formatMoney = (value) => value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    if (amountPaidInput) {
        amountPaidInput.max = total.toFixed(2);
        amountPaidInput.setCustomValidity(
            normalizedAmountPaid > total ? 'Amount paid cannot exceed the sale total.' : ''
        );
    }

    const totalOutput = scope?.querySelector('[data-sales-total-preview]');
    if (totalOutput) {
        totalOutput.textContent = totalOutput.closest('[data-sales-payment-summary]')
            ? `PHP ${formatMoney(total)}`
            : `Total: ${formatMoney(total)}`;
    }

    const balanceOutput = scope?.querySelector('[data-sales-balance-preview]');
    if (balanceOutput) {
        balanceOutput.textContent = `PHP ${formatMoney(remainingBalance)}`;
    }

    const paymentStatusOutput = scope?.querySelector('[data-sales-payment-status-preview]');
    if (paymentStatusOutput) {
        let paymentStatus = 'Unpaid';
        if (normalizedAmountPaid > 0) {
            paymentStatus = total > 0 && normalizedAmountPaid >= total ? 'Fully Paid' : 'Partially Paid';
        }
        paymentStatusOutput.textContent = paymentStatus;
    }
};

document.querySelectorAll('[data-sales-items]').forEach(updateSalesTotals);

// Part 9: Bank Selection only shows for Bank Transfer / Cheque; otherwise it is hidden and cleared.
function syncBankSelect(modeSelect) {
    const bank = document.getElementById(modeSelect.dataset.paymentMode);
    if (!bank) return;
    const needsBank = ['bank_transfer', 'cheque'].includes(modeSelect.value);
    const row = bank.closest('[data-bank-row]');
    if (row) row.hidden = !needsBank;
    bank.disabled = !needsBank;
    bank.required = needsBank;
    if (!needsBank) bank.value = '';
}

document.querySelectorAll('[data-payment-mode]').forEach(syncBankSelect);

// Part 9 Record Sales: a line shows Stock Source (Garage to Client) or Fuel Type (Depot to Client). F1 Percentage
// applies to Unleaded / Premium lines only (for Depot to Client the F1 is loaded at the garage); otherwise it is
// completely hidden, cleared, and counts as 0%.
function syncSaleItemRow(row, items) {
    const isDepot = items.dataset.deliveryTypeValue === 'depot_to_client';
    row.querySelectorAll('[data-garage-only]').forEach((field) => {
        field.hidden = isDepot;
        field.querySelectorAll('select, input').forEach((input) => { input.disabled = isDepot; });
    });
    row.querySelectorAll('[data-depot-only]').forEach((field) => {
        field.hidden = !isDepot;
        field.querySelectorAll('select, input').forEach((input) => { input.disabled = !isDepot; });
    });

    const activeFuelSelect = [...row.querySelectorAll('select[name$="[stock_lot_id]"], select[name$="[fuel_type_id]"]')]
        .find((select) => !select.disabled);
    const fuelOption = activeFuelSelect?.selectedOptions?.[0];

    const fuelTypeValue = (fuelOption?.dataset?.fuelType || fuelOption?.dataset?.fuelName || '').trim().toLowerCase();
    const fuelCodeValue = (fuelOption?.dataset?.fuelCode || '').trim().toUpperCase();
    const eligible = Boolean(fuelOption?.value) && (
        fuelTypeValue === 'unleaded' ||
        fuelTypeValue === 'premium' ||
        fuelCodeValue === 'UNL' ||
        fuelCodeValue === 'PREM'
    );

    const f1Field = row.querySelector('[data-f1-field]');
    const f1Select = row.querySelector('[data-f1-percentage]');
    const pricePair = f1Field?.closest('.sale-pair') || row.querySelector('.sale-pair');

    if (f1Field) {
        f1Field.hidden = !eligible;
    }
    if (pricePair) {
        pricePair.classList.toggle('has-no-f1', !eligible);
    }
    if (f1Select) {
        if (!eligible) {
            f1Select.value = '0';
        }
        f1Select.disabled = !eligible;
    }

    const breakdown = row.querySelector('[data-f1-breakdown]');
    if (!breakdown) return;

    const percent = eligible ? Number(f1Select?.value || 0) : 0;
    const showBreakdown = eligible && percent > 0;
    breakdown.hidden = !showBreakdown;

    const quantity = Number(row.querySelector('input[name*="[quantity_liters]"]')?.value || 0);
    const ordered = (showBreakdown && Number.isFinite(quantity)) ? quantity : 0;
    const f1Amount = showBreakdown ? Math.round(ordered * percent) / 100 : 0;
    const mainAmount = showBreakdown ? Math.round((ordered - f1Amount) * 100) / 100 : 0;
    const format = (value) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const set = (selector, value) => {
        const element = breakdown.querySelector(selector);
        if (element) element.textContent = value;
    };

    if (showBreakdown) {
        set('[data-f1-ordered]', format(ordered));
        set('[data-f1-percent]', `${percent}%`);
        set('[data-f1-amount]', format(f1Amount));
        const fuelName = fuelOption?.dataset?.fuelType || fuelOption?.dataset?.fuelName || 'Fuel';
        set('[data-f1-main-label]', `${fuelName} Amount`);
        set('[data-f1-main]', format(mainAmount));
        set('[data-f1-total]', format(mainAmount + f1Amount));
    } else {
        set('[data-f1-ordered]', '0.00');
        set('[data-f1-percent]', '0%');
        set('[data-f1-amount]', '0.00');
        set('[data-f1-main-label]', 'Fuel Amount');
        set('[data-f1-main]', '0.00');
        set('[data-f1-total]', '0.00');
    }
}

const visibleTableFor = (trigger) => {
    const scope = trigger.closest('[data-tabs]') || trigger.closest('section') || document;
    const visiblePanel = [...scope.querySelectorAll('[data-tab-panel]')]
        .find((panel) => ! panel.hidden);

    return (visiblePanel || scope).querySelector('table');
};

const sortVisibleTable = (trigger, columnIndex) => {
    const table = visibleTableFor(trigger);
    const tbody = table?.tBodies?.[0];

    if (! tbody) {
        return;
    }

    const direction = trigger.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
    const rows = [...tbody.rows].filter((row) => ! row.querySelector('.empty-cell'));

    rows.sort((first, second) => {
        const a = first.cells[columnIndex]?.textContent.trim() || '';
        const b = second.cells[columnIndex]?.textContent.trim() || '';
        const comparison = a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });

        return direction === 'asc' ? comparison : -comparison;
    });

    rows.forEach((row) => tbody.appendChild(row));
    trigger.dataset.sortDirection = direction;
};

const exportVisibleTable = (trigger) => {
    const scope = trigger.closest('[data-tabs]') || document;
    const table = visibleTableFor(trigger);

    if (! table) {
        return;
    }

    // Export exactly what the table shows: rows hidden by the live search are left out.
    // A stacked cell exports one column per [data-export-part] line, so files keep one value per column.
    const csvValue = (node) => `"${node.textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""')}"`;
    const rows = [...table.querySelectorAll('tr')]
        .filter((row) => ! row.hidden)
        .map((row) => [...row.querySelectorAll('th, td')]
            .flatMap((cell) => {
                const parts = cell.querySelectorAll('[data-export-part]');

                return parts.length > 0 ? [...parts].map(csvValue) : [csvValue(cell)];
            })
            .join(','))
        .filter(Boolean);

    if (rows.length === 0) {
        return;
    }

    const title = document.querySelector('h1')?.textContent || 'CJP Export';
    const activeTab = scope.querySelector('[data-tab-target].is-active')?.textContent || 'Table';
    const csv = [
        `"${title.replace(/"/g, '""')}"`,
        `"${activeTab.trim().replace(/"/g, '""')}"`,
        `"Generated At","${new Date().toLocaleString()}"`,
        '',
        ...rows,
    ].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const safeName = `${title}-${activeTab}`.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

    link.href = URL.createObjectURL(blob);
    link.download = `${safeName || 'cjp-export'}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
};

document.addEventListener('input', (event) => {
    const search = event.target;

    if (!(search instanceof HTMLInputElement) || search.type !== 'search') {
        return;
    }

    const table = visibleTableFor(search);

    if (! table) {
        return;
    }

    const query = search.value.trim().toLowerCase();
    [...table.tBodies[0]?.rows || []].forEach((row) => {
        if (row.querySelector('.empty-cell')) {
            return;
        }

        row.hidden = query !== '' && ! row.textContent.toLowerCase().includes(query);
    });
});

// Reports period filter: show only the inputs the chosen period needs, and apply once they are filled in.
const syncReportPeriodFields = (form) => {
    const period = form.querySelector('[data-report-period]')?.value;

    form.querySelectorAll('[data-period-field]').forEach((field) => {
        const active = field.dataset.periodField === period;
        field.hidden = !active;
        field.disabled = !active;
    });
};

document.querySelectorAll('form[data-report-filter]').forEach(syncReportPeriodFields);

document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-report-filter]');
    if (!form) {
        return;
    }

    if (event.target.matches('[data-report-period]')) {
        syncReportPeriodFields(form);
    }

    const fields = [...form.querySelectorAll('[data-period-field]:not([disabled])')];
    if (fields.every((field) => field.value !== '' && field.checkValidity())) {
        form.requestSubmit();
    }
});

// Filter forms marked [data-auto-filter] apply on change (selects/dates) and after a pause in typing (search).
const AUTO_FILTER_FOCUS_KEY = 'cjp:auto-filter-focus';
let autoFilterTimer = null;

const submitAutoFilter = (form, field) => {
    window.clearTimeout(autoFilterTimer);

    try {
        sessionStorage.setItem(AUTO_FILTER_FOCUS_KEY, JSON.stringify({ action: form.action, name: field?.name || '' }));
    } catch (error) {
        // Storage unavailable: the filter still applies, focus just isn't restored.
    }

    form.requestSubmit();
};

document.addEventListener('change', (event) => {
    const form = event.target.closest('form[data-auto-filter]');
    if (form && event.target.matches('select, input[type="date"]')) {
        submitAutoFilter(form, event.target);
    }
});

document.addEventListener('input', (event) => {
    const form = event.target.closest('form[data-auto-filter]');
    if (form && event.target.matches('input[type="search"]')) {
        window.clearTimeout(autoFilterTimer);
        autoFilterTimer = window.setTimeout(() => submitAutoFilter(form, event.target), 500);
    }
});

(() => {
    let pending = null;

    try {
        pending = JSON.parse(sessionStorage.getItem(AUTO_FILTER_FOCUS_KEY) || 'null');
        sessionStorage.removeItem(AUTO_FILTER_FOCUS_KEY);
    } catch (error) {
        pending = null;
    }

    if (! pending?.name) {
        return;
    }

    const form = [...document.querySelectorAll('form[data-auto-filter]')]
        .find((candidate) => candidate.action === pending.action && ! candidate.closest('[hidden]'));
    const field = form?.elements.namedItem(pending.name);

    if (field instanceof HTMLElement) {
        field.focus();
        if (field instanceof HTMLInputElement && field.type === 'search') {
            const length = field.value.length;
            field.setSelectionRange(length, length);
        }
    }
})();

document.addEventListener('keydown', (event) => {
    const openLightbox = document.querySelector('[data-receipt-lightbox].is-open');
    if (openLightbox && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
        showReceiptSlide(openLightbox, Number(openLightbox.dataset.receiptIndex || 0) + (event.key === 'ArrowRight' ? 1 : -1));
    }

    // Escape on an open dropdown list only closes that list (see select-picker.css), not the modal around it.
    if (event.key === 'Escape' && !isOpenSelectList(event.target)) {
        document.querySelectorAll('.modal-backdrop.is-open').forEach(closeModal);
        document.body.classList.remove('sidebar-open');
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const confirmation = form.dataset.confirmMessage;
    if (confirmation && form.dataset.confirmed !== 'true') {
        event.preventDefault();
        pendingConfirmForm = form;

        const confirmModal = document.getElementById('system-confirm-modal');
        const confirmMessage = confirmModal?.querySelector('#system-confirm-message');

        if (confirmMessage) {
            confirmMessage.textContent = confirmation;
        }

        openModal(confirmModal);

        return;
    }

    delete form.dataset.confirmed;

    const shouldPreventDoubleSubmit = form.matches('[data-ai-generate-form], [data-prevent-double-submit]')
        // getAttribute: a field named "method" (e.g. the payment form) shadows form.method.
        || (form.getAttribute('method') || 'get').toLowerCase() !== 'get';

    if (! shouldPreventDoubleSubmit) {
        return;
    }

    if (form.dataset.submitted === 'true') {
        event.preventDefault();

        return;
    }

    form.dataset.submitted = 'true';
    form.setAttribute('aria-busy', 'true');
    submitButtonsFor(form).forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
    });
});

const renderDashboardBarChart = (canvas, fallbackSelector, yTickFormatter, fallbackFormatter) => {
    if (canvas.dataset.chartRendered === 'true') {
        return;
    }

    let chartData;
    try {
        chartData = JSON.parse(canvas.dataset.chart || '{"labels":[],"datasets":[]}');
    } catch (error) {
        canvas.hidden = true;

        return;
    }

    const fallback = canvas.parentElement?.querySelector(fallbackSelector);

    try {
        new Chart(canvas, {
            type: 'bar',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => context.dataset.formattedData?.[context.dataIndex] || fallbackFormatter(context.raw),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: yTickFormatter,
                        },
                    },
                },
            },
        });
    } catch (error) {
        canvas.hidden = true;

        return;
    }

    canvas.dataset.chartRendered = 'true';

    if (fallback) {
        fallback.hidden = true;
    }
};

document.querySelectorAll('[data-sales-trend-chart]').forEach((canvas) => {
    renderDashboardBarChart(
        canvas,
        '.sales-trend-fallback',
        (value) => `PHP ${Number(value).toLocaleString()}`,
        (value) => `PHP ${Number(value || 0).toLocaleString()}`,
    );
});

document.querySelectorAll('[data-stock-level-chart]').forEach((canvas) => {
    renderDashboardBarChart(
        canvas,
        '.stock-level-fallback',
        (value) => `${Number(value).toLocaleString()} L`,
        (value) => `${Number(value || 0).toLocaleString()} L`,
    );
});

document.querySelectorAll('[data-unlifted-fuel-chart]').forEach((canvas) => {
    renderDashboardBarChart(
        canvas,
        '.unlifted-fuel-fallback',
        (value) => `${Number(value).toLocaleString()} L`,
        (value) => `${Number(value || 0).toLocaleString()} L`,
    );
});

document.querySelectorAll('[data-inventory-variance-chart]').forEach((canvas) => {
    renderDashboardBarChart(
        canvas,
        '.inventory-variance-fallback',
        (value) => Number(value).toLocaleString(),
        (value) => Number(value || 0).toLocaleString(),
    );
});

document.querySelectorAll('[data-receivables-chart]').forEach((canvas) => {
    if (canvas.dataset.chartRendered === 'true') return;

    let chartData;
    try {
        chartData = JSON.parse(canvas.dataset.chart || '{"labels":[],"datasets":[]}');
    } catch {
        canvas.hidden = true;
        return;
    }

    const fallback = canvas.parentElement?.querySelector('.receivables-fallback');

    try {
        new Chart(canvas, {
            type: 'bar',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) =>
                                context.dataset.formattedData?.[context.dataIndex]
                                || `PHP ${Number(context.raw || 0).toLocaleString()}`,
                        },
                    },
                },
                scales: {
                    x: {
                        // Always show both x-axis labels even when one bar value is 0
                        ticks: { display: true },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => `PHP ${Number(value).toLocaleString()}`,
                        },
                    },
                },
                // Ensure zero-value bars always render as a visible stub
                datasets: { bar: { minBarLength: 6 } },
            },
        });
    } catch {
        canvas.hidden = true;
        return;
    }

    canvas.dataset.chartRendered = 'true';
    if (fallback) fallback.hidden = true;
    renderDashboardBarChart(
        canvas,
        '.receivables-fallback',
        (value) => `PHP ${Number(value).toLocaleString()}`,
        (value) => `PHP ${Number(value || 0).toLocaleString()}`,
    );
});

document.querySelectorAll('[data-expected-revenue-chart]').forEach((canvas) => {
    renderDashboardBarChart(
        canvas,
        '.expected-revenue-fallback',
        (value) => `PHP ${Number(value).toLocaleString()}`,
        (value) => `PHP ${Number(value || 0).toLocaleString()}`,
    );
});

// -- Responsive table scroll helpers ----------------------------------------

/**
 * Toggle the `.is-scrollable` class on every `.table-wrap` whose inner table
 * is wider than the visible container width.  The class activates the CSS
 * scroll-hint ("<- Scroll sideways to view more columns ->").
 */
const syncScrollHints = () => {
    document.querySelectorAll('.table-wrap').forEach((wrap) => {
        const table = wrap.querySelector('table');
        if (!table) return;
        const isScrollable = table.scrollWidth > wrap.clientWidth + 2; // 2 px tolerance
        wrap.classList.toggle('is-scrollable', isScrollable);
    });
};

// Run on load and whenever the viewport resizes / fonts load
syncScrollHints();
window.addEventListener('resize', syncScrollHints, { passive: true });
document.fonts?.ready?.then(syncScrollHints);

/**
 * Convert Shift + vertical mouse-wheel into horizontal scroll on the nearest
 * `.table-wrap` ancestor so users without a touchpad can scroll wide tables
 * using their scroll wheel.
 */
document.addEventListener('wheel', (event) => {
    const wrap = event.target.closest('.table-wrap');
    if (!wrap) return;
    if (!event.shiftKey) return;

    event.preventDefault();
    wrap.scrollLeft += event.deltaY;
}, { passive: false });
