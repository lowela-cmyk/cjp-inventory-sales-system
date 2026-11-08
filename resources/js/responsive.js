// Responsive layer (all roles): the avatar account menu, the mobile Filters sheet, tables shown as cards on phones,
// the "more" link on long alert texts and the scrollable tab bar. Layout only: every form, filter, export, sort and
// modal keeps working as before (see responsive.css for the breakpoints).

// Confirmation for every data-changing form (review summary / delete / status), see confirm.js.
import './confirm';

const CARD_QUERY = window.matchMedia('(max-width: 767px)');

const closeAll = (selector, className, except = null) => {
    document.querySelectorAll(selector).forEach((element) => {
        if (element === except) return;
        element.classList.remove(className);
        element.querySelector('[aria-expanded]')?.setAttribute('aria-expanded', 'false');
    });
};

// -- Account menu (header avatar) -------------------------------------------------------------------------------

const toggleAccountMenu = (menu, open = ! menu.classList.contains('is-open')) => {
    menu.classList.toggle('is-open', open);
    menu.querySelector('[data-account-toggle]')?.setAttribute('aria-expanded', open ? 'true' : 'false');
};

// -- Filters sheet --------------------------------------------------------------------------------------------------

const FILTER_FORMS = 'form.filter-bar, form.dash-filters';
const FILTER_CONTROLS = ':scope > select, :scope > .filter-date-range';
let filterSheetId = 0;

const activeFilterCount = (panel) => {
    let count = 0;
    panel.querySelectorAll('select').forEach((select) => { if (select.value !== '') count += 1; });
    panel.querySelectorAll('.filter-date-range').forEach((range) => {
        if ([...range.querySelectorAll('input')].some((input) => input.value !== '')) count += 1;
    });
    return count;
};

const syncFilterCount = (form) => {
    const panel = form.querySelector(':scope > .filter-panel');
    const badge = form.querySelector(':scope > .filter-toggle .filter-count');
    if (! panel || ! badge) return;
    const count = activeFilterCount(panel);
    badge.textContent = String(count);
    badge.hidden = count === 0;
    form.querySelector(':scope > .filter-toggle').setAttribute('aria-label', count ? `Filters, ${count} active` : 'Filters');
};

const setFilterSheet = (form, open) => {
    form.classList.toggle('is-filters-open', open);
    form.querySelector(':scope > .filter-toggle')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('has-sheet-open', Boolean(document.querySelector('.is-filters-open')));
    if (open) form.querySelector(':scope > .filter-panel select, :scope > .filter-panel input')?.focus({ preventScroll: true });
};

const enhanceFilterForm = (form) => {
    if (form.dataset.filterSheet) return;
    const controls = [...form.querySelectorAll(FILTER_CONTROLS)];
    if (controls.length === 0) return;
    form.dataset.filterSheet = 'true';

    filterSheetId += 1;
    const panel = document.createElement('div');
    panel.className = 'filter-panel';
    panel.id = `filter-panel-${filterSheetId}`;
    panel.setAttribute('role', 'group');
    panel.setAttribute('aria-label', 'Filters');
    panel.innerHTML = '<div class="filter-panel-head"><strong>Filters</strong><button class="filter-panel-done" type="button" data-filter-close>Done</button></div>';
    controls[0].before(panel);
    controls.forEach((control) => panel.append(control));

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'filter-toggle';
    toggle.setAttribute('aria-controls', panel.id);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg><span>Filters</span><span class="filter-count" hidden>0</span>';
    const search = form.querySelector(':scope > input[type="search"]');
    (search ? search.after(toggle) : form.prepend(toggle));

    const scrim = document.createElement('div');
    scrim.className = 'filter-scrim';
    scrim.dataset.filterClose = '';
    form.append(scrim);

    syncFilterCount(form);
};

// -- Tables as cards (phones) ------------------------------------------------------------------------------------------

const MENU_ICON = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>';

const headerLabel = (th) => {
    const copy = th.cloneNode(true);
    copy.querySelectorAll('.visually-hidden, [hidden]').forEach((node) => node.remove());
    const lines = [...copy.querySelectorAll('.th-line')];
    const text = lines.length > 0 ? lines.map((line) => line.textContent.trim()).join(' / ') : copy.textContent;
    return text.replace(/\s+/g, ' ').trim();
};

const prepareCardRow = (row, table) => {
    if (row.dataset.cardReady || row.querySelector('.empty-cell')) return;
    row.dataset.cardReady = 'true';
    const { labels, keys, actionsIndex } = table.cardMeta;
    let hasStatus = false;
    let extras = 0;

    [...row.cells].forEach((cell, index) => {
        if (labels[index]) cell.dataset.label = labels[index];
        if (index === keys[0]) {
            cell.classList.add('card-primary');
        } else if (keys.includes(index)) {
            cell.classList.add('card-key');
        } else if (index === actionsIndex) {
            cell.classList.add('card-actions');
            if (cell.querySelector('a, button, form') && ! cell.querySelector(':scope > .card-menu-toggle')) {
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'card-menu-toggle';
                toggle.setAttribute('aria-haspopup', 'true');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-label', 'Actions');
                toggle.innerHTML = MENU_ICON;
                cell.prepend(toggle);
            }
        } else if (! hasStatus && cell.querySelector('.status-badge')) {
            hasStatus = true;
            cell.classList.add('card-status');
        } else if (! cell.hidden) {
            cell.classList.add('card-extra');
            extras += 1;
        }
    });

    row.classList.toggle('card-expandable', extras > 0);
    if (extras > 0) row.setAttribute('aria-expanded', row.classList.contains('is-expanded') ? 'true' : 'false');
    syncRowFocus(row);
};

const syncRowFocus = (row) => {
    if (! row.classList.contains('card-expandable')) return;
    if (CARD_QUERY.matches) row.tabIndex = 0;
    else row.removeAttribute('tabindex');
};

const enhanceCardTable = (table) => {
    if (table.cardMeta || ! table.tHead?.rows[0]) return;
    const labels = [...table.tHead.rows[0].cells].map(headerLabel);
    const keys = (table.dataset.cardKeys || '0,1').split(',').map((key) => Number(key.trim())).filter(Number.isFinite);
    table.cardMeta = { labels, keys, actionsIndex: labels.findIndex((label) => /^actions?$/i.test(label)) };
    table.classList.add('card-table');
    [...table.tBodies].forEach((body) => {
        [...body.rows].forEach((row) => prepareCardRow(row, table));
        // Rows added or swapped later (in-place updates) get the same treatment.
        new MutationObserver(() => [...body.rows].forEach((row) => prepareCardRow(row, table)))
            .observe(body, { childList: true });
    });
};

const toggleCardRow = (row) => {
    const open = ! row.classList.contains('is-expanded');
    row.classList.toggle('is-expanded', open);
    row.setAttribute('aria-expanded', open ? 'true' : 'false');
};

// -- Alert texts: two lines, then "more" ----------------------------------------------------------------------------

const syncAlertClamps = () => {
    document.querySelectorAll('.workflow-alert-message').forEach((message) => {
        if (message.classList.contains('is-expanded')) return;
        let more = message.nextElementSibling?.matches('.alert-more') ? message.nextElementSibling : null;
        const clipped = message.scrollHeight > message.clientHeight + 1;
        if (clipped && ! more) {
            more = document.createElement('button');
            more.type = 'button';
            more.className = 'alert-more';
            more.setAttribute('aria-expanded', 'false');
            more.textContent = 'more';
            message.after(more);
        }
        if (more) more.hidden = ! clipped;
    });
};

// -- Stat figures: the full value where it fits, the short one where it would overflow its card ------------------

const syncStatValues = () => {
    document.querySelectorAll('.stat-value:has(.stat-value-short)').forEach((stat) => {
        stat.classList.remove('is-short');
        if (stat.scrollWidth > stat.clientWidth + 1) stat.classList.add('is-short');
    });
};

// -- Tab bar: keep the active tab in view ---------------------------------------------------------------------------

const centerActiveTab = (bar) => {
    const tab = bar.querySelector('.is-active');
    if (! tab || bar.scrollWidth <= bar.clientWidth) return;
    bar.scrollLeft = tab.offsetLeft - bar.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2;
};

// -- Wiring ---------------------------------------------------------------------------------------------------------

const init = () => {
    document.querySelectorAll(FILTER_FORMS).forEach(enhanceFilterForm);
    document.querySelectorAll('table[data-card-keys]').forEach(enhanceCardTable);
    document.querySelectorAll('.tabs').forEach(centerActiveTab);
    syncAlertClamps();
    syncStatValues();
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
document.fonts?.ready?.then(syncStatValues);

let resizeFrame = 0;
window.addEventListener('resize', () => {
    cancelAnimationFrame(resizeFrame);
    resizeFrame = requestAnimationFrame(() => {
        syncAlertClamps();
        syncStatValues();
    });
}, { passive: true });

CARD_QUERY.addEventListener('change', () => {
    document.querySelectorAll('.card-table tr.card-expandable').forEach(syncRowFocus);
    if (! CARD_QUERY.matches) closeAll('.card-actions.is-open', 'is-open');
});

document.addEventListener('change', (event) => {
    const form = event.target.closest?.(FILTER_FORMS);
    if (form) syncFilterCount(form);
});

document.addEventListener('click', (event) => {
    const target = event.target;

    // Account menu.
    const accountToggle = target.closest('[data-account-toggle]');
    if (accountToggle) {
        toggleAccountMenu(accountToggle.closest('[data-account-menu]'));
    } else if (! target.closest('[data-account-menu]')) {
        document.querySelectorAll('[data-account-menu].is-open').forEach((menu) => toggleAccountMenu(menu, false));
    }

    // Filters sheet.
    const filterToggle = target.closest('.filter-toggle');
    if (filterToggle) {
        const form = filterToggle.closest('form');
        setFilterSheet(form, ! form.classList.contains('is-filters-open'));
        return;
    }
    if (target.closest('[data-filter-close]')) {
        setFilterSheet(target.closest('form'), false);
        return;
    }

    // Alert "more" / "less".
    const more = target.closest('.alert-more');
    if (more) {
        const message = more.previousElementSibling;
        const open = ! message.classList.contains('is-expanded');
        message.classList.toggle('is-expanded', open);
        more.textContent = open ? 'less' : 'more';
        more.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
    }

    // Tab bar: center the tab just picked (app.js switches the panel).
    const tab = target.closest('.tabs [data-tab-target]');
    if (tab) requestAnimationFrame(() => centerActiveTab(tab.closest('.tabs')));

    // Card tables: the "⋮" menu, then tap-to-expand on the rest of the card.
    const menuToggle = target.closest('.card-menu-toggle');
    if (menuToggle) {
        const cell = menuToggle.closest('.card-actions');
        const open = ! cell.classList.contains('is-open');
        closeAll('.card-actions.is-open', 'is-open', cell);
        cell.classList.toggle('is-open', open);
        menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
    }
    const menuCell = target.closest('.card-actions.is-open');
    // Picking an action (e.g. opening a modal) closes the menu after the action's own handler ran.
    if (menuCell) {
        setTimeout(() => closeAll('.card-actions.is-open', 'is-open'), 0);
        return;
    }
    closeAll('.card-actions.is-open', 'is-open');

    if (! CARD_QUERY.matches) return;
    const row = target.closest('.card-table tr.card-expandable');
    if (row && ! target.closest('a, button, input, select, textarea, label, summary, [data-modal-open]')) {
        toggleCardRow(row);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.is-filters-open').forEach((form) => setFilterSheet(form, false));
        document.querySelectorAll('[data-account-menu].is-open').forEach((menu) => toggleAccountMenu(menu, false));
        closeAll('.card-actions.is-open', 'is-open');
        return;
    }
    const row = event.target;
    if ((event.key === 'Enter' || event.key === ' ') && CARD_QUERY.matches && row.matches?.('.card-table tr.card-expandable')) {
        event.preventDefault();
        toggleCardRow(row);
    }
});

// -- Bottom nav (x-nav.bottom) ----------------------------------------------------------------------------------------

// The Alerts / More badge shows the same unread count as the header bell (no extra query on the server).
const syncAlertMirrors = () => {
    const count = document.querySelector('[data-alert-bell-count]')?.textContent.trim() || '';
    document.querySelectorAll('[data-alert-mirror]').forEach((badge) => {
        badge.textContent = count;
        badge.hidden = count === '';
        badge.setAttribute('aria-label', count ? `${count} unread alerts` : '');
    });
};
syncAlertMirrors();
const bellCount = document.querySelector('[data-alert-bell]');
if (bellCount) new MutationObserver(syncAlertMirrors).observe(bellCount, { childList: true, subtree: true, characterData: true });

// "More" sheet: opens from the bar; the scrim, Close, Esc and the Back button close it.
let openMore = null;
const setMoreSheet = (sheet, open, { fromHistory = false } = {}) => {
    if (! sheet) return;
    const toggle = document.querySelector(`[aria-controls="${sheet.id}"]`);
    sheet.hidden = ! open;
    toggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('has-sheet-open', open);
    if (open) {
        openMore = sheet;
        try { history.pushState({ ...(history.state || {}), cjpMore: true }, ''); sheet.dataset.pushed = 'true'; } catch { /* no history */ }
        requestAnimationFrame(() => sheet.querySelector('.more-link, .more-close')?.focus({ preventScroll: true }));
    } else {
        openMore = null;
        if (sheet.dataset.pushed === 'true' && ! fromHistory) history.back();
        delete sheet.dataset.pushed;
        toggle?.focus({ preventScroll: true });
    }
};

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-more-toggle]');
    if (toggle) {
        const sheet = document.getElementById(toggle.getAttribute('aria-controls'));
        setMoreSheet(sheet, sheet.hidden);
        return;
    }
    if (openMore && event.target.closest('[data-more-close]')) setMoreSheet(openMore, false);
    // Following a link: leave the history entry alone (the page changes anyway).
    if (openMore && event.target.closest('.more-link')) delete openMore.dataset.pushed;
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && openMore && ! document.querySelector('[data-confirm-dialog]:not([hidden])')) {
        event.stopImmediatePropagation();
        setMoreSheet(openMore, false);
    }
}, true);

window.addEventListener('popstate', () => {
    if (openMore) setMoreSheet(openMore, false, { fromHistory: true });
});

// On-screen keyboard: hide the bar while a text field has focus on a touch screen, or while the visual viewport is
// clearly shorter than the window (the keyboard is up).
const TOUCH_QUERY = window.matchMedia('(pointer: coarse)');
const TYPING_FIELDS = 'input:not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="file"]):not([type="range"]):not([type="color"]):not([type="hidden"]), textarea, [contenteditable="true"], [contenteditable=""]';
const setKeyboardOpen = (open) => document.documentElement.classList.toggle('is-keyboard-open', open);

document.addEventListener('focusin', (event) => {
    if (TOUCH_QUERY.matches && event.target.matches?.(TYPING_FIELDS)) setKeyboardOpen(true);
});
document.addEventListener('focusout', () => {
    window.setTimeout(() => {
        if (! document.activeElement?.matches?.(TYPING_FIELDS)) setKeyboardOpen(false);
    }, 50);
});
window.visualViewport?.addEventListener('resize', () => {
    const keyboardHeight = window.innerHeight - window.visualViewport.height;
    if (keyboardHeight > 150) setKeyboardOpen(true);
    else if (! (TOUCH_QUERY.matches && document.activeElement?.matches?.(TYPING_FIELDS))) setKeyboardOpen(false);
});
