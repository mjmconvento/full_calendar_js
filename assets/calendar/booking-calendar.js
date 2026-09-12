import { Modal } from 'bootstrap';

import { CustomerPicker } from './customer-picker.js';
import { Notifier } from './notifications.js';
import { ApiError, ReservationsApi } from './reservations-api.js';

const dayFormatter = new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
});

/** FullCalendar hands out ISO strings with an offset; the API speaks dates. */
const asDate = (isoString) => isoString.slice(0, 10);
const formatDay = (date) => dayFormatter.format(new Date(`${date}T00:00:00`));

/**
 * How far either side of the month on screen the calendar preloads, in days.
 * Two months each way is enough that paging to the next month rarely waits on
 * the network, without keeping a year of bookings in the browser.
 */
const PRELOAD_DAYS = 60;

/**
 * The month on screen decides its own page count, so this only bounds the
 * pathological case of one month holding more bookings than a screen can show.
 * Hitting it is reported rather than silently ignored.
 */
const MAX_WINDOW_PAGES = 20;

/**
 * Once the month is drawn, the preload around it arrives in the background -
 * but not without limit. These caps stop "open the calendar" from ever becoming
 * an unbounded download; anything past them is one click away on a day view.
 */
const MAX_BACKGROUND_PAGES = 40;
const MAX_EVENT_BYTES = 1_500_000;

const encoder = new TextEncoder();
const sizeOf = (events) => encoder.encode(JSON.stringify(events)).length;

/** Hands the thread back so background loading never blocks a paint. */
const yieldToBrowser = () =>
    new Promise((resolve) => {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(() => resolve(), { timeout: 1000 });
        } else {
            window.setTimeout(resolve, 0);
        }
    });

const shiftDays = (date, days) => {
    const shifted = new Date(`${date}T00:00:00`);
    shifted.setDate(shifted.getDate() + days);

    const month = String(shifted.getMonth() + 1).padStart(2, '0');
    const day = String(shifted.getDate()).padStart(2, '0');

    return `${shifted.getFullYear()}-${month}-${day}`;
};

/** A `Date` from FullCalendar's day click, as the API writes dates. */
const dateToIso = (date) => {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
};

/**
 * Wires the calendar and its three dialogs to the reservation API.
 * Replaces the jQuery/FullCalendar 2 script that used to live inline in
 * index.php.
 *
 * Bookings arrive one page at a time. The month on screen is fetched to
 * completion before the calendar draws (a month is small, and a half-drawn
 * month is worse than a slightly later one); everything around it follows in
 * the background, drawn as it lands. Nothing is ever requested without a bound
 * on how much can arrive.
 *
 * FullCalendar 7 and its Classic theme are loaded as global bundles by the
 * calendar template (see templates/calendar/index.html.twig), which registers
 * every view plugin up front - hence no `plugins` option below. That global is
 * read here rather than at module scope on purpose: app.js is one module graph
 * shared by every page, so destructuring `window.FullCalendar` at the top would
 * throw on the day, dashboard and sign-in pages, where those bundles are
 * deliberately absent, and take their scripts down with it.
 */
export function bootBookingCalendar(mount) {
    const notifier = new Notifier(document.getElementById('notice'));
    const Calendar = window.FullCalendar?.Calendar;

    if (!Calendar) {
        notifier.error('The calendar could not be loaded. Reload the page and try again.');

        return;
    }

    const api = new ReservationsApi({
        collectionUrl: mount.dataset.reservationsUrl,
        perPage: Number(mount.dataset.bookingsPerPage) || 10,
    });

    /**
     * Where a day page lives, given a YYYY-MM-DD date. The template hands over
     * its own URL for one placeholder day - the route's requirement only
     * accepts real dates, so one is used to generate it - and the date is
     * always the last path segment.
     */
    const dayUrl = (date) => mount.dataset.dayUrl.replace(/[^/]+$/, date);

    const createDialog = document.getElementById('create-modal');
    const editDialog = document.getElementById('edit-modal');
    const confirmDialog = document.getElementById('confirm-modal');

    const createModal = Modal.getOrCreateInstance(createDialog);
    const editModal = Modal.getOrCreateInstance(editDialog);
    const confirmModal = Modal.getOrCreateInstance(confirmDialog);

    const createForm = document.getElementById('create-form');
    const editForm = document.getElementById('edit-form');

    // The dialogs post to the JSON API, but the lookup behind the name field is
    // the same one the day page uses.
    const pickers = {
        create: pickerFor(createForm),
        edit: pickerFor(editForm),
    };

    /** Day currently being booked, and reservation currently being edited. */
    let bookingDate = null;
    let editing = null;

    /**
     * Days the calendar knows to hold at least one booking. A click on a day
     * that is in here - including one whose bookings have not been drawn yet -
     * opens the day rather than offering to book it, because a day with room
     * still has bookings worth reading.
     */
    const knownDays = new Set();

    /**
     * Incremented on every fetch. Preloading checks it before each request and
     * before each render, so paging through months cannot leave an old loader
     * appending events to a window nobody is looking at any more.
     */
    let loadToken = 0;

    /** Event sources added by preloading, dropped when a new fetch begins. */
    let preloadSources = [];

    const remember = (events) => {
        for (const event of events) {
            knownDays.add(asDate(`${event.start}`));
        }
    };

    const calendar = new Calendar(mount, {
        initialView: 'dayGridMonth',
        initialDate: mount.dataset.focusDate,
        // v7 renders no toolbar unless one is configured.
        headerToolbar: { start: 'title', end: 'prev,next today' },
        height: 'auto',
        displayEventTime: false,
        // Fill each cell with as many bookings as it can hold and fold the rest
        // into a "+N more" link. Growing the row instead is what made the
        // original month view unreadable.
        dayMaxEvents: true,
        // `navLinks` stays off: it makes FullCalendar wrap each day's whole cell
        // in an anchor, so nothing else in the cell can be clicked.
        //
        // `dateClick` is not used either. This build of FullCalendar is the
        // Classic theme, which strips the logical `fc-event` / `fc-more-link`
        // class names and leaves only obfuscated ones, so its internal
        // "is this a valid date click target" test cannot be reasoned about
        // from here - a click on a day cell silently did nothing. Instead the
        // day cell gets its own listener below, and the things it must not
        // swallow - events and the "+N more" link - are marked as they mount.
        dayCellDidMount: (info) => {
            info.el.addEventListener('click', (event) => {
                if (event.target.closest('[data-calendar-interactive]')) {
                    return;
                }

                void startBooking(dateToIso(info.date));
            });
        },
        eventDidMount: (info) => {
            // Marks the event element so the day cell's listener leaves it to
            // FullCalendar's own eventClick.
            info.el.dataset.calendarInteractive = 'event';
        },
        events: async (fetchInfo) => {
            const token = ++loadToken;
            const windowStart = asDate(fetchInfo.startStr);
            const windowEnd = asDate(fetchInfo.endStr);

            // Events added by the previous fetch belong to the previous window.
            clearPreloads();

            const budget = { bytes: 0, pages: 0 };
            const window = await collect(token, { start: windowStart, end: windowEnd }, budget, MAX_WINDOW_PAGES);

            if (window === null) {
                // Superseded by a newer fetch, or the request failed and has
                // already been reported.
                return [];
            }

            remember(window.events);

            if (window.truncated) {
                notifier.warning(
                    'This month holds more bookings than can be loaded at once. Open a day to see all of it.',
                );
            } else {
                void preload(token, [
                    // Ahead first: the next month is what an operator pages to.
                    { start: windowEnd, end: shiftDays(windowEnd, PRELOAD_DAYS) },
                    { start: shiftDays(windowStart, -PRELOAD_DAYS), end: windowStart },
                ], budget);
            }

            return window.events;
        },
        dateClick: (info) => {
            void startBooking(asDate(`${info.dateStr}`));
        },
        moreLinkDidMount: (info) => {
            // The "+N more" link is the one affordance that says "there is more
            // here than fits", so it opens the day rather than a popover.
            info.el.dataset.calendarInteractive = 'more';
            info.el.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                openDay(asDate(`${info.date}`));
            });
        },
        eventClick: (info) => {
            startEditing(info.event);
        },
    });

    calendar.render();

    /**
     * Reads every page of one span, up to `maxPages`.
     *
     * @returns {Promise<{events: object[], truncated: boolean, pages: number}|null>}
     *          null when a newer fetch superseded this one, or the request failed
     */
    async function collect(token, span, budget, maxPages) {
        const events = [];
        let page = 1;
        let hasMore = true;

        while (hasMore && page <= maxPages && budget.bytes < MAX_EVENT_BYTES) {
            let chunk;

            try {
                chunk = await api.list({ ...span, page });
            } catch (error) {
                notifier.error(messageFor(error, 'The reservations could not be loaded.'));

                return null;
            }

            if (token !== loadToken) {
                return null;
            }

            events.push(...chunk.events);
            budget.bytes += sizeOf(chunk.events);
            budget.pages += 1;
            hasMore = chunk.hasMore;
            page += 1;
        }

        return { events, truncated: hasMore, pages: page - 1 };
    }

    /**
     * Pulls the span around the month on screen in behind the finished render,
     * one page at a time, drawn as it arrives and stopped by the page and byte
     * ceilings above.
     */
    async function preload(token, spans, budget) {
        let pagesLeft = MAX_BACKGROUND_PAGES;

        for (const span of spans) {
            if (pagesLeft <= 0 || budget.bytes >= MAX_EVENT_BYTES) {
                return;
            }

            const collected = await collect(token, span, budget, pagesLeft);

            if (collected === null) {
                return;
            }

            pagesLeft -= collected.pages;
            remember(collected.events);

            if (collected.events.length > 0) {
                preloadSources.push(calendar.addEventSource(collected.events));
            }

            await yieldToBrowser();
        }
    }

    function clearPreloads() {
        for (const source of preloadSources) {
            source.remove();
        }

        preloadSources = [];
    }

    function startBooking(date) {
        // A day that already has bookings is worth reading before adding to,
        // even when they are not on screen, so it opens the day.
        if (knownDays.has(date)) {
            openDay(date);

            return;
        }

        bookingDate = date;
        resetForm(createForm);
        pickers.create.prefill();
        document.getElementById('create-day').textContent = formatDay(date);
        createModal.show();
    }

    function openDay(date) {
        window.location.assign(dayUrl(date));
    }

    function startEditing(event) {
        editing = { id: event.id, title: event.title };

        resetForm(editForm);
        document.getElementById('edit-day').textContent = formatDay(asDate(`${event.startStr}`));
        document.getElementById('edit-customer-name').value = event.title;
        document.getElementById('edit-customer-phone').value = event.extendedProps.phone ?? '';
        document.getElementById('edit-customer-email').value = event.extendedProps.email ?? '';
        document.getElementById('edit-starts-at').value = event.extendedProps.time ?? '';
        document.getElementById('edit-details').value = event.extendedProps.details ?? '';
        document.getElementById('edit-day-link').href = dayUrl(asDate(`${event.startStr}`));
        pickers.edit.prefill({ id: event.extendedProps.customerId, name: event.title });
        editModal.show();
    }

    createForm.addEventListener('submit', (submitEvent) => {
        submitEvent.preventDefault();

        if (!isValid(createForm)) {
            return;
        }

        void submit(createForm, async () => {
            await api.create({
                date: bookingDate,
                ...fieldsOf('create'),
            });

            createModal.hide();
            calendar.refetchEvents();
            notifier.success(`Reservation added for ${formatDay(bookingDate)}.`);
        }, 'The reservation could not be saved.');
    });

    editForm.addEventListener('submit', (submitEvent) => {
        submitEvent.preventDefault();

        if (!isValid(editForm)) {
            return;
        }

        void submit(editForm, async () => {
            await api.revise(editing.id, fieldsOf('edit'));

            editModal.hide();
            calendar.refetchEvents();
            notifier.success('Reservation updated.');
        }, 'The reservation could not be updated.');
    });

    document.getElementById('cancel-reservation').addEventListener('click', () => {
        document.getElementById('confirm-name').textContent = editing.title;

        // Chain the dialogs instead of stacking them: Bootstrap only manages one
        // backdrop at a time.
        editDialog.addEventListener('hidden.bs.modal', () => confirmModal.show(), { once: true });
        editModal.hide();
    });

    document.getElementById('confirm-cancel').addEventListener('click', (clickEvent) => {
        void submit(clickEvent.currentTarget, async () => {
            await api.cancel(editing.id);

            confirmModal.hide();
            calendar.refetchEvents();
            notifier.success(`Reservation for ${editing.title} cancelled.`);
        }, 'The reservation could not be cancelled.');
    });

    /**
     * The customer fields both dialogs carry, read from the dialog's own
     * inputs. Blank optional fields are sent as null, so the server stores
     * "not given" rather than an empty string.
     *
     * @param {'create'|'edit'} scope
     */
    function fieldsOf(scope) {
        const read = (id) => document.getElementById(`${scope}-${id}`).value.trim();
        // Found through the form rather than an element id: the picker owns this
        // field, and `data-customer-id` is the contract it sets up.
        const chosen = document.getElementById(`${scope}-form`).querySelector('input[data-customer-id]');

        return {
            customerName: read('customer-name'),
            customerPhone: read('customer-phone') || null,
            customerEmail: read('customer-email') || null,
            startsAt: read('starts-at') || null,
            details: read('details') || null,
            // Empty means "match the details above to a profile server-side".
            customerId: chosen.value === '' ? null : Number(chosen.value),
        };
    }

    /**
     * Wires the customer lookup of one dialog to that dialog's own fields.
     */
    function pickerFor(form) {
        const input = form.querySelector('input[data-customer-picker]');

        const picker = new CustomerPicker({
            searchUrl: input.dataset.customerPicker,
            input,
            hidden: form.querySelector('input[data-customer-id]'),
            list: form.querySelector('[data-customer-results]'),
            status: form.querySelector('[data-customer-status]'),
        });

        // Choosing someone fills in the contact details their profile already
        // holds, so the operator only confirms what the calendar knows.
        form.addEventListener('customer:picked', (event) => {
            form.querySelector('input[name="customerPhone"]').value = event.detail.phone;
            form.querySelector('input[name="customerEmail"]').value = event.detail.email;
        });

        return picker;
    }

    /**
     * Runs an API call with the triggering control disabled, so a double click
     * cannot send the request twice. The legacy UI had exactly that bug.
     */
    async function submit(scope, action, fallbackMessage) {
        const controls = scope.tagName === 'BUTTON' ? [scope] : [...scope.querySelectorAll('button')];
        controls.forEach((control) => (control.disabled = true));

        try {
            await action();
        } catch (error) {
            notifier.error(messageFor(error, fallbackMessage));
        } finally {
            controls.forEach((control) => (control.disabled = false));
        }
    }
}

function resetForm(form) {
    form.reset();
    form.classList.remove('was-validated');
}

function isValid(form) {
    form.classList.add('was-validated');

    return form.checkValidity();
}

function messageFor(error, fallbackMessage) {
    return error instanceof ApiError ? error.message : fallbackMessage;
}
