import './subject-class-setup';
import './list-tables';
import './class-teacher-setup';

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
    const boxes = () => [...toggle.closest('form').querySelectorAll('input[name="ids[]"]')];
    const refresh = () => {
        const selected = boxes().filter((box) => box.checked).length;
        toggle.disabled = boxes().length === 0;
        toggle.checked = boxes().length > 0 && selected === boxes().length;
        toggle.indeterminate = selected > 0 && selected < boxes().length;
    };
    toggle.addEventListener('change', () => { boxes().forEach((box) => { box.checked = toggle.checked; }); refresh(); });
    toggle.closest('form').addEventListener('change', refresh);
    toggle.closest('form').addEventListener('list:draw', refresh);
    refresh();
});

// Guided class setup keeps optional sections and subjects on the same form.
document.querySelectorAll('[data-class-setup]').forEach((form) => {
    const name = form.querySelector('[name="name"]');
    const code = form.querySelector('[data-class-code]');
    const refreshCode = () => {
        const value = name.value.trim();
        let generated = '';
        if (value && value === form.dataset.originalName) generated = form.dataset.originalCode;
        else if (value && /^[a-zA-Z0-9 _-]+$/.test(value)) {
            generated = value.toUpperCase().replace(/[ _]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            if (!generated || generated.length > 50) generated = 'Generated when you save';
        } else if (value) generated = 'Generated when you save';
        if (code instanceof HTMLInputElement) code.value = generated;
        else code.textContent = generated || 'Enter a class name';
        const announcement = form.querySelector('[data-class-code-announcement]');
        if (announcement) announcement.textContent = generated ? `Class code: ${generated}` : '';
    };
    name.addEventListener('input', refreshCode); refreshCode();

    const sectionArea = form.querySelector('[data-setup-sections]');
    if (sectionArea) {
        const skip = sectionArea.querySelector('[data-skip-sections]');
        const custom = sectionArea.querySelector('[data-custom-sections]');
        const choices = [...sectionArea.querySelectorAll('[data-section-choice]')];
        const add = sectionArea.querySelector('[data-add-section]');
        let sequence = 0;
        const refresh = () => {
            choices.forEach((choice) => {
                choice.disabled = Boolean(skip?.checked) || choice.dataset.locked === 'true';
                sectionArea.querySelector(`[data-section-capacity="${choice.dataset.sectionChoice}"]`).disabled = Boolean(skip?.checked) || !choice.checked;
            });
            custom.querySelectorAll('input').forEach((input) => input.disabled = Boolean(skip?.checked));
            add.disabled = Boolean(skip?.checked) || custom.children.length + choices.filter((c) => c.checked).length >= 50;
            const names = skip?.checked ? [] : [
                ...choices.filter((c) => c.checked).map((c) => c.dataset.sectionChoice),
                ...[...custom.querySelectorAll('[data-section-name]')].map((c) => c.value.trim()).filter(Boolean),
            ];
            sectionArea.querySelector('[data-section-summary]').textContent = names.length ? `Selected sections: ${names.join(', ')}` : 'No sections selected. You can add them later.';
            form.dispatchEvent(new CustomEvent('class-setup:changed'));
        };
        sectionArea.addEventListener('input', refresh);
        sectionArea.addEventListener('change', refresh);
        sectionArea.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-section]');
            if (!button) return;
            if (button.closest('[data-custom-section]').dataset.protected === 'true') return;
            button.closest('[data-custom-section]').remove(); add.focus(); refresh();
        });
        add.hidden = false;
        custom.querySelectorAll('[data-remove-section]').forEach((button) => button.hidden = false);
        add.addEventListener('click', () => {
            const fragment = sectionArea.querySelector('[data-section-template]').content.cloneNode(true);
            let key;
            do { key = `extra${sequence++}`; } while (form.querySelector(`[name="sections[${key}][name]"]`));
            fragment.querySelectorAll('[data-section-field]').forEach((input) => input.name = `sections[${key}][${input.dataset.sectionField}]`);
            const input = fragment.querySelector('[data-section-name]');
            custom.append(fragment); input.focus(); refresh();
        });
        refresh();
    }

    const subjectArea = form.querySelector('[data-setup-subjects]');
    const search = subjectArea?.querySelector('[data-subject-search]');
    if (search) {
        const boxes = [...subjectArea.querySelectorAll('input[type="checkbox"][name="subject_ids[]"]')];
        const options = [...subjectArea.querySelectorAll('[data-subject-option]')];
        const count = subjectArea.querySelector('[data-subject-count]');
        const refresh = () => {
            const selected = boxes.filter((box) => box.checked);
            count.textContent = selected.length ? `${selected.length} subjects selected: ${selected.map((box) => box.closest('label').querySelector('span.font-semibold').textContent).join(', ')}` : 'No subjects selected. You can add them later.';
            form.dispatchEvent(new CustomEvent('class-setup:changed'));
        };
        search.disabled = false;
        search.addEventListener('input', () => {
            const term = search.value.trim().toLocaleLowerCase();
            options.forEach((option) => option.hidden = !option.dataset.searchText.toLocaleLowerCase().includes(term));
            subjectArea.querySelector('[data-subject-empty]').hidden = options.some((option) => !option.hidden);
        });
        boxes.forEach((box) => box.addEventListener('change', refresh));
        const copy = subjectArea.querySelector('[data-copy-subjects]');
        if (copy) {
            copy.disabled = false;
            copy.addEventListener('change', () => {
                if (!copy.value) return;
                const ids = JSON.parse(copy.selectedOptions[0].dataset.subjects).map(String);
                boxes.forEach((box) => box.checked = box.dataset.locked === 'true' || ids.includes(box.value));
                search.value = ''; search.dispatchEvent(new Event('input')); refresh();
            });
        }
        refresh();
    }
});
