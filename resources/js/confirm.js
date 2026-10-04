// Shared in-app confirmation for destructive / irreversible actions, replacing
// the browser's native confirm() (which automated browser sessions can't
// drive). Markup: resources/views/layouts/partials/confirm-dialog.blade.php,
// included once in each layout.
//
//   <form data-confirm="Message">                    confirm on any submit
//   <button type="submit" data-confirm="Message">    confirm on this button
//   <a href="…" data-confirm="Message">              confirm before navigating
//
// Optional: data-confirm-title, data-confirm-label (OK button text),
// data-confirm-cancel-label (Cancel button text, default "Cancel"),
// data-confirm-danger (red OK button). Each is reset on every open.
//
// Confirmed submits go through form.requestSubmit(submitter) so the clicked
// button's name/value is still posted and submit listeners still run.

const MODAL = 'confirm-action';
let pending = null;

function flag(form) {
    form.__confirmed = true;
    // requestSubmit() fires no submit event when constraint validation
    // refuses it — don't let the flag outlive that and skip the next dialog.
    setTimeout(() => { form.__confirmed = false; }, 0);
}

// Returns true when the event must be stopped (dialog opened, or the
// fallback was declined); false lets it proceed.
function open(el, action) {
    const title = document.getElementById('confirm-action-title');
    const message = document.getElementById('confirm-action-message');
    const ok = document.getElementById('confirm-action-ok');
    const cancel = document.getElementById('confirm-action-cancel');
    if (!title || !message || !ok) {
        // A page outside both layouts has no dialog markup: never let a
        // destructive action through unasked — fall back to the browser.
        return !window.confirm(el.dataset.confirm || 'Are you sure?');
    }

    title.textContent = el.dataset.confirmTitle || 'Are you sure?';
    message.textContent = el.dataset.confirm || '';
    ok.textContent = el.dataset.confirmLabel || 'Confirm';
    if (cancel) {
        cancel.textContent = el.dataset.confirmCancelLabel || 'Cancel';
    }
    const danger = el.hasAttribute('data-confirm-danger');
    ok.classList.toggle('mf-btn-danger', danger);
    ok.classList.toggle('mf-btn-primary', !danger);

    pending = action;
    window.dispatchEvent(new CustomEvent('open-modal', { detail: MODAL }));
    // Focus Cancel so a stray Enter doesn't confirm.
    setTimeout(() => document.getElementById('confirm-action-cancel')?.focus(), 50);

    return true;
}

document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[data-confirm]')) {
        return;
    }
    if (form.__confirmed) {
        form.__confirmed = false;
        return;
    }

    const submitter = e.submitter;
    const opened = open(form, () => {
        flag(form);
        form.requestSubmit(submitter || undefined);
    });
    if (opened) {
        e.preventDefault();
        e.stopImmediatePropagation();
    }
}, true);

document.addEventListener('click', (e) => {
    const el = e.target instanceof Element
        ? e.target.closest('button[data-confirm], a[data-confirm]')
        : null;
    if (!el) {
        return;
    }

    if (el.tagName === 'A') {
        if (open(el, () => { window.location.href = el.href; })) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
        return;
    }

    const form = el.form;
    if (!form || form.__confirmed) {
        return;
    }
    if (open(el, () => {
        flag(form);
        form.requestSubmit(el);
    })) {
        e.preventDefault();
        e.stopImmediatePropagation();
    }
}, true);

document.addEventListener('click', (e) => {
    if (!(e.target instanceof Element) || !e.target.closest('#confirm-action-ok')) {
        return;
    }
    window.dispatchEvent(new CustomEvent('close-modal', { detail: MODAL }));
    const action = pending;
    pending = null;
    if (action) {
        action();
    }
});
