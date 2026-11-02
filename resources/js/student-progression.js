document.querySelectorAll('[data-progression-dialog]').forEach((dialog) => {
    const initial = JSON.parse(dialog.querySelector('[data-progression-initial]').textContent);
    const field = (name) => dialog.querySelector(`[data-progression-${name}]`);
    const sourceYear = field('source-year'), sourceClass = field('source-class'), sourceSection = field('source-section');
    const targetYear = field('target-year'), targetClass = field('target-class'), targetSection = field('target-section');
    const selected = new Map();
    let step = 1, page = 1, roster = null, busy = false, saving = false, completed = false;
    let rosterController, requestNumber = 0, searchTimer, reviewToken, returnFocus, initialized = false;
    const params = new URLSearchParams(location.search);
    let requestedEnrollment = params.get('progression_student');
    const uuid = () => crypto.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
    let operationKey = uuid();
    const source = () => ({source_academic_year_id:sourceYear.value, source_class_id:sourceClass.value, source_section_id:sourceSection.value});
    const currentClass = () => initial.classes.find((row) => String(row.id) === sourceClass.value);
    const eligibleSections = () => currentClass()?.active ? currentClass().sections.filter((row) => row.active) : [];
    const hasPromotions = () => [...selected.values()].some((row) => row.action === 'PROMOTE');
    const clearError = () => { field('error').hidden = true; field('error').replaceChildren(); field('retry').hidden = true; };
    const showError = (messages) => {
        field('error').replaceChildren();
        [...new Set(messages)].forEach((message) => { const p = document.createElement('p'); p.textContent = message; field('error').append(p); });
        field('error').hidden = false;
        field('error').scrollIntoView({block:'nearest'});
    };
    const optionList = (select, rows, placeholder, value = '') => {
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach((row) => select.add(new Option(row.name, String(row.id))));
        select.value = rows.some((row) => String(row.id) === String(value)) ? String(value) : '';
    };
    const textCell = (row, value, tag = 'td') => { const cell = document.createElement(tag); cell.textContent = value; row.append(cell); return cell; };
    const studentCell = (tr, student) => {
        const cell = textCell(tr, ''), name = document.createElement('strong'), number = document.createElement('small');
        name.textContent = student.name; number.textContent = student.admission_number; cell.append(name, number);
    };
    const messageRow = (root, text, span = 4) => { const tr = document.createElement('tr'); const cell = textCell(tr, text); cell.colSpan = span; root.replaceChildren(tr); };
    const labelCells = (tr, labels) => [...tr.cells].forEach((cell, index) => cell.dataset.label = labels[index]);
    const refresh = () => {
        dialog.querySelectorAll('[data-progression-step]').forEach((panel) => panel.hidden = completed || Number(panel.dataset.progressionStep) !== step);
        dialog.querySelectorAll('[data-progression-step-label]').forEach((label) => {
            if (!completed && Number(label.dataset.progressionStepLabel) === step) label.setAttribute('aria-current', 'step');
            else label.removeAttribute('aria-current');
        });
        field('loading').hidden = !busy;
        field('continue').hidden = completed;
        field('continue').disabled = busy || (step === 1 && selected.size === 0);
        field('continue').textContent = saving ? 'Recording outcomes…' : step === 1 ? `Continue${selected.size ? ` (${selected.size})` : ''}` : step === 2 ? 'Review moves' : 'Confirm and move students';
        field('cancel').hidden = completed; field('back').hidden = completed || step === 1;
        field('back').disabled = busy; field('close').disabled = saving; field('cancel').disabled = saving;
        field('remaining').hidden = !completed; field('done').hidden = !completed;
        [sourceYear, sourceClass, sourceSection, field('search')].forEach((input) => input.disabled = busy || step !== 1);
        sourceSection.disabled ||= !sourceClass.value;
        field('select-page').disabled = busy || !roster?.rows.some((row) => row.eligible);
        field('clear-selection').disabled = busy || !selected.size;
        field('previous').disabled = busy || !roster || page <= 1;
        field('next').disabled = busy || !roster || page >= roster.last_page;
        const visible = roster?.rows.filter((row) => row.eligible) || [];
        const checked = visible.filter((row) => selected.has(row.id)).length;
        field('select-page').checked = visible.length > 0 && checked === visible.length;
        field('select-page').indeterminate = checked > 0 && checked < visible.length;
        field('selection').textContent = `${selected.size} selected${roster ? ` · ${roster.eligible_count} eligible in this section` : ''}`;
        field('default-destination').hidden = !hasPromotions();
        dialog.querySelectorAll('[data-progression-step="2"] input, [data-progression-step="2"] select').forEach((input) => input.disabled = busy);
        targetSection.disabled ||= !targetClass.value;
    };
    const renderRoster = () => {
        const body = field('roster'); body.replaceChildren();
        if (!roster?.rows.length) messageRow(body, roster ? 'No students match this section and search.' : 'Choose an academic year, class and section to load students.');
        roster?.rows.forEach((student) => {
            const tr = document.createElement('tr');
            const cell = textCell(tr, ''), checkbox = document.createElement('input');
            checkbox.type = 'checkbox'; checkbox.checked = selected.has(student.id); checkbox.disabled = !student.eligible || busy;
            checkbox.setAttribute('aria-label', `Select ${student.name}`);
            checkbox.addEventListener('change', () => { choose(student, checkbox.checked); checkbox.checked = selected.has(student.id); refresh(); });
            cell.append(checkbox); studentCell(tr, student); textCell(tr, student.roll_number);
            const readiness = textCell(tr, student.reason); readiness.className = student.eligible ? 'progression-ready' : 'progression-muted';
            body.append(tr);
        });
        field('page-summary').textContent = roster ? `${roster.total} matching students · Page ${page} of ${roster.last_page}` : '';
        field('page').textContent = roster ? String(page) : '';
        refresh();
    };
    const choose = (student, checked) => {
        if (!checked) { selected.delete(student.id); return; }
        if (selected.has(student.id) || !student.eligible) return;
        if (selected.size >= 100) { showError(['Select up to 100 students per batch. Process the remaining students in another batch.']); return; }
        selected.set(student.id, {...student, action:'PROMOTE', repeat_section:eligibleSections().some((row) => String(row.id) === sourceSection.value) ? sourceSection.value : '', manual_roll:''});
    };
    const fetchJSON = async (url, options = {}) => {
        const response = await fetch(url, {credentials:'same-origin', cache:'no-store', ...options, headers:{Accept:'application/json', 'X-CSRF-TOKEN':field('form').querySelector('[name="_token"]').value, ...options.headers}});
        let result;
        try { result = await response.json(); } catch { result = {}; }
        if (!response.ok || response.redirected) {
            const error = new Error('Request failed');
            error.messages = result.errors ? Object.values(result.errors).flat() : [response.status === 403 ? 'Your permission changed. Reload the page before continuing.' : response.status === 419 ? 'Your session expired. Reload the page and sign in again.' : response.status === 404 ? 'A selected year, class or section is no longer available. Refresh your selection.' : 'Could not complete the request. Your choices are preserved; try again.'];
            throw error;
        }
        return result;
    };
    const loadRoster = async (nextPage = 1) => {
        clearTimeout(searchTimer); rosterController?.abort(); const request = ++requestNumber;
        if (!sourceYear.value || !sourceClass.value || !sourceSection.value) { busy = false; roster = null; page = 1; renderRoster(); return; }
        rosterController = new AbortController(); busy = true; clearError(); refresh(); renderRoster();
        try {
            const url = new URL(dialog.dataset.rosterUrl, location.href);
            Object.entries({...source(), q:field('search').value, page:nextPage}).forEach(([key, value]) => url.searchParams.set(key, value));
            const result = await fetchJSON(url, {signal:rosterController.signal});
            if (request !== requestNumber) return;
            roster = result; page = result.page;
            result.rows.forEach((row) => { if (!row.eligible) selected.delete(row.id); });
            if (requestedEnrollment) { const student = result.rows.find((row) => String(row.id) === requestedEnrollment); if (student) choose(student, true); requestedEnrollment = null; }
        } catch (error) {
            if (error.name === 'AbortError' || request !== requestNumber) return;
            roster = null; showError(error.messages || ['Could not load students. Your selection is preserved. Retry loading students.']); field('retry').hidden = false;
        } finally { if (request === requestNumber) { busy = false; renderRoster(); } }
    };
    const populateSourceSections = (value = '') => optionList(sourceSection, currentClass()?.sections || [], 'Choose a section', value);
    const resetSource = () => {
        const count = selected.size; selected.clear(); reviewToken = null; operationKey = uuid(); field('search').value = '';
        field('context-reset').textContent = count ? 'Selection cleared because the source year, class or section changed.' : '';
        optionList(targetYear, [], 'Choose the next academic year'); optionList(targetClass, [], 'Choose the next class'); optionList(targetSection, [], 'Choose a section');
        loadRoster();
    };
    const populateTargetSections = (value = '') => {
        const sections = initial.classes.find((row) => String(row.id) === targetClass.value)?.sections.filter((row) => row.active) || [];
        optionList(targetSection, sections, 'Choose a section', value); targetSection.disabled = !targetClass.value;
    };
    const updateJoiningDate = () => {
        const year = initial.years.find((row) => String(row.id) === targetYear.value);
        field('joined').value = year?.start || ''; field('joined').min = year?.start || ''; field('joined').max = year?.end || '';
    };
    const renderDecisions = () => {
        const body = field('decisions'); body.replaceChildren();
        selected.forEach((student) => {
            const tr = document.createElement('tr'); studentCell(tr, student);
            const actionCell = textCell(tr, ''), action = document.createElement('select');
            action.add(new Option('Promote', 'PROMOTE')); action.add(new Option('Repeat', 'REPEAT')); action.value = student.action;
            action.setAttribute('aria-label', `Decision for ${student.name}`);
            action.addEventListener('change', () => { student.action = action.value; renderDecisions(); refresh(); }); actionCell.append(action);
            const sectionCell = textCell(tr, '');
            if (student.action === 'REPEAT') {
                const section = document.createElement('select'); optionList(section, eligibleSections(), 'Choose a section', student.repeat_section);
                section.setAttribute('aria-label', `Repeat section for ${student.name}`); section.required = true;
                section.addEventListener('change', () => student.repeat_section = section.value); sectionCell.append(section);
            } else sectionCell.textContent = 'Uses destination above';
            const rollCell = textCell(tr, ''), roll = document.createElement('input'); roll.type = 'number'; roll.min = '1'; roll.max = '4294967295'; roll.step = '1'; roll.inputMode = 'numeric'; roll.placeholder = 'Automatic'; roll.value = student.manual_roll;
            roll.setAttribute('aria-label', `New roll for ${student.name}`); roll.addEventListener('input', () => student.manual_roll = roll.value); rollCell.append(roll); labelCells(tr, ['Student', 'Decision', 'Repeat section', 'New roll']); body.append(tr);
        });
    };
    const showDestinations = () => {
        const year = initial.years.find((row) => String(row.id) === sourceYear.value);
        const targets = initial.years.filter((row) => row.active && row.start > year.end);
        const previous = targetYear.value; optionList(targetYear, targets, targets.length ? 'Choose the next academic year' : 'Create an active later academic year first', previous || (targets.length === 1 ? targets[0].id : ''));
        if (!field('joined').value || previous !== targetYear.value) updateJoiningDate();
        optionList(targetClass, initial.classes.filter((row) => row.active && String(row.id) !== sourceClass.value), 'Choose the next class', targetClass.value);
        populateTargetSections(targetSection.value); renderDecisions();
    };
    const scrollTop = () => { dialog.querySelector('.student-modal-content').scrollTop = 0; };
    const validateStep = (number) => {
        const root = dialog.querySelector(`[data-progression-step="${number}"]`);
        const invalid = [...root.querySelectorAll('input,select')].find((input) => input.willValidate && !input.checkValidity());
        if (invalid) { invalid.reportValidity(); return false; } return true;
    };
    const command = () => {
        const firstRepeat = [...selected.values()].find((row) => row.action === 'REPEAT');
        return {operation_key:operationKey, ...source(), target_academic_year_id:targetYear.value, target_class_id:hasPromotions() ? targetClass.value : sourceClass.value, target_section_id:hasPromotions() ? targetSection.value : firstRepeat?.repeat_section, joined_at:field('joined').value,
            rows:[...selected.values()].map((row) => ({source_enrollment_id:row.id, action:row.action, roll_number:row.manual_roll || null, ...(row.action === 'REPEAT' ? {target_section_id:row.repeat_section} : {})}))};
    };
    const review = async () => {
        targetClass.required = hasPromotions(); targetSection.required = hasPromotions();
        if (!validateStep(2)) return;
        busy = true; clearError(); refresh();
        try {
            const result = await fetchJSON(dialog.dataset.reviewUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(command())});
            reviewToken = result.review_token; field('confirm').checked = false;
            field('review-counts').textContent = `${result.summary.promoted} promoted · ${result.summary.repeating} repeating · ${result.summary.left_for_later} left for later`;
            field('review-context').textContent = `${result.source} → ${result.target_year} · Joining ${result.joined_at}`;
            const body = field('review-rows'); body.replaceChildren();
            result.rows.forEach((student) => { const tr = document.createElement('tr'); studentCell(tr, student); textCell(tr, student.action === 'REPEAT' ? 'Repeat' : 'Promote'); textCell(tr, `${student.class_name} / ${student.section_name}`); textCell(tr, student.roll_number); labelCells(tr, ['Student', 'Decision', 'New placement', 'New roll']); body.append(tr); });
            step = 3; scrollTop();
        } catch (error) { showError(error.messages || ['Could not review this selection. Your choices are preserved; try again.']); }
        finally { busy = false; refresh(); }
    };
    const confirm = async () => {
        if (!reviewToken || !validateStep(3)) return;
        saving = true; busy = true; clearError(); refresh();
        try {
            const result = await fetchJSON(dialog.dataset.storeUrl, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({review_token:reviewToken, confirmed:true})});
            completed = true; field('success').hidden = false; field('success-counts').textContent = `${result.promoted} promoted · ${result.repeating} repeating`;
            field('history').href = result.history_url; scrollTop();
        } catch (error) { showError(error.messages || ['Confirmation could not be verified. Retry confirmation with the same review; duplicate outcomes will not be created.']); }
        finally { saving = false; busy = false; refresh(); }
    };
    const close = () => {
        if (saving) return;
        if (completed) { location.reload(); return; }
        clearTimeout(searchTimer); rosterController?.abort(); ++requestNumber; busy = false; dialog.close(); document.documentElement.classList.remove('student-modal-open'); returnFocus?.focus(); refresh();
    };
    const open = (trigger) => {
        returnFocus = trigger || document.activeElement;
        dialog.showModal(); document.documentElement.classList.add('student-modal-open');
        if (!initialized) {
            initialized = true;
            const filters = document.querySelector('[data-student-list-filters]');
            optionList(sourceYear, initial.years, 'Choose an academic year', params.get('progression_year') || initial.defaults.year);
            optionList(sourceClass, initial.classes, 'Choose a class', params.get('progression_class') || filters?.querySelector('[name="class_id"]')?.value || initial.defaults.class);
            populateSourceSections(params.get('progression_section') || filters?.querySelector('[name="section_id"]')?.value || initial.defaults.section);
            field('search').value = params.get('progression_search') || ''; loadRoster();
            if (params.get('progression') === '1') {
                const url = new URL(location.href);
                [...url.searchParams.keys()].filter((key) => key === 'progression' || key.startsWith('progression_')).forEach((key) => url.searchParams.delete(key));
                history.replaceState(null, '', url);
            }
        }
        refresh();
    };
    document.querySelectorAll('[data-progression-open]').forEach((button) => button.addEventListener('click', () => open(button)));
    [field('close'), field('cancel')].forEach((button) => button.addEventListener('click', close));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => { if (event.target !== dialog) return; const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close(); });
    sourceYear.addEventListener('change', resetSource);
    sourceClass.addEventListener('change', () => { populateSourceSections(); resetSource(); });
    sourceSection.addEventListener('change', resetSource);
    targetYear.addEventListener('change', updateJoiningDate);
    targetClass.addEventListener('change', () => populateTargetSections());
    field('search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadRoster(), 300); });
    field('previous').addEventListener('click', () => loadRoster(page - 1)); field('next').addEventListener('click', () => loadRoster(page + 1));
    field('retry').addEventListener('click', () => loadRoster(page));
    field('select-page').addEventListener('change', (event) => { roster?.rows.forEach((row) => choose(row, event.target.checked)); renderRoster(); });
    field('clear-selection').addEventListener('click', () => { selected.clear(); renderRoster(); });
    field('continue').addEventListener('click', () => { if (busy) return; if (step === 1 && selected.size) { clearError(); step = 2; showDestinations(); refresh(); scrollTop(); } else if (step === 2) review(); else if (step === 3) confirm(); });
    field('back').addEventListener('click', () => { if (busy) return; clearError(); reviewToken = null; operationKey = uuid(); --step; refresh(); if (step === 1) loadRoster(page); scrollTop(); });
    field('remaining').addEventListener('click', () => { completed = false; step = 1; selected.clear(); reviewToken = null; operationKey = uuid(); field('success').hidden = true; field('search').value = ''; clearError(); refresh(); loadRoster(); scrollTop(); });
    field('done').addEventListener('click', () => location.reload());
    field('form').addEventListener('submit', (event) => { event.preventDefault(); field('continue').click(); });
    refresh();
    if (params.get('progression') === '1') open(document.querySelector('[data-progression-open]'));
});
