/**
 * Thin client for the JSON API exposed by src/Controller/Api/ReservationController.php.
 *
 * Every failure surfaces as an ApiError carrying the server's own message, so
 * the UI never has to invent wording (the legacy code showed a generic
 * "There was an error while posting data." for everything).
 *
 * A signed-out request answers 401 with a problem+json body rather than
 * redirecting to the sign-in page, so an expired session reads as one clear
 * sentence instead of HTML arriving where JSON was expected.
 *
 * There is no availability call: a day accepts as many bookings as it is given,
 * so there is nothing left to ask about before offering to book one.
 */

export class ApiError extends Error {
    constructor(message, status, options = {}) {
        super(message, options);
        this.name = 'ApiError';
        this.status = status;
    }

    get isUnauthorized() {
        return this.status === 401;
    }
}

export class ReservationsApi {
    #collectionUrl;
    #perPage;

    constructor({ collectionUrl, perPage }) {
        this.#collectionUrl = collectionUrl;
        this.#perPage = perPage;
    }

    /**
     * One page of reservations.
     *
     * @param {object}  query
     * @param {string} [query.start] inclusive YYYY-MM-DD
     * @param {string} [query.end]   exclusive YYYY-MM-DD
     * @param {number} [query.page]  1-based
     *
     * @returns {Promise<{events: Array<object>, page: number, perPage: number, total: number, hasMore: boolean}>}
     */
    async list({ start, end, page = 1 } = {}) {
        const url = new URL(this.#collectionUrl, window.location.origin);
        url.searchParams.set('page', String(page));
        url.searchParams.set('perPage', String(this.#perPage));

        if (start) {
            url.searchParams.set('start', start);
        }

        if (end) {
            url.searchParams.set('end', end);
        }

        return request(url);
    }

    async create({ date, customerName, customerPhone, customerEmail, startsAt, details }) {
        return request(this.#collectionUrl, {
            method: 'POST',
            body: { date, customerName, customerPhone, customerEmail, startsAt, details },
        });
    }

    async revise(id, { customerName, customerPhone, customerEmail, startsAt, details }) {
        return request(this.#reservationUrl(id), {
            method: 'PATCH',
            body: { customerName, customerPhone, customerEmail, startsAt, details },
        });
    }

    async cancel(id) {
        return request(this.#reservationUrl(id), { method: 'DELETE' });
    }

    #reservationUrl(id) {
        return `${this.#collectionUrl}/${encodeURIComponent(id)}`;
    }
}

async function request(url, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response;

    try {
        response = await fetch(url, {
            method,
            headers,
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch (cause) {
        throw new ApiError('The server could not be reached. Check your connection and try again.', 0, { cause });
    }

    if (response.status === 204) {
        return null;
    }

    const payload = await readJson(response);

    if (!response.ok) {
        throw new ApiError(describeFailure(payload, response), response.status);
    }

    return payload;
}

async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * Symfony renders errors as RFC 9457 problem details. Validation failures carry
 * a `violations` list; domain conflicts carry a `detail`.
 */
function describeFailure(payload, response) {
    const violations = payload?.violations;

    if (Array.isArray(violations) && violations.length > 0) {
        return violations.map((violation) => violation.title ?? violation.message).filter(Boolean).join(' ');
    }

    return payload?.detail || payload?.title || `Request failed with status ${response.status}.`;
}
