/*
 * Blade pages of the staff area (e.g. /staff/team).
 */

/** Ask before destructive actions: <form data-confirm="Are you sure?"> */
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

/** Prevent double submits. */
document.querySelectorAll('form[method="POST"], form[method="post"]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) {
            return;
        }
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
    });
});
