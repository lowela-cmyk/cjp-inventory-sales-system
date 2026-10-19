// Dropdown lists that open directly under their box (see select-picker.css).
// Chrome / Edge 135+ do this in CSS (appearance: base-select). Other browsers (Safari, Firefox, older Chrome) draw
// the native macOS list over the field, so there the list is drawn here instead. The <select> stays the source of
// truth: picking an option sets its value and fires the usual input/change events, so every page script still works.

const useFallback = () => !CSS.supports('appearance', 'base-select') || document.documentElement.dataset.selectFallback === 'true';

let panel = null;
let current = null;
let activeIndex = -1;

const isPickable = (select) => select instanceof HTMLSelectElement && !select.multiple && select.size <= 1 && !select.disabled;

const items = () => [...(panel?.querySelectorAll('[data-picker-option]') || [])];

const setActive = (index) => {
    const list = items().filter((item) => item.getAttribute('aria-disabled') !== 'true');
    if (list.length === 0) return;
    activeIndex = (index + list.length) % list.length;
    items().forEach((item) => item.classList.remove('is-active'));
    list[activeIndex].classList.add('is-active');
    list[activeIndex].scrollIntoView({ block: 'nearest' });
};

const place = () => {
    if (!panel || !current) return;
    const box = current.getBoundingClientRect();
    panel.style.left = `${Math.max(8, box.left)}px`;
    panel.style.minWidth = `${box.width}px`;
    panel.style.maxWidth = `${Math.max(box.width, window.innerWidth - box.left - 8)}px`;
    const below = window.innerHeight - box.bottom - 12;
    const height = Math.min(panel.scrollHeight, 320);
    // Open below; flip above only when the list does not fit underneath.
    if (height > below && box.top - 12 > below) {
        panel.style.top = `${Math.max(8, box.top - 4 - height)}px`;
    } else {
        panel.style.top = `${box.bottom + 4}px`;
    }
};

export const closePicker = () => {
    if (!panel) return;
    panel.remove();
    if (current) delete current.dataset.pickerOpen;
    panel = null;
    current = null;
    activeIndex = -1;
};

const choose = (option) => {
    const select = current;
    closePicker();
    if (!select || !option || option.disabled) return;
    if (select.value !== option.value || select.selectedOptions[0] !== option) {
        option.selected = true;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    select.focus();
};

const addOption = (option) => {
    if (option.hidden) return;
    const item = document.createElement('div');
    item.className = 'select-panel-option';
    item.setAttribute('role', 'option');
    item.dataset.pickerOption = '';
    item.textContent = option.textContent;
    item.setAttribute('aria-selected', String(option.selected));
    if (option.disabled) item.setAttribute('aria-disabled', 'true');
    item.addEventListener('mousedown', (event) => event.preventDefault());
    item.addEventListener('click', () => choose(option));
    item._option = option;
    panel.appendChild(item);
};

const open = (select) => {
    closePicker();
    current = select;
    select.dataset.pickerOpen = 'true';
    panel = document.createElement('div');
    panel.className = 'select-panel';
    panel.setAttribute('role', 'listbox');
    [...select.children].forEach((child) => {
        if (child instanceof HTMLOptGroupElement) {
            if (child.hidden) return;
            const label = document.createElement('div');
            label.className = 'select-panel-group';
            label.textContent = child.label;
            panel.appendChild(label);
            [...child.children].forEach(addOption);
        } else if (child instanceof HTMLOptionElement) {
            addOption(child);
        }
    });
    document.body.appendChild(panel);
    place();
    const enabled = items().filter((item) => item.getAttribute('aria-disabled') !== 'true');
    setActive(Math.max(0, enabled.findIndex((item) => item._option.selected)));
};

const activeItem = () => items().filter((item) => item.getAttribute('aria-disabled') !== 'true')[activeIndex];

document.addEventListener('mousedown', (event) => {
    if (!useFallback()) return;
    const select = event.target.closest?.('select');
    if (select && isPickable(select)) {
        // Keep the native list from opening; draw ours under the box instead.
        event.preventDefault();
        select.focus();
        if (current === select) closePicker(); else open(select);
        return;
    }
    if (panel && !panel.contains(event.target)) closePicker();
}, true);

document.addEventListener('keydown', (event) => {
    if (!useFallback()) return;
    const select = event.target.closest?.('select');
    if (!select || !isPickable(select)) return;

    if (current !== select) {
        if ([' ', 'Enter'].includes(event.key) || (['ArrowDown', 'ArrowUp'].includes(event.key) && (event.altKey || /Mac/.test(navigator.platform)))) {
            event.preventDefault();
            open(select);
        }
        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        setActive(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        choose(activeItem()?._option);
    } else if (event.key === 'Escape') {
        // Closes the list only: the event stops here, so the surrounding modal stays open.
        event.preventDefault();
        event.stopPropagation();
        closePicker();
    } else if (event.key === 'Tab') {
        closePicker();
    }
}, true);

// Customizable selects (Chrome / Edge 135+): the closed box shows its selected option on one line, cut off with
// an ellipsis (a long stock-source label used to wrap out of the box). That needs a <button><selectedcontent>
// inside each select; it is added here so no template has to change. The full text shows as a tooltip.
const addFace = (select) => {
    if (!(select instanceof HTMLSelectElement) || select.multiple || select.size > 1) return;
    if (select.querySelector(':scope > button.select-face')) return;
    const face = document.createElement('button');
    face.type = 'button';
    face.className = 'select-face';
    face.appendChild(document.createElement('selectedcontent'));
    select.prepend(face);
};

const syncTitle = (select) => {
    const text = select.selectedOptions?.[0]?.textContent.trim() || '';
    if (text) select.title = text; else select.removeAttribute('title');
};

const addFaces = (root) => {
    root.querySelectorAll?.('select').forEach((select) => { addFace(select); syncTitle(select); });
    root.querySelectorAll?.('template').forEach((template) => addFaces(template.content));
};

if (!useFallback()) {
    addFaces(document);
    document.addEventListener('change', (event) => { if (event.target instanceof HTMLSelectElement) syncTitle(event.target); });
    // Page scripts also set values directly (e.g. the first matching tank), so the tooltip is refreshed on hover.
    document.addEventListener('mouseover', (event) => {
        const select = event.target.closest?.('select');
        if (select) syncTitle(select);
    });
    // Rows added later (+ Add Lift, + Add Purchase, + Add Item, edit templates) get the same face.
    new MutationObserver((mutations) => mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
        if (node instanceof HTMLSelectElement) { addFace(node); syncTitle(node); } else if (node instanceof Element) addFaces(node);
    }))).observe(document.body, { childList: true, subtree: true });
}

// The list follows its box; it closes when the page scrolls or resizes underneath it.
window.addEventListener('scroll', (event) => { if (panel && !panel.contains(event.target)) closePicker(); }, true);
window.addEventListener('resize', closePicker);
