# Booking Calendar

A booking system around a month calendar: click a free day, book a customer (name and phone
required), edit or cancel bookings, read a day in full at `/day/{date}`, browse a customer
directory at `/customers`, and see a dashboard of what is on the books. Accounts must verify
their email before signing in. A 2016 three-file PHP + mysqli app rewritten as Symfony 8.1
(PHP 8.5) with Twig, a JSON API, Doctrine on PostgreSQL 18, and FullCalendar 7. `README.md` is
thorough (domain rules, API, implementation notes); read the relevant section before larger changes.

## Running it

```bash
make build && make up      # nginx :8080, php-fpm (dev stage, source bind-mounted), Postgres :15432, Mailpit :18025
make fixtures              # demo data + manager@booking-calendar.test / booking-calendar
make console ARGS="debug:router"
```

The php entrypoint waits for Postgres, warms the cache and runs migrations on every start.
Without Docker: PHP 8.5 with `pdo_sqlite` is enough for the test suite (`vendor/bin/phpunit`).

## No Node.js, by design

Front-end dependencies come from AssetMapper's importmap and are **committed** under
`assets/vendor/`. FullCalendar is the vendor's self-contained global bundle in
`assets/fullcalendar/` (its ES modules break under an importmap because of duplicated Preact).
Never introduce npm, a bundler or a CDN. Add a package with
`make console ARGS="importmap:require <pkg>"`; compile with `make assets`.

## Domain rules (enforced in `src/Booking/`, backed by the schema)

- A day takes any number of bookings: no cap, no slots, no 409.
- Editing changes the customer and details only; moving a day is cancel + rebook.
- Date ranges are half-open `[start, end)`, matching FullCalendar.
- A booking belongs to exactly one `Customer`; name and phone live on the customer.
- Matching (`CustomerResolver`): explicit `customerId` wins; else phone, then email, then name,
  case-insensitively. Phone/email matches may update the profile; a name-only match attaches but
  never overwrites contact details.
- A booking form **merges** into the profile (blank = not asked); the profile form **replaces** it.
- Cancelling keeps the customer; customers are never deleted (`ON DELETE RESTRICT`).
- Timestamps are UTC. Date decisions use the injected `ClockInterface` (templates: `ClockExtension`'s `today()`), so tests can pin the day.

## Architecture notes

- Pages: Twig controllers in `src/Controller/`; JSON API in `src/Controller/Api/` with request
  DTOs in `src/Api/` bound by `#[MapRequestPayload]` / `#[MapQueryString]`, presenters for output,
  RFC 9457 problem+json for every error, and 401 problem+json (not a redirect) for JSON clients
  without a session (`SessionEntryPoint`).
- Controllers extend `AbstractController` here; services and DTOs are `final readonly` with
  constructor promotion; `declare(strict_types=1)` everywhere.
- Email verification uses signed URLs (`UriSigner`, keyed by `APP_SECRET`), no token table; the
  "verify first" refusal sits in `checkPostAuth()` so it cannot enumerate accounts.
- Sessions live in the `sessions` table in production (`PdoSessionHandler`, advisory locks);
  `schema_filter` keeps Doctrine away from it.
- Front end: Twig + vanilla ES modules (`assets/calendar/*.js`, `assets/paged-results.js`), Bootstrap 5.3.
  Pagers and the day page work without JavaScript; scripts only enhance. Paged cards fetch the same
  Twig partial with `X-Requested-With` instead of building rows in JS.
- Styling goes through design tokens in `assets/styles/app.css`. The FullCalendar Classic theme
  obfuscates its class names, so target the `--fc-classic-*` variables, roles and `[data-date]`,
  never `.fc-*` selectors. Text colours meet WCAG AA; `ink-300` is decoration only.

## Tests and analysis

| Command | What |
| --- | --- |
| `make test` | PHPUnit on SQLite in the php container (schema built from the mapping) |
| `make test-postgres` | Same suite against the compose Postgres (`booking_calendar_test`) |
| `docker compose exec php vendor/bin/phpstan analyse` | PHPStan level 8 over `src/` and `tests/` |

Run both suites before shipping a query: Postgres sorts `NULL` first in `DESC` and orders names
by collation, which SQLite hides. **Both suites build the schema from the entity mapping**, so a
forgotten migration passes every test: check `doctrine:schema:validate` against the migrated dev
database after any entity change. Tests sign in through `UserRegistrar` (verified) by default;
signed-out tests say so explicitly.

## Production

One Render container (Dockerfile's last stage `render`: nginx + php-fpm under supervisord on
`$PORT`), Neon Postgres (direct endpoint, not `-pooler`), Brevo HTTPS API for mail. Migrations run
at boot. See the `deploy` skill.

## Claude Code setup in this repo

| Kind | Name | What it does |
| --- | --- | --- |
| Skill | `verify` | PHPUnit on SQLite and Postgres, PHPStan level 8, schema validation, Twig/container lint. Claude runs it before commits |
| Skill | `add-json-endpoint` | Adds an API endpoint with a validated DTO, presenter, problem+json errors, tests and README entry |
| Skill | `frontend-assets` | Adds or upgrades a front-end package or FullCalendar without Node, and styles within the token system |
| Skill | `deploy` | Manual only (`/deploy`): ships to Render with Neon and Brevo |
| Agent | `booking-domain-reviewer` | Reviews changes against the booking and customer-matching rules |
| Agent | `frontend-ui-reviewer` | Reviews templates, CSS and JS for progressive enhancement, accessibility and FullCalendar pitfalls |
| Agent | `dependency-upgrader` | Triages Dependabot branches and reports what is safe to merge |
| Hook | `SessionStart` | Reports the branch, Dependabot branches and which test commands can run |
| Hook | `PreToolUse` | Blocks npm/npx, `doctrine:schema:update`, edits to vendored or generated assets, lockfiles and committed migrations; asks before wiping data |
| Hook | `PostToolUse` | `php -l` / `lint:twig` on the edited file; flags migration, Postgres-dialect, matching-rule, API and styling follow-ups |

Hooks are Python 3 scripts in `.claude/hooks/` (standard library only).
