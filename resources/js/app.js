import './bootstrap';
import Chart from 'chart.js/auto';

const closeModal = (modal) => {
    modal?.classList.remove('is-open');
    modal?.setAttribute('aria-hidden', 'true');
};

const openModal = (modal) => {
    if (!modal) {
        return;
    }

    document.querySelectorAll('.modal-backdrop.is-open').forEach((openModalElement) => {
        if (openModalElement !== modal) {
            closeModal(openModalElement);
        }
    });

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
};

let pendingConfirmForm = null;

document.addEventListener('click', (event) => {
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
        const source = items?.querySelector('[data-sales-item]:last-child');

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
            clone.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
            clone.querySelectorAll('input').forEach((input) => { input.value = ''; });
            const summary = clone.querySelector('[data-purchase-summary]');
            if (summary) summary.textContent = '—';
            items.appendChild(clone);
            updateLiftingSchedule(form);
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

document.addEventListener('input', (event) => {
    if (event.target.closest('[data-sales-item]')) {
        updateSalesTotals(event.target.closest('[data-sales-items]'));
    }

    const liftingForm = event.target.closest('[data-lifting-schedule-form]');
    if (liftingForm) updateLiftingSchedule(liftingForm);
});

document.addEventListener('change', (event) => {
    const liftingForm = event.target.closest('[data-lifting-schedule-form]');
    if (liftingForm) updateLiftingSchedule(liftingForm);
});

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

    rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`);
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
            depotLabel ||= `${option.dataset.depot}${option.dataset.address ? ` — ${option.dataset.address}` : ''}`;
            if (quantity > remaining) error ||= 'An amount to lift exceeds its remaining purchase quantity.';
            if (quantityInput) quantityInput.max = String(remaining);
            if (summary) summary.textContent = `${option.dataset.fuel} / ${remaining.toLocaleString()} L remaining`;
        } else if (summary) {
            summary.textContent = '—';
        }
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
    const submit = form.querySelector('button[type="submit"]');
    if (submit) submit.disabled = Boolean(error) || rows.some((row) => !row.querySelector('[data-lifting-purchase]')?.value) || capacity <= 0 || total <= 0;
};

document.querySelectorAll('[data-lifting-schedule-form]').forEach(updateLiftingSchedule);

const reindexSaleItems = (items) => {
    const rows = items.querySelectorAll('[data-sales-item]');

    rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/items\[\d+\]/, `items[${index}]`);
        });

        row.querySelectorAll('[id]').forEach((field) => {
            const oldId = field.id;
            const newId = oldId.replace(/_\d+$/, `_${index}`);
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

    const totalOutput = items.parentElement?.querySelector('[data-sales-total-preview]');
    if (totalOutput) {
        totalOutput.textContent = `Total: ${total.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    }
};

document.querySelectorAll('[data-sales-items]').forEach(updateSalesTotals);

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

    const rows = [...table.querySelectorAll('tr')]
        .map((row) => [...row.querySelectorAll('th, td')]
            .map((cell) => `"${cell.textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""')}"`)
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

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
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
        || form.method.toLowerCase() !== 'get';

    if (! shouldPreventDoubleSubmit) {
        return;
    }

    if (form.dataset.submitted === 'true') {
        event.preventDefault();

        return;
    }

    form.dataset.submitted = 'true';
    form.setAttribute('aria-busy', 'true');
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
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
