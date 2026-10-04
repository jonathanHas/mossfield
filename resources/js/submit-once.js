// Forms submit once. A double-clicked Record payment used to post twice and
// record the money twice (E2E finding F-39); the same was true of every form
// without a confirm dialog. After a submit actually goes through, the form's
// submit buttons are disabled until the page is left.
//
// Cooperation with confirm.js: confirm.js stops a data-confirm submit in the
// capture phase (preventDefault + stopImmediatePropagation) and re-submits via
// requestSubmit(submitter) once confirmed. This listener runs in the bubble
// phase and ignores prevented submits, so it only ever sees the submit that
// really leaves — never the one parked behind the dialog.
//
// GET forms are left alone: they're idempotent (filters, search, the CSV
// export), and some trigger a download that never leaves the page.

const REENABLE_AFTER_MS = 10000;

function submitButtonsOf(form) {
    const own = [...form.elements].filter((el) => el.matches('button[type=submit], button:not([type]), input[type=submit]'));
    const external = form.id
        ? [...document.querySelectorAll(`button[form="${CSS.escape(form.id)}"], input[type=submit][form="${CSS.escape(form.id)}"]`)]
        : [];

    return [...new Set([...own, ...external])];
}

function release(form) {
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');
    submitButtonsOf(form).forEach((button) => {
        // Only undo what we did; Alpine-owned :disabled stays Alpine's.
        if (button.dataset.submitOnceDisabled) {
            delete button.dataset.submitOnceDisabled;
            button.disabled = false;
        }
    });
}

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented) {
        return;
    }
    if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
        return;
    }
    if (form.dataset.submitting) {
        // A second submit of a form already on its way (Enter pressed again,
        // a script calling requestSubmit twice): drop it.
        e.preventDefault();
        return;
    }

    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');

    // After this tick, so the submitter's name/value is already in the
    // form data the browser is sending.
    setTimeout(() => {
        submitButtonsOf(form).forEach((button) => {
            if (!button.disabled) {
                button.dataset.submitOnceDisabled = '1';
                button.disabled = true;
            }
        });
    }, 0);

    // A form that downloads a file never navigates away; give it back.
    setTimeout(() => release(form), REENABLE_AFTER_MS);
});

// Back/forward cache restores the page as it was left — disabled. Undo that.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach(release);
});
