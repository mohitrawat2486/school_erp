// Teacher choices remain a local draft until the parent class form is saved.
document.querySelectorAll('[data-class-teachers]').forEach((panel) => {
    const stateElement = panel.querySelector('[data-teacher-state]');
    if (!stateElement) return;
    const config = JSON.parse(stateElement.textContent);
    const form = panel.closest('form');
    const changesInput = panel.querySelector('[data-teacher-changes]');
    const dialog = panel.querySelector('[data-teacher-dialog]');
    const search = panel.querySelector('[data-teacher-search]');
    const results = panel.querySelector('[data-teacher-results]');
    const status = panel.querySelector('[data-teacher-picker-status]');
    const retry = panel.querySelector('[data-teacher-retry]');
    const previous = panel.querySelector('[data-teacher-previous]');
    const next = panel.querySelector('[data-teacher-next]');
    const labels = new Map(config.labels.map((teacher) => [String(teacher.id), teacher]));
    const draft = new Map();
    const expanded = new Set();
    let activeContext = null;
    let pageNumber = 1;
    let totalPages = 1;
    let requestController;
    let timer;
    let queued = false;
    let serial = 0;
    let selectedSections = [];
    let selectedSubjects = [];
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const slotKey = (sectionKey, subjectId) => `${sectionKey}:${subjectId ?? 'class'}`;
    for (const change of Array.isArray(config.changes) ? config.changes.slice(0, 500) : []) {
        if (change && typeof change.section_key === 'string' && (change.teacher_id === null || Number.isInteger(Number(change.teacher_id)))) {
            draft.set(slotKey(change.section_key, change.subject_id), {...change, teacher_id: change.teacher_id === null ? null : Number(change.teacher_id)});
        }
    }
    const initialTeachers = (section, subjectId) => [...new Set(config.assignments.filter((a) =>
        (a.section_id === null || Number(a.section_id) === Number(section.id)) && String(a.subject_id ?? 'class') === String(subjectId ?? 'class')
    ).map((a) => String(a.teacher_id)))].sort();
    const teachersFor = (section, subjectId) => {
        const change = draft.get(slotKey(section.key, subjectId));
        return change ? (change.teacher_id === null ? [] : [String(change.teacher_id)]) : initialTeachers(section, subjectId);
    };
    const teacherText = (ids) => {
        if (!ids.length) return 'Choose teacher';
        if (ids.length > 1) return `${ids.length} teachers`;
        const teacher = labels.get(ids[0]);
        return teacher ? teacher.name + (teacher.eligible === false ? ' (inactive)' : '') : 'Teacher unavailable';
    };
    const focusTrigger = (key) => panel.querySelector(`[data-teacher-trigger="${CSS.escape(key)}"]`)?.focus();
    const choose = (context, teacher) => {
        if (teacher) labels.set(String(teacher.id), teacher);
        for (const section of context.sections) {
            const key = slotKey(section.key, context.subjectId);
            const initial = initialTeachers(section, context.subjectId);
            const ids = teacher ? [String(teacher.id)] : [];
            if (JSON.stringify(ids) === JSON.stringify(initial)) draft.delete(key);
            else draft.set(key, {section_key: section.key, subject_id: context.subjectId, teacher_id: teacher ? Number(teacher.id) : null});
        }
        refresh();
        if (dialog.open) dialog.close();
        focusTrigger(context.triggerKey);
    };
    const pickerControl = (context) => {
        const group = element('div', 'teacher-choice-actions');
        const perSection = context.sections.map((s) => teachersFor(s, context.subjectId));
        const same = perSection.every((ids) => JSON.stringify(ids) === JSON.stringify(perSection[0]));
        const ids = same ? (perSection[0] ?? []) : [];
        const text = same ? teacherText(ids) : 'Different teachers selected';
        const button = element('button', 'teacher-choice-button', text);
        button.type = 'button'; button.dataset.teacherTrigger = context.triggerKey;
        button.title = same ? ids.map((id) => labels.get(id)?.name ?? 'Teacher unavailable').join(', ') || 'Choose teacher' : 'Choose one teacher for all selected sections';
        button.setAttribute('aria-label', `${context.title}: ${text}`);
        button.addEventListener('click', () => openPicker(context));
        group.append(button);
        if (perSection.some((list) => list.length)) {
            const clear = element('button', 'teacher-clear-button', 'Clear');
            clear.type = 'button'; clear.setAttribute('aria-label', `Clear ${context.title}`);
            clear.addEventListener('click', () => choose(context, null)); group.append(clear);
        }
        return group;
    };
    function refresh() {
        const sectionArea = form.querySelector('[data-setup-sections]');
        if (sectionArea) {
            const skipped = sectionArea.querySelector('[data-skip-sections]')?.checked;
            selectedSections = skipped ? [] : [...sectionArea.querySelectorAll('[data-section-choice]:checked')].map((input) => ({key: input.dataset.sectionChoice, name: input.dataset.sectionChoice}));
            if (!skipped) {
                for (const input of sectionArea.querySelectorAll('[data-section-name]')) {
                    const match = input.name.match(/^sections\[([^\]]+)\]\[name\]$/);
                    if (match && input.value.trim()) selectedSections.push({key: match[1], name: input.value.trim()});
                }
            }
            selectedSections = selectedSections.map((section) => ({...section, id: config.sections.find((s) => s.key === section.key)?.id}));
        } else selectedSections = config.sections;
        const subjectArea = form.querySelector('[data-setup-subjects]');
        selectedSubjects = subjectArea ? [...subjectArea.querySelectorAll('input[type="checkbox"][name="subject_ids[]"]:checked')].map((input) => ({id: Number(input.value), name: input.closest('label').querySelector('span.font-semibold').textContent})) : config.subjects;
        const sectionKeys = new Set(selectedSections.map((s) => s.key));
        const subjectIds = new Set(selectedSubjects.map((s) => Number(s.id)));
        changesInput.value = JSON.stringify([...draft.values()].filter((change) => sectionKeys.has(change.section_key) && (change.subject_id === null || subjectIds.has(Number(change.subject_id)))));
        const changeCount = JSON.parse(changesInput.value).length;
        panel.querySelector('[data-teacher-draft-count]').textContent = changeCount ? `${changeCount} unsaved ${changeCount === 1 ? 'choice' : 'choices'}` : config.assignments.length ? 'Saved assignments' : 'Not assigned yet';
        const classRows = panel.querySelector('[data-class-teacher-rows]'); classRows.replaceChildren();
        const subjectRows = panel.querySelector('[data-subject-teacher-rows]'); subjectRows.replaceChildren();
        if (!selectedSections.length) {
            classRows.append(element('p', 'teacher-panel-empty', 'Choose sections above to assign teachers.'));
            subjectRows.append(element('p', 'teacher-panel-empty', 'Choose sections and subjects above to assign subject teachers.'));
            return;
        }
        for (const section of selectedSections) {
            const row = element('div', 'teacher-assignment-row');
            const text = element('div', 'teacher-row-label'); text.append(element('strong', '', `Section ${section.name}`), element('span', '', 'Class teacher'));
            row.append(text, pickerControl({title: `Class teacher for Section ${section.name}`, sections: [section], subjectId: null, triggerKey: `class-${section.key}`})); classRows.append(row);
        }
        if (!selectedSubjects.length) subjectRows.append(element('p', 'teacher-panel-empty', 'Select subjects above to choose their teachers.'));
        for (const subject of selectedSubjects) {
            const block = element('div', 'teacher-subject-block'); const row = element('div', 'teacher-assignment-row');
            const text = element('div', 'teacher-row-label'); text.append(element('strong', '', subject.name), element('span', '', `Sections ${selectedSections.map((s) => s.name).join(', ')}`));
            row.append(text, pickerControl({title: `${subject.name} — all selected sections`, sections: selectedSections, subjectId: subject.id, triggerKey: `subject-${subject.id}`})); block.append(row);
            if (selectedSections.length > 1) {
                const details = element('details', 'teacher-by-section'); const summary = element('summary', '', 'Different teachers by section');
                details.open = expanded.has(subject.id); details.append(summary);
                const renderSectionRows = () => {
                    if (!details.open || details.querySelector('.teacher-section-rows')) return;
                    const inner = element('div', 'teacher-section-rows');
                    for (const section of selectedSections) {
                        const line = element('div', 'teacher-assignment-row');line.append(element('strong', '', `Section ${section.name}`), pickerControl({title: `${subject.name} for Section ${section.name}`, sections: [section], subjectId: subject.id, triggerKey: `subject-${subject.id}-${section.key}`}));inner.append(line);
                    }
                    details.append(inner);
                };
                details.addEventListener('toggle', () => { if (details.open) expanded.add(subject.id); else expanded.delete(subject.id); renderSectionRows(); });
                renderSectionRows();block.append(details);
            }
            subjectRows.append(block);
        }
    }
    async function loadTeachers(page = 1) {
        requestController?.abort(); requestController = new AbortController();const requestSerial = ++serial;
        results.replaceChildren();results.setAttribute('aria-busy','true');status.textContent='Loading teachers…';retry.hidden=true;previous.disabled=true;next.disabled=true;
        const url = new URL(panel.dataset.teacherOptionsUrl, location.href);url.searchParams.set('q', search.value.trim());url.searchParams.set('page', String(page));
        try {
            const response = await fetch(url, {headers:{Accept:'application/json'},credentials:'same-origin',signal:requestController.signal});
            if (!response.ok) throw new Error(response.status===401 ? 'Your session expired. Sign in again, then reload this page.' : response.status===429 ? 'Please wait a moment, then try searching again.' : 'Could not load teachers. Please try again.');
            const data = await response.json();if (requestSerial!==serial || !dialog.open) return;
            pageNumber=data.current_page;totalPages=data.last_page;
            for (const teacher of data.data) {
                labels.set(String(teacher.id),teacher);
                const button=element('button','teacher-picker-option');button.type='button';button.setAttribute('aria-label',`Select ${teacher.name}`);
                const avatar=element('span','teacher-avatar',teacher.name.split(/\s+/).slice(0,2).map((word)=>word[0]).join('').toUpperCase());avatar.setAttribute('aria-hidden','true');
                const text=element('span','teacher-picker-person');text.append(element('strong','',teacher.name),element('span','',[teacher.specialization,teacher.employee_number].filter(Boolean).join(' · ')));
                button.append(avatar,text,element('span','teacher-picker-select','Select'));button.addEventListener('click',()=>choose(activeContext,teacher));results.append(button);
            }
            status.textContent=data.total ? `${data.total} active ${data.total === 1 ? 'teacher' : 'teachers'} found.` : 'No active teachers found. Add or activate teachers under Employees → Teachers.';
            panel.querySelector('[data-teacher-page-label]').textContent=data.total ? `Page ${pageNumber} of ${totalPages}` : '';
            previous.disabled=pageNumber<=1;next.disabled=pageNumber>=totalPages;
        } catch(error) {
            if (error.name!=='AbortError' && requestSerial===serial) {status.textContent=error.message;retry.hidden=false;}
        } finally {if(requestSerial===serial)results.setAttribute('aria-busy','false');}
    }
    function openPicker(context) {
        activeContext=context;panel.querySelector('[data-teacher-picker-heading]').textContent=`Choose ${context.subjectId===null?'class teacher':'subject teacher'}`;
        panel.querySelector('[data-teacher-picker-description]').textContent=context.title;
        search.value='';dialog.showModal();document.documentElement.classList.add('teacher-dialog-open');search.focus();loadTeachers(1);
    }
    dialog.addEventListener('close',()=>{requestController?.abort();clearTimeout(timer);document.documentElement.classList.remove('teacher-dialog-open');if(activeContext)focusTrigger(activeContext.triggerKey);});
    dialog.addEventListener('click',(event)=>{if(event.target===dialog)dialog.close();});
    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            dialog.close();
        } else if (event.key === 'Enter' && event.target === search) {
            event.preventDefault();
            clearTimeout(timer);
            loadTeachers(1);
        }
    });
    panel.querySelector('[data-teacher-close]').addEventListener('click',()=>dialog.close());
    search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>loadTeachers(1),250);});
    previous.addEventListener('click',()=>loadTeachers(pageNumber-1));next.addEventListener('click',()=>loadTeachers(pageNumber+1));retry.addEventListener('click',()=>loadTeachers(pageNumber));
    form.addEventListener('class-setup:changed',()=>{if(queued)return;queued=true;queueMicrotask(()=>{queued=false;refresh();});});
    panel.querySelector('[data-teacher-content]').hidden=false;changesInput.disabled=false;refresh();
});
