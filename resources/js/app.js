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
    }

    const removeSaleItemButton = event.target.closest('[data-sales-item-remove]');
    if (removeSaleItemButton) {
        const items = removeSaleItemButton.closest('[data-sales-items]');

        if (!items || items.querySelectorAll('[data-sales-item]').length <= 1) {
            return;
        }

        removeSaleItemButton.closest('[data-sales-item]')?.remove();
        reindexSaleItems(items);
    }
});

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
    if (confirmation && ! window.confirm(confirmation)) {
        event.preventDefault();

        return;
    }

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
