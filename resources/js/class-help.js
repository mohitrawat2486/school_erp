const classHelp = document.querySelector('[data-class-help]');
if (classHelp) {
    const trigger = document.querySelector('[data-class-help-open]');
    const dialog = classHelp.querySelector('[data-class-help-dialog]');
    dialog.querySelector('[data-class-help-slot]').append(classHelp.querySelector('[data-class-help-content]'));
    classHelp.querySelector('[data-class-help-fallback]').hidden = true;
    trigger.hidden = false;
    trigger.addEventListener('click', () => {
        dialog.showModal();
        document.documentElement.classList.add('class-help-open');
        dialog.querySelector('h2').focus();
    });
    classHelp.querySelectorAll('[data-class-help-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    const buttons = [...dialog.querySelectorAll('button')];
    dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const first = buttons[0], last = buttons.at(-1);
        if ((!event.shiftKey && document.activeElement === last) || (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.querySelector('h2')))) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        }
    });
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
        document.documentElement.classList.remove('class-help-open');
        trigger.focus();
    });
}
