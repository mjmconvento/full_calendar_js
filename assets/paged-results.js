/**
 * Paged result cards that swap in place, and a search box that filters as the
 * operator types.
 *
 * The server already renders the card whole (a Twig partial), and it renders
 * the same partial on its own when asked for a fragment. This module only asks
 * for it and replaces the card, so the markup keeps exactly one source and the
 * page keeps working without any of this: the search form submits, and the
 * pager links navigate.
 *
 * A fetch that fails, or is answered with a sign-in page because the session
 * expired, hands the job back to the browser with a full navigation rather
 * than swapping HTML that does not belong in the card.
 */

const FRAGMENT_HEADERS = { 'X-Requested-With': 'XMLHttpRequest' };

/**
 * @param {HTMLElement} region      The element whose content is replaced.
 * @param {object}      [options]
 * @param {HTMLInputElement|null} [options.searchInput] The filter box, if the
 *        page has one. Typing filters after a short pause; Enter and the
 *        form's button filter at once.
 * @param {HTMLElement|null} [options.clearLink] Link that clears the filter.
 * @param {number}      [options.debounceMs] Quiet time before a keystroke is
 *        sent, in milliseconds.
 *
 * @returns {void}
 */
export function bootPagedResults(region, { searchInput = null, clearLink = null, debounceMs = 250 } = {}) {
    let inFlight = null;
    let timer = null;

    /**
     * Replaces the card with the one the server renders for a URL.
     *
     * @param {string} url
     * @param {'push'|'replace'|'none'} mode What to do with the address bar.
     */
    async function load(url, mode = 'push') {
        // A newer request supersedes an older one; letting both land would let
        // a slow reply overwrite the results of a faster one.
        inFlight?.abort();

        const controller = new AbortController();
        inFlight = controller;
        region.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, { headers: FRAGMENT_HEADERS, signal: controller.signal });

            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }

            if (!response.ok) {
                window.location.assign(url);
                return;
            }

            const html = await response.text();

            if (controller.signal.aborted) {
                return;
            }

            region.innerHTML = html;

            if (mode === 'push') {
                window.history.pushState(null, '', url);
            } else if (mode === 'replace') {
                window.history.replaceState(null, '', url);
            }
        } catch (error) {
            if (!controller.signal.aborted) {
                window.location.assign(url);
            }
        } finally {
            if (inFlight === controller) {
                inFlight = null;
                region.removeAttribute('aria-busy');
            }
        }
    }

    // Page links only - the rows carry links of their own (a customer, a phone
    // number) and those must keep behaving like links.
    region.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a.page-link') : null;

        if (link === null || !region.contains(link) || link.closest('.disabled') !== null) {
            return;
        }

        event.preventDefault();
        load(link.href);
    });

    if (searchInput !== null && searchInput.form !== null) {
        const form = searchInput.form;

        const urlFor = (term) => {
            const url = new URL(form.action, window.location.origin);
            url.search = '';

            if (term !== '') {
                url.searchParams.set('q', term);
            }

            return url.href;
        };

        const filter = (mode) => {
            window.clearTimeout(timer);

            const url = urlFor(searchInput.value.trim());

            if (clearLink !== null) {
                clearLink.hidden = searchInput.value.trim() === '';
            }

            if (url !== window.location.href) {
                load(url, mode);
            }
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => filter('replace'), debounceMs);
        });

        // Enter, or the Search button: the form's own submit, answered in
        // place rather than by a page load.
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            filter('replace');
        });

        if (clearLink !== null) {
            clearLink.addEventListener('click', (event) => {
                event.preventDefault();
                searchInput.value = '';
                searchInput.focus();
                filter('replace');
            });
        }

        // Back and forward move between searches and pages, so the box has to
        // take its value back from the URL the browser restored.
        window.addEventListener('popstate', () => {
            const term = new URL(window.location.href).searchParams.get('q') ?? '';
            searchInput.value = term;

            if (clearLink !== null) {
                clearLink.hidden = term === '';
            }

            load(window.location.href, 'none');
        });
    } else {
        window.addEventListener('popstate', () => load(window.location.href, 'none'));
    }
}
