document.querySelectorAll('[data-student-list-filters]').forEach((form) => {
    const classSelect = form.querySelector('[name="class_id"]');
    const sectionSelect = form.querySelector('[name="section_id"]');

    const refreshSections = (selected = '') => {
        const sections = JSON.parse(classSelect.selectedOptions[0]?.dataset.sections || '[]');
        const label = !classSelect.value ? 'Choose a class first' : sections.length ? 'All sections' : 'No active sections';
        sectionSelect.replaceChildren(new Option(label, ''));
        sections.forEach(({ id, name }) => sectionSelect.add(new Option(name, String(id))));
        sectionSelect.disabled = !classSelect.value || !sections.length;
        sectionSelect.value = sections.some((section) => String(section.id) === selected) ? selected : '';
    };

    refreshSections(sectionSelect.value);
    // The target listener clears a previous section before the table reads the form's change event.
    classSelect.addEventListener('change', () => refreshSections());
});
