import { Modal } from 'bootstrap';

import { CustomerPicker } from './customer-picker.js';

/**
 * Progressive enhancements for the day page.
 *
 * Both of the behaviours here are optional by design: the page's forms post and
 * redirect on their own, and the customer search is only a convenience on top
 * of the server matching a typed name to an existing profile. If this module
 * never runs, the day page still books, edits and cancels bookings.
 */
export function bootDayPage() {
    bootCancellationDialogs();
    bootCustomerPickers();
}

/**
 * Confirms a destructive form before it is posted.
 *
 * Any form carrying `data-confirm="<name>"` opts in. Intercepting the submit is
 * what stops a mis-click from deleting a booking, and it is the opposite
 * trade-off to the calendar's dialogs, where the dialog *is* the feature.
 */
function bootCancellationDialogs() {
    const dialog = document.getElementById('day-confirm');

    if (!dialog) {
        return;
    }

    const modal = Modal.getOrCreateInstance(dialog);
    const name = dialog.querySelector('[data-confirm-name]');
    const proceed = dialog.querySelector('[data-confirm-proceed]');

    /** The form awaiting confirmation. */
    let pending = null;

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
            return;
        }

        event.preventDefault();
        pending = form;
        name.textContent = form.dataset.confirm;
        modal.show();
    });

    proceed.addEventListener('click', () => {
        const form = pending;
        pending = null;
        modal.hide();

        // form.submit() posts without re-entering the submit listener above,
        // so the dialog cannot ask twice.
        form?.submit();
    });
}

/**
 * Wires the customer lookup into every booking form on the page.
 *
 * A day holds one "book this day" form plus one inline edit form per booking,
 * so this runs over all of them rather than being written for a single form.
 */
function bootCustomerPickers() {
    for (const input of document.querySelectorAll('input[data-customer-picker]')) {
        const form = input.closest('form');
        const hidden = form?.querySelector('input[data-customer-id]');
        const list = form?.querySelector('[data-customer-results]');
        const status = form?.querySelector('[data-customer-status]');

        if (!form || !hidden || !list || !status) {
            continue;
        }

        const picker = new CustomerPicker({
            searchUrl: input.dataset.customerPicker,
            input,
            hidden,
            list,
            status,
        });

        // Choosing someone fills in the contact details the profile already
        // knows, so the operator does not retype what the calendar has.
        form.addEventListener('customer:picked', (event) => {
            form.querySelector('input[name="customerPhone"]').value = event.detail.phone;
            form.querySelector('input[name="customerEmail"]').value = event.detail.email;
        });

        // A reset restores the fields' HTML defaults, which happens after this
        // listener runs - hence the tick, so the picker reads what the browser
        // actually put back.
        form.addEventListener('reset', () => window.setTimeout(() => picker.syncStatus(), 0));
    }
}
