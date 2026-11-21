---
name: add-json-endpoint
description: Add or change an endpoint of the booking calendar's JSON API following its conventions (request DTO with MapRequestPayload or MapQueryString, shared ContactConstraints, presenter, problem+json errors, session-only access, ApiTestCase tests, README API table). Use when the calendar or a page needs new server data or a new action.
---

# Adding a JSON endpoint

Read `src/Controller/Api/ReservationController.php` and `tests/Api/ReservationApiTest.php` first;
they show every piece.

1. **Route.** A method on a controller under `src/Controller/Api/` (extends `AbstractController`,
   `final`, class-level `#[Route('/api/...', name: 'api_..._')]`). Its docblock says what it replaces
   or why it exists, like the existing ones.
2. **Input.** A `final readonly` DTO in `src/Api/`:
   - bodies: `#[MapRequestPayload]` (invalid → 422 problem+json with violations);
   - query strings: `#[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]` (→ 400).
   Reuse `ContactConstraints` for names, phones and emails, and `LocalDate` for days, so every surface
   validates the same way. Reject impossible dates and times instead of normalising them.
3. **Behaviour** lives in `src/Booking/` (`ReservationBooker`, `CustomerResolver`), never in the
   controller. Respect the domain rules in `CLAUDE.md`: half-open ranges, merge vs replace, no deletes of customers.
4. **Output** through a presenter (`ReservationPresenter`, `CustomerPresenter`). Paged lists carry
   their own context, as `ReservationPage` does: `{events, page, perPage, total, hasMore}`.
5. **Errors** are problem+json rendered by Symfony: an entity argument (`Reservation $reservation`)
   that does not resolve is a 404, a failed DTO is a 422 (400 for query strings). No ad-hoc
   `{error: ...}` bodies; the front end has one error path (`assets/calendar/reservations-api.js`).
6. **Access.** Everything under `/api` needs a session; `SessionEntryPoint` already answers 401
   problem+json. Only sign-in, sign-up and verification are public in `config/packages/security.yaml`.
7. **Schema** changes: `make console ARGS="make:migration"`, review the PostgreSQL DDL, `make migrate`,
   `make console ARGS="doctrine:schema:validate"`.
8. **Tests** in `tests/Api/` extending `ApiTestCase` (already signed in): success shape, each
   validation failure, 404, the boundary of any range, and a signed-out request answering 401.
   Run `make test` and `make test-postgres`.
9. **Client and docs.** Add the call to `assets/calendar/reservations-api.js` if the calendar uses it,
   and a row to the README's HTTP API table.
10. Run the `verify` skill.
