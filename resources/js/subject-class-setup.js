document.querySelectorAll('[data-subject-year]').forEach((form) => {
    form.querySelector('select').addEventListener('change', () => form.requestSubmit());
});
document.querySelectorAll('[data-subject-class-setup]').forEach((form) => {
    const boxes = [...form.querySelectorAll('input[type="checkbox"][name="class_ids[]"]')];
    const summary = form.querySelector('[data-subject-class-summary]');
    const review = form.querySelector('[data-subject-removal-review]');
    const confirmation = form.querySelector('[data-subject-removal-confirm]');
    const list = form.querySelector('[data-subject-removal-names]');
    let removalKey = null;
    const refresh = () => {
        const selected = boxes.filter((box) => box.checked);
        const added = selected.filter((box) => box.dataset.wasAssigned !== 'true');
        const removed = boxes.filter((box) => !box.checked && box.dataset.wasAssigned === 'true');
        summary.textContent = `${selected.length} classes selected · ${added.length} to add or restore · ${removed.length} to remove`;
        const key = removed.map((box) => box.value).sort().join(',');
        if (removalKey !== null && removalKey !== key) confirmation.checked = false;
        removalKey = key;
        review.hidden = removed.length === 0; confirmation.disabled = removed.length === 0; confirmation.required = removed.length > 0;
        if (!removed.length) confirmation.checked = false;
        list.replaceChildren(...removed.map((box) => { const item = document.createElement('li'); item.textContent = box.dataset.className; return item; }));
    };
    boxes.forEach((box) => box.addEventListener('change', refresh));
    const search = form.querySelector('[data-subject-class-search]');
    search.disabled = false;
    search.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase();
        const options = [...form.querySelectorAll('[data-subject-class-option]')];
        options.forEach((option) => option.hidden = !option.dataset.name.toLocaleLowerCase().includes(term));
        form.querySelector('[data-subject-class-empty]').hidden = options.some((option) => !option.hidden);
    });
    refresh();
});
