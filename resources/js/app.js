const root = document.querySelector('[data-sidebar-root]');

if (root) {
    const panel = root.querySelector('[data-sidebar-panel]');
    const workspace = root.querySelector('[data-sidebar-workspace]');
    const backdrop = root.querySelector('[data-sidebar-backdrop]');
    const toggles = [...root.querySelectorAll('[data-sidebar-toggle]')];
    const trigger = toggles.find((button) => !panel.contains(button));
    const closeButton = panel.querySelector('[data-sidebar-close]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const preferenceKey = 'school-erp:sidebar-collapsed';
    let collapsed = false;
    let mobileOpen = false;
    let returnFocus = trigger;

    try {
        collapsed = localStorage.getItem(preferenceKey) === 'true';
    } catch { /* Navigation still works when browser storage is unavailable. */ }

    function render() {
        const visible = desktop.matches || mobileOpen;
        const expanded = desktop.matches ? !collapsed : mobileOpen;
        const modal = !desktop.matches && mobileOpen;
        workspace.inert = modal;
        if (!visible && panel.contains(document.activeElement)) trigger.focus();
        root.dataset.desktopCollapsed = String(collapsed);
        root.dataset.mobileOpen = String(mobileOpen);
        panel.inert = !visible;
        panel.setAttribute('aria-hidden', String(!visible));
        if (modal) {
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
        } else {
            panel.removeAttribute('role');
            panel.removeAttribute('aria-modal');
        }
        backdrop.hidden = !modal;
        document.documentElement.classList.toggle('sidebar-scroll-locked', modal);
        toggles.forEach((button) => {
            button.setAttribute('aria-expanded', String(expanded));
            button.setAttribute('aria-label', desktop.matches
                ? (expanded ? 'Collapse sidebar' : 'Expand sidebar')
                : (visible ? 'Close navigation' : 'Open navigation'));
            button.title = button.getAttribute('aria-label');
        });
    }

    function closeMobile(restoreFocus = true) {
        if (!mobileOpen) return;
        mobileOpen = false;
        render();
        if (restoreFocus && returnFocus?.isConnected) returnFocus.focus();
    }

    toggles.forEach((button) => button.addEventListener('click', () => {
        if (desktop.matches) {
            collapsed = !collapsed;
            try { localStorage.setItem(preferenceKey, String(collapsed)); } catch { /* Optional preference. */ }
            render();
        } else if (mobileOpen) {
            closeMobile();
        } else {
            returnFocus = document.activeElement;
            mobileOpen = true;
            render();
            requestAnimationFrame(() => {
                if (mobileOpen && !desktop.matches) closeButton.focus();
            });
        }
    }));
    closeButton.addEventListener('click', () => closeMobile());
    backdrop.addEventListener('click', () => closeMobile());
    panel.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => closeMobile(false)));

    document.addEventListener('keydown', (event) => {
        if (!mobileOpen || desktop.matches) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            closeMobile();
        } else if (event.key === 'Tab') {
            const focusable = [...panel.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"]')]
                .filter((element) => element.getClientRects().length && !element.hidden);
            const first = focusable[0];
            const last = focusable.at(-1);
            if (event.shiftKey && (document.activeElement === first || !panel.contains(document.activeElement))) {
                event.preventDefault(); last?.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !panel.contains(document.activeElement))) {
                event.preventDefault(); first?.focus();
            }
        }
    });
    desktop.addEventListener('change', () => {
        const wasOpen = mobileOpen;
        mobileOpen = false;
        render();
        if (wasOpen) trigger.focus();
    });
    render();
    requestAnimationFrame(() => root.dataset.sidebarReady = 'true');

    root.querySelectorAll('.app-content .overflow-x-auto').forEach((region) => {
        if (!region.querySelector('table')) return;
        region.tabIndex = 0;
        region.setAttribute('role', 'region');
        if (!region.hasAttribute('aria-label')) region.setAttribute('aria-label', 'Scrollable data table');
    });
}

// Preview totals for entry rows; the server validates and recomputes saved totals.
document.querySelectorAll('[data-marks-row]').forEach((row) => {
    const fields = [...row.querySelectorAll('[data-marks-component]')];
    const absent = row.querySelector('[data-marks-absent]');
    if (!absent) return;
    const refresh = () => {
        fields.forEach((field) => { field.disabled = absent.checked; if (absent.checked) field.value = ''; });
        const complete = fields.length > 0 && fields.every((field) => field.value !== '' && field.validity.valid);
        row.querySelector('[data-marks-total]').textContent = absent.checked ? 'Absent' : complete ? (fields.reduce((sum, field) => sum + Math.round(Number(field.value) * 100), 0) / 100).toFixed(2) : 'Incomplete';
    };
    fields.forEach((field) => field.addEventListener('input', refresh));
    absent.addEventListener('change', refresh);
    refresh();
});

// Class result selections are scoped to the currently displayed page.
document.querySelectorAll('[data-result-select-all]').forEach((toggle) => {
    const boxes = [...toggle.closest('form').querySelectorAll('input[name="ids[]"]')];
    const refresh = () => {
        const selected = boxes.filter((box) => box.checked).length;
        toggle.disabled = boxes.length === 0;
        toggle.checked = boxes.length > 0 && selected === boxes.length;
        toggle.indeterminate = selected > 0 && selected < boxes.length;
    };
    toggle.addEventListener('change', () => boxes.forEach((box) => { box.checked = toggle.checked; }));
    boxes.forEach((box) => box.addEventListener('change', refresh));
    refresh();
});
