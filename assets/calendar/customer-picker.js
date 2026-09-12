import { ApiError } from './reservations-api.js';

/**
 * The customer lookup behind every booking form.
 *
 * It owns three things the forms would otherwise each get wrong:
 *
 *  1. **Debouncing.** The search endpoint is cheap but not free, and a request
 *     per keystroke turns typing a name into a burst of them.
 *  2. **Race ordering.** Replies can land out of order, so a slow search for
 *     "ad" must not overwrite the results for "ada" that arrived first. Each
 *     request carries a sequence number and stale replies are dropped.
 *  3. **The no-JavaScript answer.** The markup a form ships with is a plain
 *     datalist-backed input. Everything here only improves on it: if this module
 *     never runs, the operator types a name and the server matches the profile
 *     by email, phone or name.
 */
export class CustomerPicker {
    #api;
    #input;
    #hidden;
    #list;
    #status;
    #debounceMs;
    #sequence = 0;
    #timer = null;

    /**
     * @param {object}      options
     * @param {string}      options.searchUrl    the lookup endpoint
     * @param {HTMLInputElement}  options.input  where the operator types
     * @param {HTMLInputElement}  options.hidden holds the chosen profile id
     * @param {HTMLElement} options.list         the results container
     * @param {HTMLElement} options.status       one-line feedback under the input
     * @param {number}      [options.debounceMs]
     */
    constructor({ searchUrl, input, hidden, list, status, debounceMs = 200 }) {
        this.#api = new CustomerSearch(searchUrl);
        this.#input = input;
        this.#hidden = hidden;
        this.#list = list;
        this.#status = status;
        this.#debounceMs = debounceMs;

        this.#input.addEventListener('input', () => this.#onInput());
        this.#input.addEventListener('focus', () => void this.#search(this.#input.value));
        this.#input.addEventListener('keydown', (event) => this.#onKeydown(event));
        this.#list.addEventListener('click', (event) => this.#onPick(event));

        // A click anywhere else closes the list, without cancelling the
        // pending request: the operator may be moving to the next field.
        document.addEventListener('click', (event) => {
            if (!this.#list.contains(event.target) && event.target !== this.#input) {
                this.#close();
            }
        });
    }

    /**
     * Points the picker at an existing booking or profile.
     *
     * The calendar's dialogs are reused for every booking, so they have to be
     * told explicitly who they are editing; the day page's forms arrive
     * prefilled and only need syncStatus().
     */
    prefill({ id = null, name = '' } = {}) {
        this.#input.value = name;
        this.#hidden.value = id ?? '';
        this.syncStatus();
    }

    /**
     * Re-reads the form's fields after something else changed them - a reset,
     * or the browser restoring defaults - and closes the results list.
     */
    syncStatus() {
        const name = this.#input.value.trim();
        const chosen = this.#hidden.value !== '';

        this.#status.textContent = chosen && name !== '' ? 'Existing customer' : '';
        this.#input.setAttribute('aria-expanded', 'false');
        this.#close();
    }

    #onInput() {
        // Typing invalidates any chosen profile: the name no longer describes
        // the customer the hidden field names.
        this.#hidden.value = '';
        this.#status.textContent = '';

        window.clearTimeout(this.#timer);
        this.#timer = window.setTimeout(() => void this.#search(this.#input.value), this.#debounceMs);
    }

    #onKeydown(event) {
        const options = [...this.#list.querySelectorAll('button')];

        if (event.key === 'Escape') {
            this.#close();

            return;
        }

        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        event.preventDefault();

        if (options.length === 0) {
            return;
        }

        const index = options.indexOf(document.activeElement);
        const next = event.key === 'ArrowDown'
            ? (index + 1) % options.length
            : (index <= 0 ? options.length - 1 : index - 1);

        options[next].focus();
    }

    #onPick(event) {
        const option = event.target.closest('button[data-customer]');

        if (!option) {
            return;
        }

        event.preventDefault();

        this.#input.value = option.dataset.name;
        this.#hidden.value = option.dataset.customer;
        this.#status.textContent = option.dataset.contact
            ? `Existing customer · ${option.dataset.contact}`
            : 'Existing customer';

        // The form fills in whatever the profile knows, so honouring the pick
        // does not mean retyping the phone number.
        this.#input.dispatchEvent(new CustomEvent('customer:picked', {
            bubbles: true,
            detail: {
                id: option.dataset.customer,
                name: option.dataset.name,
                phone: option.dataset.phone ?? '',
                email: option.dataset.email ?? '',
            },
        }));

        this.#close();
    }

    async #search(term) {
        const sequence = ++this.#sequence;

        try {
            const { customers } = await this.#api.search(term);

            // A slower earlier reply must not replace a newer one.
            if (sequence !== this.#sequence) {
                return;
            }

            this.#render(customers, term);
        } catch {
            if (sequence !== this.#sequence) {
                return;
            }

            // The lookup is a convenience; a failure must not block the
            // booking, which the server can still resolve by name.
            this.#list.replaceChildren();
            this.#status.textContent = '';
            this.#close();
        }
    }

    #render(customers, term) {
        this.#list.replaceChildren();

        if (customers.length === 0) {
            this.#close();

            if (term.trim() !== '') {
                this.#status.textContent = 'New customer';
            }

            return;
        }

        for (const customer of customers) {
            const option = document.createElement('button');

            option.type = 'button';
            option.className = 'list-group-item list-group-item-action';
            option.dataset.customer = customer.id;
            option.dataset.name = customer.name;
            option.dataset.contact = customer.contact;
            option.dataset.phone = customer.phone ?? '';
            option.dataset.email = customer.email ?? '';
            option.innerHTML = '<span class="fw-semibold"></span><span class="text-body-secondary small d-block"></span>';
            option.querySelector('.fw-semibold').textContent = customer.name;
            option.querySelector('.small').textContent = customer.contact;

            this.#list.append(option);
        }

        this.#list.hidden = false;
        this.#input.setAttribute('aria-expanded', 'true');
        this.#status.textContent = '';
    }

    #close() {
        this.#list.hidden = true;
        this.#input.setAttribute('aria-expanded', 'false');
    }
}

class CustomerSearch {
    #url;

    constructor(url) {
        this.#url = url;
    }

    async search(term) {
        const url = new URL(this.#url, window.location.origin);
        url.searchParams.set('q', term);

        const response = await fetch(url, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            throw new ApiError('The customer list could not be loaded.', response.status);
        }

        return response.json();
    }
}
