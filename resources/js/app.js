import './bootstrap';

const closeModal = (modal) => {
    modal?.classList.remove('is-open');
    modal?.setAttribute('aria-hidden', 'true');
};

const runDemoLogin = (form) => {
    const role = form?.querySelector('[data-demo-role]')?.value;
    const error = form?.querySelector('[data-demo-login-error]');
    const destinations = {
        admin: '/admin/dashboard',
        dispatch: '/dispatch/fuel-lifting',
        'inventory-officer': '/inventory-officer/inventory',
        'sales-officer': '/sales-officer/sales',
        driver: '/driver/fuel-lifting',
    };

    if (!role || !destinations[role]) {
        error.hidden = false;
        return;
    }

    error.hidden = true;
    window.location.href = destinations[role];
};

document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-demo-login-form]');
    if (!form) {
        return;
    }

    event.preventDefault();
    runDemoLogin(form);
});

document.addEventListener('click', (event) => {
    const demoLoginButton = event.target.closest('[data-demo-login-button]');
    if (demoLoginButton) {
        runDemoLogin(demoLoginButton.closest('[data-demo-login-form]'));
    }

    const modalButton = event.target.closest('[data-modal-open]');
    if (modalButton) {
        const modal = document.getElementById(modalButton.dataset.modalOpen);
        modal?.classList.add('is-open');
        modal?.setAttribute('aria-hidden', 'false');
    }

    if (event.target.matches('[data-modal-close]') || event.target.classList.contains('modal-backdrop')) {
        closeModal(event.target.closest('.modal-backdrop') || event.target);
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
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop.is-open').forEach(closeModal);
        document.body.classList.remove('sidebar-open');
    }
});
