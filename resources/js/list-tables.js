import DataTable from 'datatables.net-dt';

// Reuse authorised Laravel list pages and their escaped Blade cells for every draw.
// Only one database page is fetched; no complete directories are sent to the browser.
document.querySelectorAll('[data-list-table]').forEach((panel) => {
    const table = panel.querySelector('table');
    if (!table?.tHead || !table.tBodies[0]) return;
    const config = JSON.parse(panel.dataset.listConfig);
    const key = panel.dataset.listTable;
    const columnCount = table.tHead.rows[0].cells.length;
    const extract = (source) => [...source.querySelector('table').tBodies[0].rows]
        .filter((row) => row.cells.length === columnCount && ![...row.cells].some((cell) => cell.colSpan > 1))
        .map((row) => ({
            cells: [...row.cells].map((cell) => cell.innerHTML),
            attributes: [...row.attributes].map((attribute) => [attribute.name, attribute.value]),
            cellAttributes: [...row.cells].map((cell) => [...cell.attributes].map((attribute) => [attribute.name, attribute.value])),
        }));
    const initialRows = extract(panel);
    const getForms = [...document.querySelectorAll('form')].filter((form) =>
        (form.getAttribute('method') || 'get').toLowerCase() === 'get' && new URL(form.getAttribute('action') || location.href, location.href).pathname === location.pathname
        && Boolean(form.compareDocumentPosition(panel) & Node.DOCUMENT_POSITION_FOLLOWING)
    );
    const filterForm = getForms.findLast((form) => form.matches('.list-toolbar, [data-list-filters]')
        || form.querySelector('input[name="q"], input[name="search"], select[name], input[type="date"]'));
    const legacySearch = filterForm?.querySelector('input[name="q"], input[name="search"]');
    const searchName = legacySearch?.name;
    const initialSearch = config.search || legacySearch?.value || '';
    const error = document.createElement('div');
    error.className = 'list-table-error'; error.hidden = true; error.setAttribute('role', 'alert');
    const message = document.createElement('span');
    const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Try again';
    error.append(message, retry); panel.querySelector('.data-panel-heading').after(error);
    let initial = true;
    let controller;
    let serial = 0;
    let api;

    const setHidden = (form, name, value) => {
        let input = [...form.elements].find((field) => field.type === 'hidden' && field.name === name);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.append(input); }
        input.value = value;
    };
    const syncFilters = (term, length, source, responseDocument) => {
        if (filterForm) {
            setHidden(filterForm, `tables[${key}][search]`, term);
            setHidden(filterForm, `tables[${key}][length]`, length);
        }
        // Published-result output uses the same student-name filter as the table.
        if (panel.hasAttribute('data-result-list')) {
            const form = panel.closest('form');
            const sourceForm = responseDocument?.querySelector('[data-result-list]')?.closest('form');
            if (form && sourceForm) {
                for (const name of ['academic_year_id', 'exam_id', 'class_id', 'section_id', 'result_status']) {
                    const value = sourceForm.querySelector(`input[type="hidden"][name="${name}"]`)?.value;
                    if (value !== undefined) setHidden(form, name, value);
                    else form.querySelector(`input[type="hidden"][name="${name}"]`)?.remove();
                }
            }
            if (form) setHidden(form, 'q', term);
            document.querySelectorAll('[data-result-export]').forEach((link) => {
                const sourceLink = responseDocument?.querySelector('[data-result-export]');
                const url = new URL(sourceLink?.getAttribute('href') || link.href, location.href);
                term ? url.searchParams.set('q', term) : url.searchParams.delete('q');
                link.href = url.href;
            });
        }
        panel.querySelectorAll('[data-result-select-all]').forEach((toggle) => {
            toggle.checked = false; toggle.indeterminate = false;
            toggle.disabled = !panel.querySelector('tbody input[name="ids[]"]');
        });
        if (source) panel.querySelector('.record-count').textContent = Number(source.filtered).toLocaleString();
        panel.dispatchEvent(new CustomEvent('list:draw', {bubbles: true}));
    };

    api = new DataTable(table, {
        serverSide: true, processing: true, ordering: false, autoWidth: false, data: [],
        pageLength: config.length, lengthMenu: [10, 20, 25, 50, 100],
        displayStart: (config.page - 1) * config.length,
        search: {search: initialSearch}, searchDelay: 400,
        layout: {topStart: 'pageLength', topEnd: 'search', bottomStart: 'info', bottomEnd: {paging: {buttons: 5}}},
        language: {
            search: 'Search', searchPlaceholder: 'Search records…', lengthMenu: 'Show _MENU_ per page',
            info: 'Showing _START_–_END_ of _TOTAL_ records', infoEmpty: 'No records to display', infoFiltered: '',
            emptyTable: 'No records match your search or filters.', zeroRecords: 'No matching records.',
            paginate: {
                first: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m11 6-6 6 6 6m8-12-6 6 6 6"/></svg>',
                previous: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>',
                next: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
                last: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 6 6 6-6 6m8-12 6 6-6 6"/></svg>',
            },
        },
        columns: Array.from({length: columnCount}, (_, index) => ({data: (row) => row.cells[index], defaultContent: ''})),
        createdRow(row, data) {
            for (const [name, value] of data.attributes) row.setAttribute(name, value);
            [...row.cells].forEach((cell, index) => {
                for (const [name, value] of data.cellAttributes[index]) cell.setAttribute(name, value);
            });
        },
        ajax: async (data, callback) => {
            if (initial) {
                initial = false;
                callback({draw: data.draw, recordsTotal: config.total, recordsFiltered: config.filtered, data: initialRows});
                return;
            }
            controller?.abort(); controller = new AbortController(); const request = ++serial;
            error.hidden = true;
            const url = new URL(location.href);
            if (filterForm) {
                // Read the live controls, including cleared filters, on every draw.
                for (const field of filterForm.elements) {
                    if (field.name) url.searchParams.delete(field.name);
                }
                for (const [name, value] of new FormData(filterForm)) {
                    if (typeof value === 'string' && value !== '') url.searchParams.append(name, value);
                }
            }
            if (searchName) url.searchParams.delete(searchName);
            url.searchParams.set(key, String(Math.floor(data.start / data.length) + 1));
            url.searchParams.set(`tables[${key}][length]`, String(data.length));
            url.searchParams.set(`tables[${key}][search]`, data.search.value);
            try {
                const response = await fetch(url, {headers: {Accept: 'text/html'}, credentials: 'same-origin', signal: controller.signal, cache: 'no-store'});
                if (!response.ok || response.redirected) throw new Error('The list could not be loaded. Check your connection and sign-in, then try again.');
                const document = new DOMParser().parseFromString(await response.text(), 'text/html');
                const source = [...document.querySelectorAll('[data-list-table]')].find((node) => node.dataset.listTable === key);
                if (!source) throw new Error('This list is unavailable. Reload the page to check your access.');
                const metadata = JSON.parse(source.dataset.listConfig);
                const rows = extract(source);
                if (request !== serial) return;
                callback({draw: data.draw, recordsTotal: metadata.total, recordsFiltered: metadata.filtered, data: rows});
                const synced = new Map([...document.querySelectorAll('[data-list-sync]')].map((node) => [node.dataset.listSync, node]));
                window.document.querySelectorAll('[data-list-sync]').forEach((node) => {
                    const replacement = synced.get(node.dataset.listSync);
                    if (replacement) node.replaceWith(replacement.cloneNode(true));
                });
                history.replaceState(null, '', url);
                syncFilters(data.search.value, data.length, metadata, document);
            } catch (failure) {
                if (failure.name === 'AbortError' || request !== serial) return;
                message.textContent = failure.message; error.hidden = false;
                callback({draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: []});
            }
        },
    });
    if (filterForm) {
        filterForm.addEventListener('change', () => {
            if (filterForm.checkValidity()) api.ajax.reload();
        });
        filterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            api.ajax.reload();
        });
    }
    retry.addEventListener('click', () => api.ajax.reload());
    const container = api.table().container();
    container.querySelector('input[type="search"]')?.setAttribute('maxlength', '100');
    container.querySelector('input[type="search"]')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });
    const scroll = container.querySelector('.dt-layout-table > .dt-layout-cell');
    if (scroll) { scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', `${panel.getAttribute('aria-label')} table`); scroll.tabIndex = 0; }
    panel.querySelector('.data-table-region').removeAttribute('tabindex');
    panel.classList.add('is-enhanced');
    panel.querySelector('[data-table-fallback]').hidden = true;
    panel.querySelector('.data-panel-hint').hidden = true;
    if (legacySearch) {
        legacySearch.disabled = true;
        const wrapper = legacySearch.closest('label') || legacySearch.parentElement;
        wrapper.hidden = true;
        if (!filterForm.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')) {
            filterForm.querySelectorAll('button:not([type]), button[type="submit"]').forEach((button) => button.hidden = true);
            if (!filterForm.querySelector('a')) filterForm.hidden = true;
        }
    }
    syncFilters(initialSearch, config.length);
});
