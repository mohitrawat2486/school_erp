document.querySelectorAll('[data-employee-photo]').forEach((section) => {
    const input = section.querySelector('input[type="file"]');
    const current = section.querySelector('[data-employee-photo-current]');
    const preview = section.querySelector('[data-employee-photo-preview]');
    let url;
    const reset = () => {
        preview.hidden = true;
        current.hidden = false;
        preview.removeAttribute('src');
        if (url) URL.revokeObjectURL(url);
        url = null;
    };
    input.addEventListener('change', () => {
        reset();
        const file = input.files[0];
        if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) return;
        url = URL.createObjectURL(file);
        preview.src = url;
        preview.hidden = false;
        current.hidden = true;
    });
    preview.addEventListener('error', reset);
    input.form.addEventListener('reset', reset);
    window.addEventListener('pagehide', reset, {once: true});
});
