import './bootstrap';
import '../css/part9.css';
import '../css/select-picker.css';
import './select-picker';
// Part 10: Dashboard and Analytics charts.
import '../css/dashboard.css';
import './dashboard';
// Part 2 revisions: Schedule Lift (purchases + Depot to Client deliveries).
import './lift-schedule';

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

});

document.addEventListener('input', (event) => {
    if (event.target.closest('[data-sales-item]')) {
        updateSalesTotals(event.target.closest('[data-sales-items]'));
    }

    if (event.target.matches('[data-sales-amount-paid]')) {
        const modal = event.target.closest('.modal-backdrop');
        updateSalesTotals(modal?.querySelector('[data-sales-items]'));
    }

});

document.addEventListener('change', (event) => {
    // Part 9 Purchases: Bank Selection is required for Bank Transfer / Cheque, and disabled and cleared for Cash.
    if (event.target.matches('[data-payment-mode]')) syncBankSelect(event.target);

    // Part 2 revisions Record Sales: Delivery Details (truck, driver, date, status) belong to Standard orders only.
    if (event.target.matches('[data-delivery-type]')) syncDeliveryType(event.target);

    if (event.target.closest('[data-sales-item]')) {
        if (event.target.matches('[data-sale-f1-purchase]')) {
            const row = event.target.closest('[data-sales-item]');
            const price = row?.querySelector('[data-sale-f1-price]');
            const selected = event.target.selectedOptions[0];
            // Seed the editable F1 price from the purchase cost once; subsequent edits remain user-controlled.
            if (price && !price.value && selected?.dataset.unitPrice) {
                price.value = selected.dataset.unitPrice;
            }
        }
        updateSalesTotals(event.target.closest('[data-sales-items]'));
    }


});

// Submit buttons of a form, including ones placed in a modal footer via the form="..." attribute.
const submitButtonsFor = (form) => [
    ...form.querySelectorAll('button[type="submit"]'),
    ...(form.id ? document.querySelectorAll(`button[type="submit"][form="${form.id}"]`) : []),
];


// Reopen a modal after a redirect (e.g. Payment History after recording a payment).
const modalToReopen = document.querySelector('[data-open-modal-on-load]')?.dataset.openModalOnLoad;
if (modalToReopen) openModal(document.getElementById(modalToReopen));

const updateSalesTotals = (items) => {
    if (!items) {
        return;
    }

    let total = 0;

    items.querySelectorAll('[data-sales-item]').forEach((row) => {
        total += syncSaleItemRow(row);
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

// Part 2 revisions Record Sales: one order line. The Fuel Type is a purchase; an Unleaded / Premium line can carry an
// F1 portion (F1 purchase, F1 Price, F1 %), otherwise the F1 row is hidden and counts as 0%.
// Premium Total = main liters x Fuel Price, F1 Total = F1 liters x F1 Price, Line Total = Premium Total + F1 Total.
// Returns the Line Total. The server re-checks prices and stock.
function syncSaleItemRow(row) {
    const format = (value) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const set = (selector, value) => { const element = row.querySelector(selector); if (element) element.textContent = value; };
    const purchase = row.querySelector('[data-sale-purchase]');
    const fuelSource = purchase?.value ? purchase.selectedOptions[0] : row.querySelector('[data-legacy-fuel]');
    const fuelCode = (fuelSource?.dataset?.fuelCode || '').toUpperCase();
    const fuelName = fuelSource?.dataset?.fuelName || 'Fuel';
    const eligible = ['UNL', 'PREM'].includes(fuelCode);

    const f1Row = row.querySelector('[data-f1-row]');
    const f1Select = row.querySelector('[data-f1-percentage]');
    if (f1Row) f1Row.hidden = !eligible;
    if (!eligible && f1Select) f1Select.value = '0';
    row.querySelectorAll('[data-f1-row] select, [data-f1-row] input').forEach((field) => { field.disabled = !eligible; });

    const quantity = Number(row.querySelector('[data-sale-quantity]')?.value || 0) || 0;
    const percent = eligible ? Number(f1Select?.value || 0) : 0;
    const f1 = Math.round(quantity * percent) / 100;
    const main = Math.round((quantity - f1) * 100) / 100;
    const f1Purchase = row.querySelector('[data-sale-f1-purchase]');
    const f1Price = row.querySelector('[data-sale-f1-price]');
    if (f1Purchase) f1Purchase.required = eligible && percent > 0;
    if (f1Price) f1Price.required = eligible && percent > 0;

    const price = Number(row.querySelector('[data-sale-price]')?.value || 0) || 0;
    const mainTotal = Math.round(main * price * 100) / 100;
    const f1Total = percent > 0 ? Math.round(f1 * (Number(f1Price?.value || 0) || 0) * 100) / 100 : 0;
    const lineTotal = mainTotal + f1Total;

    set('[data-f1-ordered]', format(quantity));
    set('[data-f1-percent]', `${percent}%`);
    // The breakdown is monetary: quantities are already shown by Ordered Amount and the F1 (L) / Fuel (L) fields.
    // Keep the labels stable even when the primary fuel itself is F1; otherwise the bar showed “F1 Amount” twice.
    set('[data-f1-main-label]', 'Fuel Amount');
    set('[data-f1-main]', format(mainTotal));
    set('[data-f1-amount]', format(f1Total));
    set('[data-f1-total]', format(lineTotal));
    set('[data-main-total-label]', 'Fuel Total');
    set('[data-sales-main-total]', format(mainTotal));
    set('[data-sales-f1-total]', format(f1Total));
    set('[data-sales-line-total]', format(lineTotal));

    // Each purchase may only give what it has available.
    const quantityInput = row.querySelector('[data-sale-quantity]');
    const available = purchase?.value ? Number(purchase.selectedOptions[0].dataset.available || 0) : Infinity;
    quantityInput?.setCustomValidity(main > available ? `Only ${format(available)} L of this purchase is available.` : '');
    const f1Available = f1Purchase?.value ? Number(f1Purchase.selectedOptions[0].dataset.available || 0) : Infinity;
    f1Purchase?.setCustomValidity(eligible && f1 > f1Available ? `Only ${format(f1Available)} L of this F1 purchase is available.` : '');

    return lineTotal;
}

function syncDeliveryType(select) {
    const form = select.closest('[data-sale-form]');
    const standard = select.value !== 'depot_to_client';
    form?.querySelectorAll('[data-standard-only]').forEach((section) => {
        section.hidden = !standard;
        section.querySelectorAll('select, input').forEach((field) => { field.disabled = !standard; });
    });
}

document.querySelectorAll('[data-delivery-type]').forEach(syncDeliveryType);

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

// Part 11: Withdrawal receipt upload (driver Schedule modal). Picking an image previews it; Upload sends it in the
// background, shows progress and the result, then updates the modal and the table in place (no page refresh).
// Without JavaScript the form still posts normally.
const WITHDRAWAL_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const WITHDRAWAL_MAX_BYTES = 5 * 1024 * 1024;

document.querySelectorAll('[data-withdrawal-upload]').forEach((form) => {
    const input = form.querySelector('input[type="file"]');
    const button = form.querySelector('[data-upload-button]');
    const preview = form.querySelector('[data-withdrawal-preview]');
    const previewImage = form.querySelector('[data-preview-image]');
    const previewName = form.querySelector('[data-preview-name]');
    const message = form.querySelector('[data-upload-message]');
    const modal = form.closest('.modal-backdrop');
    let previewUrl = null;

    const say = (text, type) => {
        message.textContent = text;
        message.className = `withdrawal-upload-message is-${type}`;
        message.hidden = false;
    };

    input?.addEventListener('change', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        message.hidden = true;
        const file = input.files?.[0];
        if (! file) {
            preview.hidden = true;
            return;
        }
        if (! WITHDRAWAL_TYPES.includes(file.type)) {
            say('Choose a JPG, PNG or WEBP image.', 'error');
            input.value = '';
            preview.hidden = true;
            return;
        }
        if (file.size > WITHDRAWAL_MAX_BYTES) {
            say('The image is larger than 5 MB. Choose a smaller one.', 'error');
            input.value = '';
            preview.hidden = true;
            return;
        }
        previewUrl = URL.createObjectURL(file);
        previewImage.src = previewUrl;
        previewImage.alt = `Selected receipt ${file.name}`;
        previewName.textContent = `Selected: ${file.name} (${Math.max(1, Math.round(file.size / 1024))} KB)`;
        preview.hidden = false;
    });

    form.addEventListener('submit', async (event) => {
        if (! window.fetch || ! window.FormData) return;
        event.preventDefault();
        if (! input?.files?.length) {
            say('Choose the receipt image first.', 'error');
            return;
        }

        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Uploading…';
        message.hidden = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));
            if (! response.ok) {
                const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                throw new Error(first || data.message || 'The receipt could not be uploaded. Try again.');
            }

            // The modal and the table now show the upload.
            const card = modal?.querySelector('[data-withdrawal-card]');
            card?.classList.add('is-uploaded');
            const set = (selector, text) => { const el = modal?.querySelector(selector); if (el) { el.textContent = text; el.hidden = false; } };
            set('[data-withdrawal-title]', 'Withdrawal receipt: Uploaded');
            set('[data-withdrawal-uploaded-at]', `Uploaded ${data.uploaded_at}`);
            const notes = modal?.querySelector('[data-withdrawal-notes]');
            if (notes) {
                notes.textContent = data.notes ? `Notes: ${data.notes}` : '';
                notes.hidden = ! data.notes;
            }
            set('[data-detail="withdrawal-receipt"]', 'Uploaded');
            set('[data-detail="withdrawal-uploaded-at"]', data.uploaded_at);
            set('[data-detail="withdrawal-notes"]', data.notes || 'N/A');
            const link = modal?.querySelector('[data-withdrawal-link]');
            if (link) {
                link.href = data.url;
                link.querySelector('[data-withdrawal-image]').src = data.url;
                link.hidden = false;
            }
            document.querySelectorAll(`[data-receipt-status="${form.dataset.withdrawalUpload}"]`).forEach((cell) => {
                cell.textContent = 'Uploaded';
                cell.classList.add('is-uploaded');
            });

            form.reset();
            preview.hidden = true;
            form.querySelector('[data-file-label]').textContent = 'Choose replacement image';
            button.dataset.done = 'Replace Receipt';
            say(data.message || 'Withdrawal receipt uploaded successfully.', 'success');
        } catch (error) {
            say(error.message, 'error');
        } finally {
            button.disabled = false;
            button.textContent = button.dataset.done || label;
        }
    });
});
