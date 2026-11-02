document.querySelectorAll('[data-student-admission]').forEach((form) => {
    form.addEventListener('invalid', (event) => {
        let details = event.target.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement.closest('details');
        }
    }, true);
    const photo = form.querySelector('[data-admission-photo]');
    if (photo) {
        const input = photo.querySelector('input[type="file"]');
        const preview = photo.querySelector('[data-student-photo-preview]');
        const current = photo.querySelector('[data-student-photo-current]');
        let url;
        const reset = () => {
            preview.hidden = true; current.hidden = false;
            preview.removeAttribute('src');
            if (url) URL.revokeObjectURL(url);
            url = null;
        };
        input.addEventListener('change', () => {
            reset();
            const file = input.files[0];
            if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) return;
            url = URL.createObjectURL(file); preview.src = url; preview.hidden = false; current.hidden = true;
        });
        preview.addEventListener('error', reset);
        window.addEventListener('pagehide', () => { if (url) URL.revokeObjectURL(url); }, { once: true });
    }
});
