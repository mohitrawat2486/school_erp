document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-teacher-photo]').forEach((section) => {
        const input = section.querySelector('input[type="file"]');
        const current = section.querySelector('[data-photo-current]');
        const preview = section.querySelector('[data-photo-preview]');
        let objectUrl = null;

        const reset = () => {
            preview.hidden = true;
            current.hidden = false;
            preview.removeAttribute('src');
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        };
        input.addEventListener('change', () => {
            reset();
            const file = input.files[0];
            if (!file || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) return;
            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            preview.hidden = false;
            current.hidden = true;
        });
        preview.addEventListener('error', reset);
        input.form.addEventListener('reset', reset);
        window.addEventListener('pagehide', () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        }, { once: true });
    });
});
