import { Toast } from 'bootstrap';

/**
 * Non-blocking feedback. The legacy UI raised a second modal on top of the
 * first one to report "date picked has already reached its reservation limits";
 * a toast keeps the calendar usable while saying the same thing.
 */
export class Notifier {
    #toast;
    #element;
    #body;

    constructor(element) {
        this.#element = element;
        this.#body = element.querySelector('.toast-body');
        this.#toast = Toast.getOrCreateInstance(element, { delay: 5000 });
    }

    success(message) {
        this.#show(message, 'text-bg-success');
    }

    warning(message) {
        this.#show(message, 'text-bg-warning');
    }

    error(message) {
        this.#show(message, 'text-bg-danger');
    }

    #show(message, variant) {
        this.#element.classList.remove('text-bg-success', 'text-bg-warning', 'text-bg-danger');
        this.#element.classList.add(variant);
        this.#body.textContent = message;
        this.#toast.show();
    }
}
