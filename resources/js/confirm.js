// Confirmation for every data-changing form (all roles). UI only: the form, its fields and the server are unchanged.
//
// A form's confirmation comes from its attributes (see resources/views/components/confirm-dialog.blade.php):
//   data-confirm="review"  (default for POST / PATCH / PUT forms) Add: every entered field; Edit: changed fields only.
//   data-confirm="delete"  destructive: names the item, says whether it can be undone, red button, optional typed ID.
//   data-confirm="status"  a short "item: From -> To" confirm.
//   data-confirm="simple"  one sentence (data-confirm-message), e.g. Sign Out.
//   data-confirm="none"    no confirmation (exports, report generation).
// Shared: data-confirm-title, data-confirm-item, data-confirm-note (an extra line), data-confirm-message (the older
// one-line text: the question of a simple confirm, a note on a review), data-confirm-when
// ("field=value": the note applies only then), data-confirm-ok (button label), data-confirm-tone="danger".
// delete: data-confirm-action ("Delete", "Cancel", "Reject"...), data-confirm-undo, data-confirm-typed (text to type).
// status: data-confirm-from, data-confirm-to or data-confirm-to-field (a select whose chosen option is the new status).
// Fields: data-confirm-format="money|liters|percent|text" overrides how a value is shown; data-confirm-skip hides one.

const SKIPPED_NAMES = new Set(['_token', '_method', 'idempotency_key']);
const AJAX_FORMS = '[data-withdrawal-upload]';
const BUSY_TIMEOUT_MS = 20000;

// -- Formatting --------------------------------------------------------------------------------------------------------

const number = (value, decimals) => Number(value).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
const trimmedDecimals = (value) => (Math.abs(value % 1) > 0.000001 ? 2 : 0);

export const formatMoney = (value) => {
    const amount = Number(value);
    if (! Number.isFinite(amount)) return String(value);
    return `${amount < 0 ? '-' : ''}PHP ${number(Math.abs(amount), trimmedDecimals(amount))}`;
};

export const formatLiters = (value) => {
    const amount = Number(value);
    if (! Number.isFinite(amount)) return String(value);
    return `${number(amount, trimmedDecimals(amount))} L`;
};

export const formatDate = (value, withTime = false) => {
    if (! value) return '';
    const [datePart, timePart] = String(value).split('T');
    const [y, m, d] = datePart.split('-').map(Number);
    if (! y || ! m || ! d) return String(value);
    const [hh = 0, mm = 0] = (timePart || '').split(':').map(Number);
    const date = new Date(y, m - 1, d, hh, mm);
    const text = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    return withTime && timePart ? `${text}, ${date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}` : text;
};

const clean = (text) => String(text ?? '').replace(/\s+/g, ' ').replace(/[*:]\s*$/, '').trim();

const fieldKind = (el, label) => {
    if (el.dataset.confirmFormat) return el.dataset.confirmFormat;
    if (el.type !== 'number' && el.inputMode !== 'decimal') return 'text';
    const key = `${el.name} ${label}`.toLowerCase();
    if (/percent|%/.test(key)) return 'percent';
    // A field with an "L" suffix box (e.g. Amount to Lift) is a volume.
    const suffix = el.closest('.input-suffix, .input-with-suffix')?.textContent.trim();
    if (suffix === 'L') return 'liters';
    // Money words first: "Cost / Liter" is a price, not a volume.
    if (/price|cost|paid|total|balance|refund|payment|php|₱/.test(key)) return 'money';
    if (/liter|litre|quantity|qty|capacity|volume|unload|_l\b|\(l\)/.test(key)) return 'liters';
    if (/amount/.test(key)) return 'money';
    return 'number';
};

const labelFor = (el) => {
    const label = el.labels?.[0];
    if (label) {
        const copy = label.cloneNode(true);
        copy.querySelectorAll('input, select, textarea, .field-error, [hidden]').forEach((node) => node.remove());
        const text = clean(copy.textContent);
        if (text) return text;
    }
    const rowLabel = el.closest('.form-row, .login-field, .lift-field, .garage-unload-tank')?.querySelector('label, .form-label');
    if (rowLabel && clean(rowLabel.textContent)) return clean(rowLabel.textContent);
    const named = el.getAttribute('aria-label') || el.placeholder || el.name;
    return clean(named.replace(/^items\[\d+\]\[|\]$/g, '').replace(/[_\[\]]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase()));
};

// Only fields the person can see count (a hidden F1 row or delivery section is left out). File inputs are often
// visually replaced by a button, so they count whenever they are not inside a hidden block.
const isShown = (el) => ! el.closest('[hidden]') && (el.getClientRects().length > 0 || el.type === 'file');

const reviewableElements = (form) => [...form.elements].filter((el) => {
    if (! el.name || el.disabled || SKIPPED_NAMES.has(el.name) || el.dataset.confirmSkip !== undefined) return false;
    if (['hidden', 'submit', 'button', 'reset', 'image'].includes(el.type) || el.tagName === 'BUTTON' || el.tagName === 'OUTPUT' || el.tagName === 'FIELDSET') return false;
    if (el.readOnly && el.type !== 'file') return false;
    return isShown(el);
});

// The value as the person sees it: option text instead of an ID, readable dates, PHP / L formatting.
export const displayValue = (el, label = labelFor(el)) => {
    if (el.tagName === 'SELECT') {
        const options = [...el.selectedOptions].filter((option) => option.value !== '');
        return options.map((option) => clean(option.textContent)).join(', ');
    }
    if (el.type === 'checkbox') return el.checked ? 'Yes' : 'No';
    if (el.type === 'file') return [...(el.files || [])].map((file) => file.name).join(', ');
    if (el.type === 'password') return el.value ? '•••••• (new password)' : '';
    const value = el.value.trim();
    if (value === '') return '';
    if (el.type === 'date') return formatDate(value);
    if (el.type === 'datetime-local') return formatDate(value, true);
    switch (fieldKind(el, label)) {
        case 'money': return formatMoney(value);
        case 'liters': return formatLiters(value);
        case 'percent': return `${number(Number(value), trimmedDecimals(Number(value)))}%`;
        case 'number': return Number.isFinite(Number(value)) ? number(Number(value), trimmedDecimals(Number(value))) : value;
        default: return value;
    }
};

const rawValue = (el) => {
    if (el.tagName === 'SELECT') return [...el.selectedOptions].map((option) => option.value).join(',');
    if (el.type === 'checkbox' || el.type === 'radio') return el.checked ? (el.value || 'on') : '';
    if (el.type === 'file') return [...(el.files || [])].map((file) => file.name).join(',');
    return el.value;
};

const fieldKey = (form, el) => {
    const same = [...form.elements].filter((other) => other.name === el.name);
    return `${el.name}#${same.indexOf(el)}`;
};

// Line prefix for repeated rows ("items[1][...]" -> "Line 2 · ") when the form has more than one line.
const linePrefix = (form, el) => {
    const match = el.name.match(/^(\w+)\[(\d+)\]/);
    if (! match) return '';
    const lines = new Set([...form.elements].map((other) => other.name?.match(/^(\w+)\[(\d+)\]/)?.[2]).filter(Boolean));
    return lines.size > 1 ? `Line ${[...lines].indexOf(match[2]) + 1} · ` : '';
};

// -- Snapshots of the original values (Edit) ---------------------------------------------------------------------------

const snapshots = new WeakMap();

export const snapshot = (form, force = false) => {
    if (! form || (snapshots.has(form) && ! force)) return;
    const values = new Map();
    [...form.elements].forEach((el) => {
        if (! el.name || SKIPPED_NAMES.has(el.name) || el.type === 'radio' && ! el.checked) return;
        values.set(fieldKey(form, el), { raw: rawValue(el), display: displayValue(el) });
    });
    snapshots.set(form, values);
};

const formsInside = (container) => {
    const forms = new Set(container.querySelectorAll('form'));
    container.querySelectorAll('[form]').forEach((el) => { if (el.form) forms.add(el.form); });
    return [...forms];
};

// -- Review content ----------------------------------------------------------------------------------------------------

const todayIso = () => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

const noteApplies = (form) => {
    if (! form.dataset.confirmWhen) return true;
    const [name, value] = form.dataset.confirmWhen.split('=');
    const field = form.elements.namedItem(name);
    return Boolean(field) && ! field.disabled && field.value === value;
};

export const riskFlags = (form, changedKeys = null) => {
    const flags = [];
    const elements = reviewableElements(form).filter((el) => ! changedKeys || changedKeys.has(fieldKey(form, el)));

    // A date in the past (fields whose max is today expect past dates, e.g. a purchase date, and are not flagged).
    elements.filter((el) => ['date', 'datetime-local'].includes(el.type) && el.value).forEach((el) => {
        const day = el.value.slice(0, 10);
        if (day < todayIso() && ! (el.max && el.max.slice(0, 10) <= todayIso())) {
            flags.push(`${labelFor(el)} is in the past (${displayValue(el)}).`);
        }
    });

    // A quantity above the stock left in the chosen source (options carry data-available / data-remaining).
    [...form.elements].filter((el) => el.tagName === 'SELECT' && ! el.disabled).forEach((select) => {
        const option = select.selectedOptions[0];
        const available = option?.dataset.available ?? option?.dataset.remaining;
        if (available === undefined || available === '') return;
        const prefix = select.name.match(/^(\w+\[\d+\])/)?.[1];
        const quantity = [...form.elements].find((el) => el.type === 'number' && ! el.disabled
            && /quantity|liters/.test(el.name) && ! /f1/.test(el.name) && (prefix ? el.name.startsWith(prefix) : ! /\[\d+\]/.test(el.name)));
        if (quantity && quantity.value !== '' && Number(quantity.value) > Number(available) + 0.0001) {
            flags.push(`${linePrefix(form, quantity)}${labelFor(quantity)} ${formatLiters(quantity.value)} is more than the stock left in ${clean(option.textContent)} (${formatLiters(available)}).`);
        }
    });

    // A payment or refund equal to the whole limit (max) is worth a second look; above it the browser already blocks.
    elements.filter((el) => el.type === 'number' && el.max !== '' && el.value !== '' && fieldKind(el, labelFor(el)) === 'money').forEach((el) => {
        if (Number(el.value) > Number(el.max)) flags.push(`${labelFor(el)} ${formatMoney(el.value)} is above the balance (${formatMoney(el.max)}).`);
    });

    // Assigning a source changes a Standard order for good (same check app.js makes for lift forms).
    if (form.matches('[data-lifting-schedule-form], [data-standard-conversion-form]')
        && form.querySelector('option[data-delivery-type="standard"]:checked, option[data-standard-order="true"]:checked')) {
        flags.push('Assigning this source changes the order from Standard. This can only be done once.');
    }

    if (form.dataset.confirmMessage && noteApplies(form)) flags.push(form.dataset.confirmMessage);

    return flags;
};

const isEditForm = (form) => (form.dataset.confirmMode
    ? form.dataset.confirmMode === 'edit'
    : ['patch', 'put'].includes((form.querySelector('input[name="_method"]')?.value || '').toLowerCase()));

export const reviewRows = (form, editing = isEditForm(form)) => {
    const original = snapshots.get(form);
    const seenRadio = new Set();
    const rows = [];
    const changedKeys = new Set();

    reviewableElements(form).forEach((el) => {
        if (el.type === 'radio') {
            if (seenRadio.has(el.name) || ! el.checked) return;
            seenRadio.add(el.name);
        }
        const label = linePrefix(form, el) + labelFor(el);
        const display = displayValue(el);
        if (editing) {
            const before = original?.get(fieldKey(form, el));
            const changed = before ? before.raw !== rawValue(el) : display !== '';
            if (! changed) return;
            if (el.type === 'password' && ! el.value) return;
            changedKeys.add(fieldKey(form, el));
            rows.push({ label, before: before?.display || '—', after: display || '—' });
        } else if (display !== '') {
            rows.push({ label, after: display });
        }
    });

    return { editing, rows, changedKeys };
};

const dialogTitleFor = (form) => {
    if (form.dataset.confirmTitle) return form.dataset.confirmTitle;
    const modalTitle = (form.closest('.modal-backdrop') || [...form.elements].find((el) => el.closest('.modal-backdrop'))?.closest('.modal-backdrop'))
        ?.querySelector('.modal-titlebar h2')?.textContent;
    return clean(modalTitle || document.querySelector('h1')?.textContent || 'Confirm');
};

const titleCase = (text) => text.toLowerCase().replace(/\b\w/g, (c) => c.toUpperCase());

// Everything the dialog shows for one submission.
export const buildConfirmation = (form, submitter = null) => {
    const type = form.dataset.confirm || (form.querySelector('input[name="_method"]')?.value.toUpperCase() === 'DELETE' ? 'delete' : 'review');
    const item = form.dataset.confirmItem || '';
    const tone = form.dataset.confirmTone || (type === 'delete' ? 'danger' : 'primary');

    if (type === 'review') {
        const { editing, rows, changedKeys } = reviewRows(form);
        const flags = riskFlags(form, editing ? changedKeys : null);
        const subject = titleCase(dialogTitleFor(form));
        return {
            type, tone, editing, rows, flags, item,
            title: editing ? `Review changes${item ? ` to ${item}` : ''}` : `Review: ${subject}`,
            lead: editing
                ? (rows.length ? `${rows.length} field${rows.length === 1 ? '' : 's'} changed. Check them before saving.` : 'No changes. There is nothing to save.')
                : 'Check what you entered before saving.',
            noChanges: editing && rows.length === 0,
            cancelLabel: 'Back to edit',
            okLabel: form.dataset.confirmOk || 'Confirm & Save',
        };
    }

    if (type === 'status') {
        const toField = form.dataset.confirmToField ? form.elements.namedItem(form.dataset.confirmToField) : null;
        const to = form.dataset.confirmTo || (toField ? displayValue(toField) : '');
        return {
            type, tone, item, from: form.dataset.confirmFrom || '', to,
            title: form.dataset.confirmTitle || 'Change status?',
            // The old one-line message is kept as a note only when it is conditional (e.g. "Delivered deducts stock").
            flags: [form.dataset.confirmNote, form.dataset.confirmWhen && noteApplies(form) ? form.dataset.confirmMessage : null].filter(Boolean),
            cancelLabel: 'Cancel',
            okLabel: form.dataset.confirmOk || (to ? `Yes, set to ${to}` : 'Confirm'),
        };
    }

    if (type === 'delete') {
        const action = form.dataset.confirmAction || clean(submitter?.textContent) || 'Delete';
        // Fields filled in for the action (e.g. a cancel reason) are listed as entered.
        const { rows } = reviewRows(form, false);
        return {
            type, tone: 'danger', item,
            title: form.dataset.confirmTitle || `${action}?`,
            lead: item ? `${action} ${item}?` : (form.dataset.confirmMessage || `${action} this record?`),
            message: form.dataset.confirmNote || '',
            undo: form.dataset.confirmUndo || "This can't be undone.",
            typed: form.dataset.confirmTyped || '',
            rows: rows.filter((row) => row.after),
            cancelLabel: 'Keep it',
            okLabel: form.dataset.confirmOk || action,
        };
    }

    // simple
    return {
        type: 'simple', tone, item,
        title: form.dataset.confirmTitle || 'Please confirm',
        lead: form.dataset.confirmMessage || 'Continue?',
        cancelLabel: 'Cancel',
        okLabel: form.dataset.confirmOk || 'Continue',
    };
};

// -- Dialog ------------------------------------------------------------------------------------------------------------

let state = null;

const dialog = () => document.querySelector('[data-confirm-dialog]');

const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
};

const renderBody = (config) => {
    const body = el('div');
    if (config.lead) body.append(el('p', 'confirm-lead', config.lead));
    if (config.message) body.append(el('p', 'confirm-message', config.message));

    if (config.type === 'status') {
        const line = el('p', 'confirm-status-line');
        if (config.item) line.append(el('strong', 'confirm-item', config.item));
        if (config.from || config.to) {
            const change = el('span', 'confirm-change');
            if (config.from) change.append(el('span', 'confirm-old', config.from), el('span', 'confirm-arrow', '→'));
            change.append(el('span', 'confirm-new', config.to || '—'));
            line.append(change);
        }
        body.append(line);
    }

    if (config.rows?.length) {
        const list = el('dl', `confirm-summary${config.editing ? ' is-diff' : ''}`);
        config.rows.forEach((row) => {
            const item = el('div', 'confirm-row');
            item.append(el('dt', '', row.label));
            const value = el('dd');
            if (config.editing) {
                value.append(el('span', 'confirm-old', row.before), el('span', 'confirm-arrow', '→'), el('mark', 'confirm-new', row.after));
            } else {
                value.textContent = row.after;
            }
            item.append(value);
            list.append(item);
        });
        body.append(list);
    }

    if (config.flags?.length) {
        const box = el('div', 'confirm-flags');
        box.setAttribute('role', 'note');
        box.append(el('strong', '', 'Please check'));
        const list = el('ul');
        config.flags.forEach((flag) => list.append(el('li', '', flag)));
        box.append(list);
        body.append(box);
    }

    if (config.undo) body.append(el('p', 'confirm-undo', config.undo));
    return body;
};

const focusables = (root) => [...root.querySelectorAll('button, input, [href], select, textarea, [tabindex]:not([tabindex="-1"])')]
    .filter((node) => ! node.disabled && ! node.hidden && node.getClientRects().length);

const setBusy = (busy) => {
    const root = dialog();
    const ok = root.querySelector('[data-cd-ok]');
    root.classList.toggle('is-busy', busy);
    ok.disabled = busy || (state?.config.typed ? ! typedMatches() : Boolean(state?.config.noChanges));
    ok.setAttribute('aria-busy', busy ? 'true' : 'false');
    root.querySelector('[data-cd-ok-label]').textContent = busy ? 'Saving…' : state?.config.okLabel || 'Confirm';
    root.querySelectorAll('[data-cd-cancel]').forEach((button) => { button.disabled = busy; });
};

const typedMatches = () => {
    const input = dialog().querySelector('[data-cd-typed-input]');
    return input.value.trim().toUpperCase() === String(state?.config.typed || '').trim().toUpperCase();
};

const close = (result, { fromHistory = false } = {}) => {
    if (! state) return;
    const { resolve, returnFocus, pushed } = state;
    const root = dialog();
    root.hidden = true;
    root.classList.remove('is-open', 'is-busy');
    document.documentElement.classList.remove('has-confirm-open');
    state = null;
    // Leave the extra history entry when cancelling (Esc / buttons); the Back button already removed it.
    if (pushed && ! fromHistory && ! result) history.back();
    if (! result) returnFocus?.focus?.({ preventScroll: true });
    resolve(result);
};

export const openConfirm = (config, { returnFocus = document.activeElement } = {}) => new Promise((resolve) => {
    const root = dialog();
    if (! root) { resolve(true); return; }
    if (state) close(false);

    root.dataset.type = config.type;
    root.dataset.tone = config.tone || 'primary';
    root.querySelector('[data-cd-title]').textContent = config.title;
    root.querySelector('[data-cd-body]').replaceChildren(renderBody(config));

    const typedBox = root.querySelector('[data-cd-typed]');
    const typedInput = root.querySelector('[data-cd-typed-input]');
    typedBox.hidden = ! config.typed;
    typedInput.value = '';
    root.querySelector('[data-cd-typed-value]').textContent = config.typed || '';

    const ok = root.querySelector('[data-cd-ok]');
    ok.hidden = Boolean(config.noChanges);
    ok.className = `btn ${config.tone === 'danger' ? 'btn-danger' : 'btn-primary'} confirm-ok`;
    root.querySelector('[data-cd-cancel-label]').textContent = config.noChanges ? 'Back to edit' : config.cancelLabel;

    state = { resolve, config, returnFocus, pushed: false };
    setBusy(false);

    root.hidden = false;
    root.classList.add('is-open');
    document.documentElement.classList.add('has-confirm-open');

    // Back (browser / phone) cancels: an extra history entry that popstate removes.
    try {
        history.pushState({ ...(history.state || {}), cjpConfirm: true }, '');
        state.pushed = true;
    } catch { /* history unavailable (sandboxed preview) */ }

    // Focus starts on the safe button.
    requestAnimationFrame(() => root.querySelector('.confirm-cancel')?.focus({ preventScroll: true }));
});

const wireDialog = () => {
    const root = dialog();
    if (! root || root.dataset.wired) return;
    root.dataset.wired = 'true';

    root.addEventListener('click', (event) => {
        if (! state || root.classList.contains('is-busy')) return;
        if (event.target.closest('[data-cd-cancel]')) close(false);
        else if (event.target.closest('[data-cd-ok]') && ! event.target.closest('[data-cd-ok]').disabled) {
            setBusy(true);
            state.resolve(true);
            state.resolve = () => {};
        }
    });

    root.querySelector('[data-cd-typed-input]')?.addEventListener('input', () => setBusy(false));
    root.querySelector('[data-cd-typed-input]')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (typedMatches()) root.querySelector('[data-cd-ok]').click();
        }
    });

    // Esc cancels, and only the dialog: capture phase, so the edit modal underneath stays open.
    document.addEventListener('keydown', (event) => {
        if (! state) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (! root.classList.contains('is-busy')) close(false);
            return;
        }
        if (event.key === 'Tab') {
            const nodes = focusables(root.querySelector('.confirm-panel'));
            if (! nodes.length) return;
            const first = nodes[0];
            const last = nodes[nodes.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (! event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }, true);

    window.addEventListener('popstate', () => {
        if (state && ! root.classList.contains('is-busy')) close(false, { fromHistory: true });
    });
};

// -- Submit interception -----------------------------------------------------------------------------------------------

const confirmType = (form) => {
    if (form.dataset.confirm) return form.dataset.confirm;
    if (document.body.classList.contains('login-page')) return 'none';
    return 'review';
};

const onSubmit = async (event) => {
    const form = event.target;
    if (! (form instanceof HTMLFormElement) || ! dialog()) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') return;
    if (form.dataset.confirmPassed === 'true') {
        delete form.dataset.confirmPassed;
        return;
    }
    if (confirmType(form) === 'none') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.submitted === 'true' || state) return;

    // Client-side validation first (the browser's own rules: required, min / max, formats).
    if (! form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
    const config = buildConfirmation(form, submitter);
    const accepted = await openConfirm(config, { returnFocus: event.submitter || document.activeElement });
    if (! accepted) return;

    // app.js reads data-confirmed (it skips its own old confirm) and then locks the form against double submits.
    form.dataset.confirmPassed = 'true';
    form.dataset.confirmed = 'true';
    if (submitter) form.requestSubmit(submitter); else form.requestSubmit();

    if (form.matches(AJAX_FORMS)) {
        // Uploads are sent in the background and report in their own modal.
        if (state) { state.pushed = false; close(true); }
        return;
    }
    // A normal post leaves the page. If it does not (offline, blocked), give the buttons back.
    window.setTimeout(() => { if (state) { setBusy(false); close(false); } }, BUSY_TIMEOUT_MS);
};

// Edit snapshots: when a modal opens (after app.js opened it) and, as a fallback, on the first touch of a form.
const onModalOpen = (event) => {
    const opener = event.target.closest('[data-modal-open], [data-modal-swap], [data-open-payment-form]');
    if (! opener) return;
    window.setTimeout(() => {
        const id = opener.dataset.modalOpen || opener.dataset.modalSwap || opener.dataset.openPaymentForm;
        const modal = id && document.getElementById(id);
        if (modal) formsInside(modal).forEach((form) => snapshot(form));
    }, 0);
};

const onFirstTouch = (event) => {
    const form = event.target?.form;
    if (form instanceof HTMLFormElement) snapshot(form);
};

export const initConfirm = () => {
    wireDialog();
    document.addEventListener('submit', onSubmit, true);
    document.addEventListener('click', onModalOpen);
    ['focusin', 'pointerdown', 'keydown'].forEach((type) => document.addEventListener(type, onFirstTouch, true));
    // Modals that open themselves on load (after a validation error) keep what was submitted as the starting point.
    window.addEventListener('load', () => document.querySelectorAll('.modal-backdrop.is-open').forEach((modal) => formsInside(modal).forEach((form) => snapshot(form))));
};

if (typeof document !== 'undefined' && ! window.__cjpConfirmInit) {
    window.__cjpConfirmInit = true;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initConfirm);
    else initConfirm();
}
