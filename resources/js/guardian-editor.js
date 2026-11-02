document.querySelectorAll('[data-guardian-editor]').forEach((editor) => {
    const fields = editor.querySelector('[data-guardian-editor-fields]');
    const container = editor.querySelector('[data-guardian-cards]');
    const template = editor.querySelector('[data-guardian-template]');
    const options = new Map(JSON.parse(editor.querySelector('[data-guardian-options]').textContent).map((row) => [String(row.id), row]));
    const notice = editor.querySelector('[data-guardian-notice]');
    const confirm = editor.querySelector('[data-guardian-confirm]');
    const cards = () => [...container.querySelectorAll('[data-guardian-card]')];
    const active = () => cards().filter((card) => !card.classList.contains('is-removed'));
    const kind = (card) => card.querySelector('[data-guardian-kind]:checked')?.value || card.querySelector('input[type=hidden][data-guardian-kind]')?.value || 'new';
    const contact = (card) => options.get(card.querySelector('[data-guardian-id]')?.value || '');
    let nextKey = Math.max(-1, ...cards().map((card) => Number(card.dataset.key))) + 1;
    const originalLimit = Math.max(3, cards().filter((card) => card.dataset.savedId).length);
    let removalSignature;

    const refreshCard = (card) => {
        const removed = card.classList.contains('is-removed');
        const body = card.querySelector('[data-guardian-fields]');
        body.disabled = removed; body.hidden = removed;
        card.querySelector('[data-guardian-removed-message]').hidden = !removed;
        card.querySelector('[data-guardian-edit-label]').textContent = removed ? 'Removed' : 'Edit';
        if (removed) { card.open = true; return; }
        const existing = kind(card) === 'existing';
        const option = existing ? contact(card) : null;
        const inactive = option?.status === 'INACTIVE';
        const toggle = card.querySelector('[data-guardian-edit-contact-toggle]');
        const editing = !existing || (toggle.checked && !inactive);
        const picker = card.querySelector('[data-guardian-picker]');
        if (picker) { picker.hidden = !existing; picker.disabled = !existing; card.querySelector('[data-guardian-id]').required = existing; }
        const editArea = card.querySelector('[data-guardian-edit-contact]');
        editArea.hidden = !existing || !option || inactive; editArea.disabled = !existing || !option || inactive;
        const contactFields = card.querySelector('[data-guardian-contact-fields]');
        contactFields.hidden = !editing; contactFields.disabled = !editing;
        contactFields.querySelectorAll('[data-contact-required]').forEach((input) => { input.required = editing; });
        const sameAddress = card.querySelector('[data-guardian-same-address]');
        const address = card.querySelector('[data-guardian-address]');
        address.hidden = sameAddress.checked;
        address.querySelector('textarea').disabled = !editing || sameAddress.checked;
        const shared = card.querySelector('[data-guardian-shared-note]');
        const count = (option?.shared_count || 0) + (card.dataset.savedId ? 0 : 1);
        shared.textContent = option ? (count > 1 ? `Changes to contact details apply to all ${count} linked students. Relationship and contact preferences apply only to this student.` : 'Contact details are shared. Relationship and contact preferences apply only to this student.') : '';
        const name = editing ? [...card.querySelectorAll('[data-contact-field="first_name"], [data-contact-field="last_name"]')].map((input) => input.value.trim()).filter(Boolean).join(' ') : option?.name;
        card.querySelector('[data-guardian-name]').textContent = name || 'New guardian';
        card.querySelector('[data-guardian-phone]').textContent = editing ? card.querySelector('[data-contact-field="phone"]').value : option?.phone || '';
        card.querySelector('[data-guardian-remove]').setAttribute('aria-label', `Remove ${name || 'guardian'}`);
        card.querySelector('[data-guardian-done]').setAttribute('aria-label', `Finish editing ${name || 'guardian'}`);
        card.querySelector('[data-guardian-primary]').disabled = inactive;
        const relationship = card.querySelector('[data-guardian-relationship]').selectedOptions[0]?.textContent || 'Guardian';
        card.querySelector('[data-guardian-summary]').textContent = [relationship, card.querySelector('[data-guardian-primary]').checked ? 'Primary contact' : '', card.querySelector('[data-guardian-emergency]').checked ? 'Emergency' : '', inactive ? 'Inactive' : ''].filter(Boolean).join(' · ');
    };
    const refresh = () => {
        const live = active();
        if (live.length && !live.some((card) => card.querySelector('[data-guardian-primary]').checked)) {
            const candidate = live.find((card) => contact(card)?.status !== 'INACTIVE');
            if (candidate) candidate.querySelector('[data-guardian-primary]').checked = true;
        }
        cards().forEach(refreshCard);
        const ids = live.filter((card) => kind(card) === 'existing').map((card) => card.querySelector('[data-guardian-id]').value).filter(Boolean);
        live.forEach((card) => {
            const select = card.querySelector('select[data-guardian-id]');
            if (select) [...select.options].forEach((option) => { option.disabled = Boolean(option.value && option.value !== select.value && ids.includes(option.value)); });
        });
        editor.querySelector('[data-guardian-count]').textContent = `${live.length} of 3`;
        editor.querySelector('[data-guardian-add]').disabled = live.length >= 3;
        editor.querySelector('[data-guardian-limit]').hidden = live.length < 3;
        editor.querySelector('[data-guardian-empty]').hidden = live.length !== 0;
        const removed = cards().filter((card) => card.classList.contains('is-removed') && card.dataset.savedId);
        const signature = removed.map((card) => card.dataset.savedId).sort().join(',');
        if (removalSignature !== undefined && signature !== removalSignature) confirm.checked = false;
        removalSignature = signature;
        editor.querySelector('[data-guardian-removals]').hidden = removed.length === 0;
        confirm.required = removed.length > 0; confirm.disabled = removed.length === 0;
        editor.querySelector('[data-guardian-removal-names]').textContent = removed.map((card) => card.querySelector('[data-guardian-name]').textContent).join(', ');
    };
    const initialize = (card) => {
        let controller, timer, serial = 0;
        let previousKind = kind(card), newDraft;
        const search = card.querySelector('[data-guardian-search]');
        const select = card.querySelector('select[data-guardian-id]');
        const load = async () => {
            controller?.abort(); controller = new AbortController(); const request = ++serial;
            const url = new URL(editor.dataset.optionsUrl, location.href); url.searchParams.set('q', search.value.trim());
            const status = card.querySelector('[data-guardian-search-status]');
            const retry = card.querySelector('[data-guardian-search-retry]');
            status.textContent = 'Finding guardians…'; retry.hidden = true; select.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch(url, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store', signal:controller.signal});
                if (!response.ok || response.redirected) throw new Error('Search unavailable');
                const data = await response.json(); if (request !== serial) return;
                const selected = select.selectedOptions[0]?.cloneNode(true); const value = select.value;
                select.replaceChildren(new Option('Choose a guardian', ''));
                if (value && selected && !data.guardians.some((row) => String(row.id) === value)) select.add(selected);
                data.guardians.forEach((row) => { options.set(String(row.id), row); select.add(new Option(`${row.name} · ${row.phone}`, String(row.id))); });
                select.value = value;
                status.textContent = data.has_more ? 'First 20 matches shown. Refine the name or phone to find more.' : data.guardians.length ? 'Choose a contact below. Your current selection is kept until you change it.' : 'No matches. Try another search or choose New contact.' + (value ? ' Your selected contact is unchanged.' : '');
                refresh();
            } catch (error) {
                if (error.name !== 'AbortError' && request === serial) { status.textContent = 'Search unavailable. Your chosen contact is unchanged.'; retry.hidden = false; }
            } finally { if (request === serial) select.removeAttribute('aria-busy'); }
        };
        if (search) {
            search.addEventListener('input', () => { clearTimeout(timer); controller?.abort(); ++serial; timer = setTimeout(load,300); });
            search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); clearTimeout(timer); load(); } });
            card.querySelector('[data-guardian-search-retry]').addEventListener('click', load);
            select.addEventListener('change', () => {
                const option = options.get(select.value);
                card.querySelector('[data-guardian-revision]').value = option?.revision || '';
                card.querySelector('[data-guardian-edit-contact-toggle]').checked = false;
                card.querySelectorAll('[data-contact-field]').forEach((input) => { input.value = option?.details?.[input.dataset.contactField] || ''; });
                card.querySelector('[data-guardian-same-address]').checked = false;
                refresh();
            });
        }
        card.querySelectorAll('[data-guardian-kind]').forEach((input) => input.addEventListener('change', () => {
            clearTimeout(timer); controller?.abort(); ++serial;
            const next = kind(card);
            if (previousKind === 'new' && next === 'existing') {
                newDraft = Object.fromEntries([...card.querySelectorAll('[data-contact-field]')].map((field) => [field.dataset.contactField, field.value]));
                newDraft.same_address = card.querySelector('[data-guardian-same-address]').checked;
            }
            const values = next === 'new' ? (newDraft || {}) : (contact(card)?.details || {});
            card.querySelectorAll('[data-contact-field]').forEach((field) => { field.value = values[field.dataset.contactField] || ''; });
            card.querySelector('[data-guardian-same-address]').checked = values.same_address ?? (next === 'new');
            card.querySelector('[data-guardian-edit-contact-toggle]').checked = false;
            previousKind = next; refresh();
            if (next === 'existing' && search) load();
        }));
        card.addEventListener('input', refresh);
        card.addEventListener('change', refresh);
        card.querySelector('summary').addEventListener('click', (event) => { if (card.classList.contains('is-removed')) event.preventDefault(); });
        card.querySelector('[data-guardian-remove]').addEventListener('click', () => {
            clearTimeout(timer); controller?.abort(); ++serial;
            select?.removeAttribute('aria-busy');
            const wasPrimary = card.querySelector('[data-guardian-primary]').checked;
            card.querySelector('[data-guardian-primary]').checked = false; card.classList.add('is-removed'); refresh();
            notice.textContent = wasPrimary && active().length ? 'Another guardian is now selected as primary. Review the primary contact before saving.' : 'Guardian removed from this draft. Use Undo to restore.';
            card.querySelector('[data-guardian-undo]').focus();
        });
        card.querySelector('[data-guardian-undo]').addEventListener('click', () => {
            const restoringOriginal = card.dataset.savedId && originalLimit > 3 && active().length < originalLimit && active().every((other) => other.dataset.savedId);
            if (active().length >= 3 && !restoringOriginal) { notice.textContent = 'Remove another contact first. A student can have up to 3 guardians.'; return; }
            card.classList.remove('is-removed'); refresh(); card.open = true; notice.textContent = 'Guardian restored to this draft.';
        });
        card.querySelector('[data-guardian-done]').addEventListener('click', () => {
            const invalid = [...card.querySelectorAll('input,select,textarea')].find((input) => input.willValidate && !input.checkValidity());
            if (invalid) { invalid.reportValidity(); return; }
            card.open = false; card.querySelector('summary').focus();
        });
    };
    fields.disabled = false;
    cards().forEach(initialize);
    editor.querySelector('[data-guardian-add]').addEventListener('click', () => {
        if (active().length >= 3) return;
        const fragment = template.content.cloneNode(true); const key = String(nextKey++);
        fragment.querySelectorAll('*').forEach((node) => {
            for (const attribute of [...node.attributes]) if (attribute.value.includes('__KEY__')) node.setAttribute(attribute.name, attribute.value.replaceAll('__KEY__', key));
        });
        const card = fragment.querySelector('[data-guardian-card]'); container.append(fragment); initialize(card); refresh(); card.open = true;
        card.querySelector('[data-contact-field="first_name"]').focus(); notice.textContent = '';
    });
    refresh();
});
