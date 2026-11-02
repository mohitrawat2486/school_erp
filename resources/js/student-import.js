import {parseClipboard, autoMap, mappingErrors, localValidation, summary} from './student-import-data';

const root = document.querySelector('[data-student-import]');
if (root) {
    const config = JSON.parse(root.querySelector('[data-import-config]').textContent);
    const node = name => root.querySelector(`[data-import-${name}]`);
    const state = {sourceRows: [], rawRows: [], columnMappings: [], normalizedRows: [], validationResults: [], filters: 'all', page: 1, reviewToken: null, expires: 0, checked: false, sourceStale: false, busy: false, serial: 0, importStatus: {chunk: 0, warningChoice: null, imported: new Map(), skipped: new Map(), done: false}};
    let timer, controller;
    const settings = () => ({date_format: node('date-format').value, admission_date: node('admission-date').value || null, academic_year_id: node('year').value ? Number(node('year').value) : null});
    const element = (tag, text, className) => { const item = document.createElement(tag); if (text !== undefined) item.textContent = text; if (className) item.className = className; return item; };
    const error = messages => { node('error').replaceChildren(...messages.map(message => element('p', message))); node('error').hidden = !messages.length; };
    const stage = number => root.querySelectorAll('[data-import-step]').forEach(item => { Number(item.dataset.importStep) === number ? item.setAttribute('aria-current', 'step') : item.removeAttribute('aria-current'); });
    const eligible = () => state.validationResults.filter(row => row.status === 'valid' || (row.status === 'warning' && node('warnings').checked)).filter(row => !state.importStatus.imported.has(row.row)).length;
    const invalidate = () => { state.serial++; controller?.abort(); state.reviewToken = null; node('review').disabled = true; node('live').textContent = 'Data changed. Validate again before importing.'; };
    const serialize = rows => rows.map(row => row.map(value => /[\t\r\n"]/.test(value) ? '"' + value.replaceAll('"', '""') + '"' : value).join('\t')).join('\n');
    const updateSummary = () => {
        const totals = summary(state.validationResults);
        const cards = [['Total rows', state.rawRows.length, 'blue'], ['Valid', totals.valid, 'green'], ['Warnings', totals.warning, 'orange'], ['Invalid', totals.invalid, 'red']];
        node('summary').replaceChildren(...cards.map(([label, count, color]) => { const card = element('div', undefined, 'classes-stat'); const icon = element('span', label === 'Valid' ? '✓' : label === 'Invalid' ? '×' : label === 'Warnings' ? '!' : '#', `classes-stat-icon is-${color}`); const content = element('div'); content.append(element('strong', String(count)), element('span', label)); card.append(icon, content); return card; }));
        node('review').disabled = !state.reviewToken || !eligible() || state.busy || state.importStatus.done;
        node('review').textContent = state.reviewToken ? `Review ${eligible()} verified ${eligible() === 1 ? 'student' : 'students'}` : 'Review import';
    };
    const visibleRows = () => state.rawRows.map((row, index) => ({cells: row, index, validation: state.validationResults[index]})).filter(row => state.filters === 'all' || row.validation?.status === state.filters);
    const renderGrid = () => {
        const active = document.activeElement, focusKey = active?.dataset.importCell, selection = active?.selectionStart;
        const head = element('tr'); head.append(element('th', 'Row'), element('th', 'Status'));
        state.columnMappings.forEach((field, column) => {
            const th = element('th'), label = element('label', (node('header').checked ? state.sourceRows[0]?.[column] : '') || `Column ${column + 1}`);
            const select = element('select'); select.id = `import-mapping-${column}`; label.htmlFor = select.id; select.dataset.importMapping = String(column); select.setAttribute('aria-label', `Map column ${column + 1}`); select.append(new Option('Do not import', ''));
            for (const [value, metadata] of Object.entries(config.fields)) {
                const option = new Option(metadata.label, value); const used = state.columnMappings.includes(value) && value !== field;
                const nameConflict = (value === 'student_name' && state.columnMappings.some(value => ['first_name', 'middle_name', 'last_name'].includes(value))) || (['first_name', 'middle_name', 'last_name'].includes(value) && state.columnMappings.includes('student_name'));
                option.disabled = used || nameConflict; select.append(option);
            }
            select.value = field || ''; select.disabled = state.busy || state.importStatus.done; th.append(label, select); head.append(th);
        });
        node('grid-head').replaceChildren(head);
        const filtered = visibleRows(), last = Math.max(1, Math.ceil(filtered.length / 25)); state.page = Math.min(state.page, last);
        const body = document.createDocumentFragment();
        for (const {cells, index, validation} of filtered.slice((state.page - 1) * 25, state.page * 25)) {
            const tr = element('tr'); tr.append(element('td', String(index + 1)));
            const status = state.importStatus.imported.has(index + 1) ? 'imported' : validation?.status || 'unchecked';
            const statusCell = element('td'); statusCell.append(element('span', {valid: '✓ Valid', warning: '! Warning', invalid: '× Invalid', imported: '✓ Imported', unchecked: '— Not checked'}[status], `import-row-status is-${status}`));
            const messages = [...(validation?.messages || []), ...(state.importStatus.skipped.get(index + 1)?.messages || [])];
            for (const [field, cell] of Object.entries(validation?.fields || {})) if (!state.columnMappings.includes(field) && cell.status === 'invalid') messages.push(`${config.fields[field]?.label || field}: ${cell.messages.join(' ')}`);
            if (messages.length) statusCell.append(element('p', messages.join(' '), 'import-row-message'));
            tr.append(statusCell);
            state.columnMappings.forEach((field, column) => {
                const cell = field ? validation?.fields[field] : null, tone = !field ? 'ignored' : cell?.status || 'unchecked';
                const td = element('td', undefined, `import-cell is-${tone}`), editor = element('textarea'); editor.rows = 1; editor.maxLength = 2000; editor.value = cells[column] || ''; editor.dataset.importCell = `${index}:${column}`; editor.setAttribute('aria-label', `Row ${index + 1}, ${config.fields[field]?.label || 'ignored column ' + (column + 1)}`);
                editor.readOnly = !field || state.busy || state.importStatus.done || state.importStatus.imported.has(index + 1);
                const message = cell?.messages?.join(' ') || (!field ? 'Column ignored.' : cell ? 'Valid value.' : 'Not yet verified.'); editor.title = message;
                editor.setAttribute('aria-invalid', String(tone === 'invalid')); const help = element('div', message, 'import-cell-message'); help.id = `import-cell-${index}-${column}-help`; editor.setAttribute('aria-describedby', help.id);
                td.append(editor, element('span', {valid: '✓', warning: '!', invalid: '×', ignored: '—', unchecked: '·'}[tone], 'import-cell-icon'));
                if (cell && cell.normalizedValue !== null && String(cell.normalizedValue) !== editor.value) td.append(element('p', '→ ' + cell.normalizedValue, 'import-normalized'));
                if (tone === 'valid') help.classList.add('sr-only'); td.append(help); tr.append(td);
            });
            body.append(tr);
        }
        if (!filtered.length) { const tr = element('tr'), td = element('td', 'No rows match this filter.'); td.colSpan = state.columnMappings.length + 2; tr.append(td); body.append(tr); }
        node('grid-body').replaceChildren(body); node('page').textContent = `${state.page} / ${last}`;
        node('page-summary').textContent = `${filtered.length} rows · Showing ${filtered.length ? (state.page - 1) * 25 + 1 : 0}–${Math.min(state.page * 25, filtered.length)}`;
        node('previous').disabled = state.page <= 1; node('next').disabled = state.page >= last;
        node('fill').hidden = !state.rawRows.some(row => row.length !== state.columnMappings.length);
        if (focusKey) { const editor = root.querySelector(`[data-import-cell="${focusKey}"]`); if (editor) { editor.focus({preventScroll: true}); editor.setSelectionRange(selection, selection); } }
    };
    const setBusy = busy => { state.busy = busy; for (const name of ['paste', 'header', 'parse', 'clear', 'date-format', 'admission-date', 'year', 'validate', 'fill', 'warnings']) node(name).disabled = busy || (name === 'warnings' && state.importStatus.warningChoice !== null); root.querySelectorAll('[data-import-mapping]').forEach(select => select.disabled = busy || state.importStatus.done); root.querySelectorAll('[data-import-cell]').forEach(editor => editor.readOnly = busy || state.importStatus.done || !state.columnMappings[Number(editor.dataset.importCell.split(':')[1])] || state.importStatus.imported.has(Number(editor.dataset.importCell.split(':')[0]) + 1)); updateSummary(); };
    const parse = (advance = true) => {
        if (state.busy) return;
        try {
            const rows = parseClipboard(node('paste').value, config);
            const guessed = autoMap(rows[0], config.fields);
            const header = node('header').checked || (!state.rawRows.length && guessed.filter(Boolean).length >= 2);
            const rawRows = header ? rows.slice(1) : rows;
            if (!rawRows.length || rawRows.length > config.maxRows) throw new Error(`Paste 1–${config.maxRows} student rows, excluding the header.`);
            const width = Math.max(...rows.map(row => row.length));
            state.sourceRows = rows; state.rawRows = rawRows; state.sourceStale = false; node('header').checked = header;
            state.columnMappings = node('header').checked ? [...guessed, ...Array(Math.max(0, width - guessed.length)).fill(null)] : Array(width).fill(null);
            state.validationResults = []; state.normalizedRows = []; state.importStatus = {chunk: 0, warningChoice: null, imported: new Map(), skipped: new Map(), done: false}; state.checked = false; state.page = 1; state.filters = 'all'; invalidate(); error([]); stage(advance ? 2 : 1); node('preview').hidden = false; node('result').hidden = true; renderGrid(); updateSummary();
            node('live').textContent = `${state.rawRows.length} pasted rows. Review the column mappings, then validate.`;
            if (advance) node('paste-panel').open = false;
            if (advance) node('preview').scrollIntoView({block: 'start', behavior: 'smooth'});
        } catch (failure) { state.sourceStale = true; invalidate(); error([failure.message]); }
    };
    const api = async (url, data, signal) => {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': root.querySelector('[name="_token"]').value}, body: JSON.stringify(data), signal});
        const result = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Request failed. Your pasted data is still here. Check your connection and try again.');
        return result;
    };
    const validate = async () => {
        if (state.busy || !state.rawRows.length) return;
        if (state.sourceStale) { error(['The paste area changed. Choose Continue to mapping to refresh the preview.']); return; }
        const errors = mappingErrors(state.columnMappings, settings()); if (errors.length) { error(errors); return; }
        invalidate(); state.checked = true; const serial = state.serial; controller = new AbortController(); error([]); stage(3);
        state.validationResults = localValidation(state.rawRows, state.columnMappings, config.fields); renderGrid(); updateSummary(); node('live').textContent = 'Checking every row on the server…';
        try {
            const result = await api(config.validateUrl, {rows: state.rawRows, mappings: state.columnMappings, settings: settings()}, controller.signal);
            if (serial !== state.serial) return;
            state.validationResults = result.rows; state.normalizedRows = result.rows.map(row => row.values); state.reviewToken = result.review_token; state.expires = Date.now() + result.expires_in * 1000;
            state.importStatus.chunk = 0; state.importStatus.warningChoice = null; node('warnings').disabled = false; state.importStatus.done = false; stage(4); renderGrid(); updateSummary(); node('live').textContent = `Verified ${result.summary.total} rows. ${result.summary.invalid} invalid ${result.summary.invalid === 1 ? 'row' : 'rows'} will be skipped. Review the normalized values and warnings.`;
        } catch (failure) { if (failure.name !== 'AbortError' && serial === state.serial) { error([failure.message]); node('live').textContent = 'Validation did not finish. Your data and mappings are still available. Try Validate data again.'; } }
    };
    const changed = () => { invalidate(); if (state.checked) { clearTimeout(timer); timer = setTimeout(validate, 500); } };
    node('parse').addEventListener('click', () => parse());
    node('paste').addEventListener('paste', () => setTimeout(() => parse(false), 0));
    node('paste').addEventListener('input', () => { state.sourceStale = true; invalidate(); });
    node('header').addEventListener('change', () => parse(false));
    node('clear').addEventListener('click', () => { controller?.abort(); state.serial++; state.sourceRows = []; state.rawRows = []; state.columnMappings = []; state.validationResults = []; state.reviewToken = null; state.checked = false; node('paste-panel').open = true; node('paste').value = ''; node('header').checked = false; node('preview').hidden = true; node('result').hidden = true; node('live').textContent = ''; error([]); stage(1); });
    for (const name of ['date-format', 'admission-date', 'year']) node(name).addEventListener('change', changed);
    node('grid-head').addEventListener('change', event => { const column = event.target.dataset.importMapping; if (column === undefined) return; state.columnMappings[Number(column)] = event.target.value || null; changed(); state.validationResults = []; renderGrid(); updateSummary(); root.querySelector(`[data-import-mapping="${column}"]`)?.focus(); });
    node('grid-body').addEventListener('input', event => { const key = event.target.dataset.importCell; if (!key) return; const [row, column] = key.split(':').map(Number); state.rawRows[row][column] = event.target.value; state.sourceRows = node('header').checked ? [state.sourceRows[0], ...state.rawRows] : state.rawRows; node('paste').value = serialize(state.sourceRows); changed(); });
    node('fill').addEventListener('click', () => { state.rawRows = state.rawRows.map(row => [...row, ...Array(Math.max(0, state.columnMappings.length - row.length)).fill('')]); state.sourceRows = node('header').checked ? [state.sourceRows[0], ...state.rawRows] : state.rawRows; node('paste').value = serialize(state.sourceRows); changed(); renderGrid(); });
    node('validate').addEventListener('click', validate);
    root.querySelectorAll('[data-import-filter]').forEach(button => button.addEventListener('click', () => { state.filters = button.dataset.importFilter; state.page = 1; root.querySelectorAll('[data-import-filter]').forEach(item => item.setAttribute('aria-pressed', String(item === button))); renderGrid(); }));
    node('previous').addEventListener('click', () => { state.page--; renderGrid(); }); node('next').addEventListener('click', () => { state.page++; renderGrid(); });
    node('warnings').addEventListener('change', updateSummary);
    const dialog = node('confirm');
    node('review').addEventListener('click', () => { if (!state.reviewToken || state.expires < Date.now()) { invalidate(); error(['The review expired. Validate again before importing.']); return; } stage(4); node('confirm-check').checked = false; node('start').disabled = true; node('confirm-summary').textContent = `Import ${eligible()} verified ${eligible() === 1 ? 'student' : 'students'}. ${state.rawRows.length - eligible() - state.importStatus.imported.size} rows will be skipped. ${node('warnings').checked ? 'Non-blocking warnings are included.' : 'Rows with warnings are excluded.'}`; dialog.showModal(); document.documentElement.classList.add('class-help-open'); dialog.querySelector('h2').focus(); });
    const close = () => dialog.close(); root.querySelectorAll('[data-import-cancel]').forEach(button => button.addEventListener('click', close)); dialog.addEventListener('close', () => { document.documentElement.classList.remove('class-help-open'); node('review').focus(); });
    dialog.addEventListener('click', event => { if (event.target !== dialog) return; const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close(); });
    dialog.addEventListener('keydown', event => { if (event.key !== 'Tab') return; const items = [...dialog.querySelectorAll('button:not([disabled]), input')], first = items[0], last = items.at(-1); if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.querySelector('h2'))) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } });
    node('confirm-check').addEventListener('change', () => node('start').disabled = !node('confirm-check').checked);
    node('start').addEventListener('click', async () => {
        if (!state.reviewToken || !node('confirm-check').checked || state.busy) return;
        state.importStatus.warningChoice ??= node('warnings').checked; close(); setBusy(true); stage(5); error([]); let completed = false;
        try {
            do {
                node('live').textContent = `Importing batch ${state.importStatus.chunk + 1}… ${state.importStatus.imported.size} students saved.`;
                const result = await api(config.importUrl, {review_token: state.reviewToken, chunk: state.importStatus.chunk, include_warnings: state.importStatus.warningChoice});
                for (const row of result.imported_rows) state.importStatus.imported.set(row.row, row);
                for (const row of result.skipped_rows) state.importStatus.skipped.set(row.row, row);
                state.importStatus.chunk++; completed = result.done; renderGrid();
            } while (!completed);
            state.importStatus.done = true; node('live').textContent = 'Import completed.';
        } catch (failure) { error([failure.message, `The current batch was not confirmed. ${state.importStatus.imported.size} students from earlier confirmed batches remain saved. Use Review import to retry safely, or validate again after corrections.`]); }
        finally { setBusy(false); renderGrid(); node('result').hidden = false; node('result-text').textContent = `${state.importStatus.imported.size} imported · ${state.importStatus.skipped.size} skipped · ${state.rawRows.length - state.importStatus.imported.size - state.importStatus.skipped.size} not yet confirmed. ${completed ? 'No invalid rows were imported.' : 'The pasted data remains available.'}`; }
    });
}
