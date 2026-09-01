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
    }

    if (event.target.closest('[data-sidebar-toggle]')) {
        document.body.classList.toggle('sidebar-open');
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

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.is-open').forEach(closeModal);
        document.body.classList.remove('sidebar-open');
    }
});

document.querySelectorAll('[data-sales-trend-chart]').forEach((canvas) => {
    const chartData = JSON.parse(canvas.dataset.chart || '{"labels":[],"datasets":[]}');
    const fallback = canvas.parentElement?.querySelector('.sales-trend-fallback');

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
                        label: (context) => context.dataset.formattedData?.[context.dataIndex] || `PHP ${Number(context.raw || 0).toLocaleString()}`,
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => `PHP ${Number(value).toLocaleString()}`,
                    },
                },
            },
        },
    });

    if (fallback) {
        fallback.hidden = true;
    }
});
