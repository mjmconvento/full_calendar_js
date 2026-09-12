# Booking Calendar

A small booking system built around a month calendar: click a free day, enter a customer's
name and phone number plus any optional details, and the reservation shows up on that day.
Click an existing reservation to edit it, or a day to read it in full. A day accepts as many
bookings as it is given - there is no per-day cap.

Around that calendar sit three screens the original never had:

- a **dashboard** with today's bookings, tomorrow, the next seven days, everything upcoming
  and an all-time count, today's bookings, and a paged list of everything on file, newest
  booking first;
- a **day view** at `/day/{date}`, showing one day in full - every booking with the
  customer's phone, email, time and notes, plus plain HTML forms to book, edit or cancel.
  The month grid stays a month grid instead of trying to be a detail view;
- a **customer directory** at `/customers`, because the person is a record rather than
  three columns repeated on every booking. Typing a name while booking searches it, and a
  returning customer is matched to their existing profile instead of creating a second one.
  The directory is ordered by each customer's closest booking to today - bookings still to
  come do not count towards that - and it filters as the operator types and pages in place.

Everything except signing in and signing up requires an account, and a new account has to
verify its email address - by following the link sent to it - before it can sign in. The
setup for that, Brevo included, is in [`ai_docs/email-verification.md`](ai_docs/email-verification.md).

This repository started life in 2016 as three PHP files (`index.php`, `process.php`,
`config.php`) talking to MySQL through `mysqli` with request data interpolated straight
into SQL, plus jQuery, Bootstrap 3 and FullCalendar 2.6 vendored with Bower. It has been
rewritten as a Symfony application with a Twig frontend and a JSON API, and containerised.
The behaviour of the original app is preserved; the way it is built is not.

---

## Stack

| Layer | Component | Version |
| --- | --- | --- |
| Runtime | PHP (`php:8.5.10-fpm-alpine3.24`) | 8.5.10 |
| Framework | Symfony (framework-bundle) | 8.1.6 |
| Templating | Twig | 3.28 |
| ORM | Doctrine ORM / DBAL | 3.7 / 4.4 |
| Migrations | doctrine/doctrine-migrations-bundle | 4.0 |
| Database | PostgreSQL (`postgres:18-alpine` locally, Neon in production) | 18 |
| Web server | nginx (`nginx:1.31-alpine`) | 1.31 |
| Auth | symfony/security-bundle (form login, password hashing) | 8.1 |
| Email | symfony/mailer + symfony/brevo-mailer (HTTPS API transport) | 8.1 |
| Assets | Symfony AssetMapper + importmap | 8.1 |
| Calendar UI | FullCalendar (Classic theme) | 7.1.0 |
| CSS/JS toolkit | Bootstrap | 5.3.8 |
| Tests | PHPUnit | 13.3 |
| Static analysis | PHPStan (level 8) | 2.2 |
| Dependencies | Composer | 2.8 |

Notes on two version choices:

- **PostgreSQL 18**, because production runs on Neon, which is Postgres-only, and running
  one engine everywhere is what lets the local stack and `make test-postgres` stand in for
  it. The app began life on MySQL and was moved on 2026-09-12; the MySQL migration chain is
  in git history. Nothing in the schema or the queries is specific to 18 - 16 or 17 work if
  you change the image and the `serverVersion` in `DATABASE_URL` together.
- **Symfony 8.1** is the newest release, not the LTS. Symfony 7.4 is the current LTS if you
  need a longer support window.

There is **no Node.js anywhere** in this project. Frontend dependencies are resolved by
AssetMapper's importmap and committed under `assets/vendor/`, so a build never depends on
npm or a CDN being reachable.

---

## Architecture

```
browser ──HTTP──> nginx (:8080)  ──FastCGI──> php-fpm (Symfony 8.1) ──PDO──> PostgreSQL 18
                  serves public/                renders Twig + JSON API
```

Three containers, one image, plus one outbound dependency:

| Service | Image / stage | Role |
| --- | --- | --- |
| `nginx` | `Dockerfile` stage `web` | Serves `public/` (digest-hashed assets, immutable caching) and proxies everything else to php-fpm |
| `php` | `Dockerfile` stage `prod` (or `dev`) | Symfony application: Twig page + JSON API |
| `database` | `postgres:18-alpine` | Customers, reservations, accounts and, in production, sessions |
| `mailer` | `axllent/mailpit` (dev overlay only) | Catches the verification email a sign-up sends; in production `MAILER_DSN` points at Brevo's HTTPS API instead |

The frontend is server-rendered Twig plus one ES module, so the "frontend" and "backend"
are one deployable Symfony app. The split that matters in operation is the one above: the
web tier (nginx, holding the compiled assets) is a separate container and image stage from
the application runtime (php-fpm), and the compiled `public/` is baked into the nginx image
so the two can never drift apart.

### Request flow for a booking

1. `GET /login` renders the sign-in form; every other page and the whole JSON API redirect
   there (or answer `401` problem+json to a client that asked for JSON) without a session.
2. `GET /` renders `templates/calendar/index.html.twig`; the calendar element carries its
   API URLs, the page size and the day it is focused on as `data-*` attributes.
3. `assets/calendar/booking-calendar.js` boots FullCalendar and asks
   `GET /api/reservations?start=…&end=…&page=…` for the visible month, one page at a time.
4. Clicking a free day opens the booking dialog. Clicking a day that already has bookings
   opens that day's page instead - there is nothing to ask the server about a free day any
   more, because no day can be full.
5. Typing in the dialog's customer field searches `GET /api/customers?q=…`; picking a
   result fills in the contact details already on file and sends its id with the booking.
6. Submitting posts to `POST /api/reservations`. The server — not the browser — decides
   which customer profile the booking belongs to.

---

## Domain rules

Everything below is enforced server-side in `src/Booking/ReservationBooker.php` and backed
by the schema.

- **A day accepts as many bookings as it is given.** There is no cap and no
  `RESERVATION_DAILY_LIMIT`. The 2016 app capped a day at two in JavaScript only, and this
  app once expressed that cap as a unique `(reserved_on, slot)` index - both are gone, and
  so is the `slot` column and the `409 Conflict` that reported a full day.
- **Editing changes the customer and the booking's details only.** Moving a booking to
  another day means cancelling it and booking the target day.
- **Date ranges are half-open** (`start` inclusive, `end` exclusive), matching
  FullCalendar. The legacy query used an inclusive `BETWEEN` and leaked one extra day.

Customer records, which did not exist before:

- **A booking belongs to exactly one customer**, and the name, phone and email live on that
  customer rather than on the booking. Two bookings for the same person are two rows
  pointing at one profile, so fixing a typo fixes it everywhere.
- **A name and a phone number are required; the email, the time and the notes are not.**
  The phone is the detail worth insisting on - it is how a booking is confirmed or moved
  when the customer is not standing in front of you.
- **A booking form merges into the profile; the profile page replaces it.** Taking a booking
  at a desk, a blank optional field means "not asked", not "delete what you have".
  Correcting a stored value is an edit to the profile, and the profile form is the whole
  record — there, an empty optional field clears.
- **Profiles are matched by strength of evidence, not on a guess.** A booking that names no
  customer is resolved against the phone number first, then the email address, then the name
  (all case-insensitively). A phone or email match lets the booking update the stored
  details; a name-only match attaches the booking but leaves the profile alone, because a
  name is shared by everyone who has it and is not evidence enough to overwrite a confirmed
  number. Picking someone from the search sends their id and skips the guessing entirely.
- **Cancelling a booking keeps the customer.** A cancelled visit is not a reason to forget
  someone.
- **No customer is ever deleted.** The foreign key is `ON DELETE RESTRICT`, so a profile in
  use cannot be removed by accident.

---

## HTTP API

All endpoints speak JSON. Errors are returned as
[RFC 9457 problem details](https://www.rfc-editor.org/rfc/rfc9457.html) — the same shape
Symfony produces for validation failures, so the frontend has one error path. There is no
`409` any more: it reported a day that had reached its cap, and days are no longer capped.
There is no availability endpoint either, for the same reason.

Every endpoint below requires a session. A request that asks for JSON and has none answers
`401` problem+json rather than redirecting, so the calendar's fetch client gets a sentence
instead of the sign-in page's HTML.

| Method | Path | Purpose | Success | Failure |
| --- | --- | --- | --- | --- |
| `GET` | `/api/reservations?start=&end=&page=&perPage=` | One page of reservations in `[start, end)`, as FullCalendar events | `200` | `400` malformed range or page |
| `POST` | `/api/reservations` | Book a day | `201` | `422` invalid body |
| `PATCH` | `/api/reservations/{id}` | Change the booking and its customer | `200` | `404` unknown · `422` invalid body |
| `DELETE` | `/api/reservations/{id}` | Cancel | `204` | `404` unknown |
| `GET` | `/api/customers?q=` | Customer lookup for the booking forms | `200` | `401` no session |

`start` and `end` are optional: without them the whole table is paged, oldest first. A page
carries its own context, so a client never has to guess whether more is coming:

```json
{"events": [ … ], "page": 1, "perPage": 10, "total": 34, "hasMore": true}
```

A search with an empty `q` returns the customers most recently dealt with, which is what the
picker shows before anything is typed. Results are capped at 20.

```bash
# Who do we already have called Ada?
curl -s 'http://localhost:8080/api/customers?q=ada'
# {"customers":[{"id":"3","name":"Ada Lovelace","phone":"+63 917 555 0134",
#   "email":"ada@example.test","contact":"+63 917 555 0134 · ada@example.test"}]}

# Book a day for a new customer. A name and a phone number are required.
curl -s -X POST http://localhost:8080/api/reservations \
  -H 'Content-Type: application/json' \
  -d '{"date":"2026-09-16","customerName":"Katherine Johnson",
#       "customerPhone":"+1 555 0100","details":"Corner table"}'
# {"id":"7","title":"Katherine Johnson","start":"2026-09-16","allDay":true,
#  "extendedProps":{"details":"Corner table","phone":"+1 555 0100","email":null,
#   "time":null,"customerId":"8"}}

# Book for someone the picker found: the id wins over the typed name.
curl -s -X POST http://localhost:8080/api/reservations \
  -H 'Content-Type: application/json' \
  -d '{"date":"2026-09-23","customerName":"Ada Lovelace","customerId":3,"startsAt":"19:00"}'
```

A name and a phone number are required; the email and the time are not. Their validation
rules are shared by every surface (see `src/Api/ContactConstraints.php`), and a time outside
the clock or an impossible date is rejected rather than quietly moved.

Each endpoint's legacy counterpart (`type=fetch`, `type=new`, `type=edit`, `type=remove` in
`process.php`) is named in the controller's docblocks. The fifth legacy type,
`type=check_limit`, has no replacement: it reported a day's remaining capacity, and there is
no longer any capacity to report.

---

## Running it with Docker

> These commands are yours to run — nothing in this repository starts containers by itself.

```bash
docker compose build          # or: make build
docker compose up -d          # or: make up
```

Then open <http://localhost:8080>. The dev overlay also starts [Mailpit](https://mailpit.axllent.org/)
at <http://localhost:18025>, which catches the verification email a sign-up sends; a
production-shaped stack sends nothing until `MAILER_DSN` points at a real transport.

The `php` entrypoint waits for PostgreSQL, warms the Symfony cache and runs
`doctrine:migrations:migrate` on every start, so the schema is ready without a manual step.
To get the demo data from `src/DataFixtures/` — customers, reservations and one account:

```bash
make fixtures                 # docker compose exec php php bin/console doctrine:fixtures:load
```

That fixture account is `manager@booking-calendar.test` with the password `booking-calendar`. It is
demo data with a published password, exactly like the demo bookings: it exists so a freshly
loaded database is usable, and it must not survive into a deployment anyone else can reach.
Register a real account and delete this one.

`compose.override.yaml` is loaded automatically and turns the stack into a dev environment:
the `php` container runs the `dev` image stage with the source bind-mounted, so code edits
need no rebuild. For the production shape, ignore the overlay explicitly:

```bash
docker compose -f compose.yaml up -d
```

Host ports are overridable (`.env` next to `compose.yaml`, or inline):

| Variable | Default | Notes |
| --- | --- | --- |
| `NGINX_PORT` | `8080` | Application |
| `POSTGRES_PORT` | `15432` | Deliberately not 5432, so this stack cannot collide with another local Postgres |
| `MAILPIT_PORT` | `18025` | Mailpit's web inbox (dev overlay only); not 8025 for the same reason |
| `APP_SECRET` | throwaway hex string | Generate a real one for anything public: `openssl rand -hex 32`. It also signs the email verification links |

`make` on its own lists every available target (`build`, `up`, `down`, `logs`, `sh`,
`console`, `composer`, `migrate`, `fixtures`, `assets`, `test`, `xdebug-on`, `db`, `clean`).

### Rebuilding assets

`assets/vendor/` (Bootstrap, via importmap) and `assets/fullcalendar/` (FullCalendar's
global bundles) are committed, so a build downloads nothing. After changing `importmap.php`
or the asset sources:

```bash
make assets                   # importmap:install + asset-map:compile
```

---

## Running it without Docker

Requires PHP ≥ 8.4 with `pdo_pgsql` (or `pdo_sqlite`), `intl` and `zip`, plus Composer.

```bash
composer install
cat > .env.local <<'EOF'
DATABASE_URL="sqlite:///%kernel.project_dir%/var/dev.db"   # or point at PostgreSQL
APP_SECRET=any-non-empty-string                             # signs the verification links
EOF
php bin/console doctrine:migrations:migrate -n     # PostgreSQL
# on SQLite, build the schema from the mapping instead:
php bin/console doctrine:schema:create -n
php bin/console doctrine:fixtures:load -n     # creates manager@booking-calendar.test / booking-calendar
php bin/console asset-map:compile
php -S 127.0.0.1:8000 -t public
```

With `MAILER_DSN=null://null` (the default in `.env`) no email is delivered, so after signing
up, print the link the email would have carried and open it at the same origin:

```bash
DEFAULT_URI=http://127.0.0.1:8000 php bin/console app:verification-link you@example.test
```

Note that `migrations/` contains PostgreSQL DDL, so SQLite runs need `doctrine:schema:create`.

---

## Tests and static analysis

```bash
vendor/bin/phpunit            # 72 tests — API contracts, paging, auth, verification, customers, the pages
vendor/bin/phpstan analyse    # level 8, src/ and tests/
make test                     # the same suite inside the php container
```

The suite builds its schema from the entity mapping into a throwaway SQLite file
(`.env.test`), so it needs neither PostgreSQL nor Docker. `make test-postgres` runs the same
suite inside the container against the compose `database` service (a `booking_calendar_test`
database created on first start), which is the dialect production runs on. Run both before
shipping a query: the move to Postgres found an ordering that every SQLite run passed and
Postgres sorted the other way round (see the implementation notes).

The tests cover the rules that are easy to get wrong and were wrong before: the exclusive
range boundary, the event payload shape, validation rejections, that `/` renders with its API
endpoints wired up, that paging neither repeats nor skips a booking, that the dashboard list
runs newest first, that the directory puts the customer booked closest to today first and
does not let a booking still to come count, that an anonymous visitor reaches nothing, and
that a repeat booking attaches to the customer already on file instead of creating a second
one. They also pin the customer-matching rules, including that a name-only match does not
overwrite a confirmed phone number.

Email verification is pinned end to end against Symfony's in-memory mail log: a sign-up sends
one link and signs nobody in, the right password is refused until the link is followed, the
link verifies and signs in exactly once, a link with a changed id or a passed expiry is
refused, and asking for another link sends at most one a minute while saying the same
sentence whether or not the address is known.

Every test signs in through the same registrar the sign-up form uses - and marks the account
verified, since an unverified one cannot sign in - because the whole application sits behind
the firewall: an unauthenticated default would turn each test into a redirect assertion
instead of the thing it means to check. The tests that *are* about being signed out say so
explicitly.

---

## Project layout

```
ai_docs/
  email-verification.md        Brevo setup, host variables, the design and its failure modes
assets/
  app.js                       AssetMapper entrypoint (Bootstrap CSS, app CSS, boot)
  paged-results.js             in-place paging + as-you-type filtering for the two cards
  calendar/
    booking-calendar.js        FullCalendar setup, paged fetching, booking dialogs
    day-page.js                Day page: cancel confirmation + customer pickers
    customer-picker.js         Debounced, race-safe customer search behind a name field
    reservations-api.js        fetch client; turns problem+json into ApiError
    notifications.js           Bootstrap toast wrapper
  fullcalendar/                FullCalendar 7 global bundles + Classic theme CSS (vendored)
  styles/app.css               design tokens, control styles, calendar re-skin
  vendor/                      importmap dependencies (committed)
config/                        Symfony configuration
docker/
  nginx/conf.d/app.conf        two-container vhost (compose): docroot, FastCGI, asset caching
  nginx/render.conf.template   single-container vhost for the `render` stage, ${PORT} rendered at boot
  php/{php.ini,opcache.ini,www.conf,entrypoint.sh}
  php/{render-php.ini,render-fpm.conf}  overrides sizing PHP for a 512 MB / 0.1 CPU instance
  php/supervisord.conf         nginx + php-fpm in one container (`render` stage)
  postgres/init/               creates booking_calendar_test on first start
migrations/
  Version20260912150000.php    the whole PostgreSQL schema, sessions table included
render.yaml                    Render Blueprint (see ai_docs/deployment.md)
src/
  Api/                         request DTOs (validated), date parsing, JSON presenters
  Booking/                     ReservationBooker, CustomerResolver, DaySchedule, metrics
  Command/                     app:verification-link, the emailed link without the email
  Controller/                  page controllers + Controller/Api/ (JSON)
  DataFixtures/                demo customers, reservations and one account
  Entity/                      Reservation, Customer, User
  Repository/                  Reservations, customers, accounts, CustomerMatch
  Security/                    UserRegistrar (hashing), EmailVerifier (signed links, sending),
                               VerifiedEmailChecker (no sign-in until verified),
                               SessionEntryPoint (401 vs redirect)
  Twig/ClockExtension.php      today(), so no template reads the clock itself
templates/
  base.html.twig               navigation, flashes, sign-out
  _flashes.html.twig           the flash alerts, on their own so a card can render them
  emails/verify_email.{html,txt}.twig  the verification email
  calendar/index.html.twig     calendar mount point + three Bootstrap dialogs
  calendar/day.html.twig       one day in full, with plain HTML forms
  calendar/_booking_fields.html.twig  customer/time/contact fields, shared
  security/{login,register}.html.twig
  security/verify_email_sent.html.twig  "check your inbox", with a resend form
  dashboard/index.html.twig    metrics bar, today, newest-first list of every booking
  dashboard/_bookings.html.twig  that list on its own, for paging in place
  customer/index.html.twig     directory: the search box and the paged card
  customer/_results.html.twig  that card on its own, for filtering and paging in place
  customer/show.html.twig      one profile
tests/
Dockerfile                     base → vendor → assets → prod, plus web (nginx) and dev
compose.yaml / compose.override.yaml
Makefile
```

---

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `APP_ENV` | `dev` (`prod` in the image) | Symfony environment |
| `APP_SECRET` | — | Symfony secret; set a real value outside local dev. Also the key the verification links are signed with, so it must be non-empty and unguessable |
| `DATABASE_URL` | `postgresql://booking_calendar:booking_calendar@database:5432/booking_calendar?serverVersion=18&charset=utf8` | Doctrine DSN. `serverVersion` lets Doctrine pick the SQL dialect without connecting; a managed database adds `&sslmode=require` |
| `MAILER_DSN` | `null://null` (`smtp://mailer:1025` in the dev overlay) | Where verification emails go. Production: `brevo+api://KEY@default` — HTTPS, so it works where SMTP ports are blocked |
| `MAILER_FROM` | `Booking Calendar <no-reply@booking-calendar.test>` | The `From` header on every email. With Brevo it must be exactly a validated sender |
| `DEFAULT_URI` | `http://localhost` (`http://localhost:8080` in the dev overlay) | Origin for URLs generated outside a request, i.e. the link `app:verification-link` prints; the signature covers the host, so it must match where the link is opened |
| `SYMFONY_TRUSTED_PROXIES` | — | Only behind a TLS-terminating proxy that the `render` stage's vhost does not already handle (it maps `X-Forwarded-Proto` itself), so emailed links come out as `https://` |
| `BOOKINGS_PER_PAGE` | `10` | Bookings per page: the dashboard's list size, and how many the calendar fetches at a time |
| `CUSTOMERS_PER_PAGE` | `25` | Customers per page of the directory |

---

## Deploying it

One container on Render's free plan, the database on Neon (managed PostgreSQL), email through
Brevo's HTTPS API. The Dockerfile's last stage, `render`, puts nginx and php-fpm in one
container answering on `$PORT`; `render.yaml` is the Blueprint. The full walkthrough - what
had to change in this repo, the variables, the measured cost and cold-start figures, and
what to do when it breaks - is [`ai_docs/deployment.md`](ai_docs/deployment.md).

---

## Upgrading an existing 2016 database

The 2016 app kept everything in one MySQL table, `calendar (id, name, startdate VARCHAR(48),
details)`. Until 2026-09-12 this project's migrations ran on MySQL too and one of them
(`Version20260908120100`, in git history) imported that table in place. The move to
PostgreSQL retired that path: the import was MySQL-to-MySQL, and a Postgres database has no
legacy table to detect.

To bring the old bookings over now, stage the legacy rows in Postgres and let the two
statements below do what the old migration chain did - one customer profile per distinct
name, then one reservation per legacy row:

```sql
-- 1. Stage the 2016 rows (pgloader, or a CSV export and \copy):
CREATE TABLE legacy_calendar (id INT, name TEXT, startdate TEXT, details TEXT);

-- 2. One profile per name. The phone number is required today and the legacy
--    table never had one, so it is the empty string: such a profile shows as
--    "needs a number" in the directory and the profile form insists on one.
INSERT INTO customer (full_name, phone, created_at, updated_at)
SELECT DISTINCT LEFT(TRIM(name), 120), '', NOW(), NOW()
FROM legacy_calendar
WHERE TRIM(COALESCE(name, '')) <> '';

-- 3. One booking per row whose startdate is a real YYYY-MM-DD day. The shape
--    check alone is not enough - '2016-02-31' has the shape and the cast would
--    abort the whole statement - hence pg_input_is_valid (PostgreSQL 16+).
--    Anything else is skipped rather than turned into a made-up day.
INSERT INTO reservation (customer_id, reserved_on, details, created_at, updated_at)
SELECT c.id, l.startdate::date, NULLIF(TRIM(COALESCE(l.details, '')), ''), NOW(), NOW()
FROM legacy_calendar l
JOIN customer c ON c.full_name = LEFT(TRIM(l.name), 120)
WHERE l.startdate ~ '^\d{4}-\d{2}-\d{2}$' AND pg_input_is_valid(l.startdate, 'date');

DROP TABLE legacy_calendar;
```

The rules the old chain enforced still hold, by construction: a booking belongs to exactly
one customer, a phone number is required for anything booked from now on, there is no per-day
cap, and every account created through `/register` has to verify its address (there are no
pre-existing accounts to grandfather - the 2016 app had none).

### What changed from the legacy app

| 2016 | Now |
| --- | --- |
| `mysqli_query("… '$name' …")` — request data in SQL strings | Doctrine ORM with bound, typed parameters |
| `startdate VARCHAR(48)`, `latin1` | `reserved_on DATE` + index, UTF-8 |
| Daily limit checked in JavaScript, so a second tab could overbook | No daily limit at all - a day takes as many bookings as it is given |
| One `process.php` switching on a POST `type` field | Five routed JSON endpoints with validated DTOs and correct status codes |
| `echo json_encode(...)`, no status codes | `201`/`204`/`400`/`401`/`404`/`422` with problem+json bodies |
| `config.php` with hard-coded `root`/no password | `DATABASE_URL` environment variable |
| jQuery 2 · Bootstrap 3 · FullCalendar 2.6 · Bower | Vanilla ES modules · Bootstrap 5.3 · FullCalendar 7.1 · AssetMapper |
| Inline `<script>` in `index.php` | Twig templates + three ES modules |
| Errors as `alert()` / a second stacked modal | Accessible toast, server messages surfaced verbatim |
| No tests, no CI-able checks | PHPUnit suite + PHPStan level 8 |
| "Edit `config.php` for database credentials" | `docker compose up -d` |
| Anyone who knew the URL could read and edit every booking | Session required; only signing in, signing up and the verification round-trip are public, and a new account cannot sign in until its address has been verified |
| The customer was a name typed per booking, with nowhere to put anything else | `customer` table, matched on phone/email/name, searchable while booking, with a profile page |
| No styling beyond Bootstrap 3 defaults | A token-based design system: one palette, one type scale, a metrics bar, a day timeline, and a re-skinned calendar |
| A day held whatever fitted on the month grid | A day view at `/day/{date}` with every booking and its contact details |
| Every reservation was echoed onto one page | The dashboard counts, and both the list and the calendar fetch one page at a time |
| No answer to "what is on the books?" | A dashboard with today, tomorrow, the next seven days, upcoming and all-time counts |

---

## Implementation notes

- **Why FullCalendar is loaded as a global bundle.** FullCalendar 7 publishes ES modules
  that expect a bundler to deduplicate their Preact runtime. Served as separate browser
  modules through an importmap, FullCalendar and Preact end up in incompatible bundles and
  the calendar throws `Class constructor … cannot be invoked without 'new'` while
  rendering. The project therefore uses the vendor's own self-contained global bundles
  (`assets/fullcalendar/`), which register every view plugin up front — hence no `plugins`
  option in `booking-calendar.js`. Bootstrap has no such constraint and stays an importmap
  ES module.
- **Why `slot` is gone.** It existed only to express "at most N per day" as a database
  constraint, via a unique `(reserved_on, slot)` index. With no cap there is nothing for a
  slot to constrain, so the column, its index, its numbering and the two "the day moved
  under you" exceptions all went with it.
- **Why PostgreSQL, and why one engine everywhere.** Production is Neon, which is Postgres
  only. Running the same engine in `compose.yaml` means the migrations and every query are
  exercised on the real dialect before they ship - the move from MySQL found two things the
  SQLite suite could not: PostgreSQL sorts `NULL` *first* in a `DESC` order (MySQL and SQLite
  last), which reversed the customer directory's "never dealt with, so last" rule until the
  ORDER BY spelled it out with a `CASE`; and Postgres name order follows the database
  collation rather than MySQL's case-insensitive default, so the tie-break now goes through
  `LOWER()`. `make test-postgres` exists so the next such difference is caught the same way.
- **Why sessions are in the database in production.** A container platform's disk does not
  survive a deploy or a scale-to-zero, and with file sessions that signs everyone out and
  invalidates every open form's CSRF token each time. `PdoSessionHandler` writes them to a
  `sessions` table over Doctrine's own connection (so one `DATABASE_URL`, TLS included,
  configures both) with advisory locking, because the default transactional locking would
  hold a transaction open on the shared connection and break Doctrine's `flush()`. Dev and
  test keep the default handlers; the table is created by the migration all the same.
- **Why `/healthz` touches nothing.** The platform polls it every few seconds. Through
  php-fpm so a dead PHP fails the check, but without a query or a session, so the probe does
  not keep a scale-to-zero database awake and spend its compute allowance on nothing.
- **Why verification links are signed URLs, not stored tokens.** The framework's
  `UriSigner` already HMACs a whole URL with `kernel.secret` and stamps an expiry on it, and
  the account id in the query string is covered by that signature. So there is no token
  table to write, expire or sweep: `verified_at` on the account is the entire state. The
  cost is that a link is only valid at the origin it was signed for (host included), which is
  why `app:verification-link` depends on `DEFAULT_URI`.
- **Why the "verify first" refusal happens after the password check.** Symfony runs a user
  checker's `checkPreAuth()` before it looks at the password. Refusing an unverified account
  there would tell anyone who typed an address whether an account exists for it, which is
  the enumeration the form's generic "Invalid credentials." is there to prevent. The check
  lives in `checkPostAuth()` instead, so only someone holding the right password learns the
  account is waiting on its email - and that person is offered the link again.
- **Why the link signs the account in, once.** Possessing the link is exactly the proof of
  inbox control that was asked for, and the person following it set the password moments
  ago; bouncing them to the sign-in form would be busywork. A link that has already done
  its work only redirects to sign-in, so a used link found later in a mailbox or browser
  history is not a way in.
- **Why the day view is a page and not a modal.** The month grid is the right place to see
  the shape of a month and the wrong place to read a customer's phone number. The day page
  is deliberately plain HTML - every action is a form post that redirects back - so it still
  works if a script fails to load, which the calendar's dialog-driven flow cannot promise.
- **Why the design is a token layer rather than restyled components.** Colour, type, radii
  and elevation are custom properties in `assets/styles/app.css`; Bootstrap components read
  from them, and so do FullCalendar's own `--fc-classic-*` variables. That is what keeps the
  calendar and the surrounding chrome on one palette without overriding either library's
  internals.
- **Why the calendar is styled through roles, not classes.** The Classic theme renames every
  logical class to an obfuscated `fc-classic-…`, so `#calendar .fc-button` and friends match
  nothing at all. The colour tokens still work - the theme's own rules consume them - but
  anything structural hooks onto roles, `[data-date]` and real `button` tags instead.
- **Contrast is measured, not eyeballed.** Every text/surface pair in the palette is checked
  against WCAG AA; the two pale greys that failed are reserved for decoration (separators,
  empty-state glyphs) and every text use moved up a step. `ink-300` measures 2.3:1 on white,
  which is why it never carries words.
- **Why the calendar does not use `dateClick` or `navLinks`.** This build of FullCalendar is
  the Classic theme, which strips the logical `fc-event` / `fc-more-link` class names and
  leaves only obfuscated ones, and `navLinks` wraps each day's whole cell in an anchor.
  Between the two, a click on a day cell either did nothing or navigated, and FullCalendar's
  own "is this a valid date-click target" test cannot be reasoned about from outside the
  bundle. `booking-calendar.js` therefore attaches its own listener in `dayCellDidMount`,
  and marks events and the "+N more" link with `data-calendar-interactive` as they mount so
  the day listener leaves them to FullCalendar's own handlers.
- **Why the customer picker ships as an enhancement.** The name input is a working form field
  before any script runs: the server resolves a typed name to an existing profile by email,
  phone or name. The picker only improves on that by searching as you type, and it is
  debounced and sequence-checked so a slow reply cannot overwrite a newer one.
- **Why the paged cards fetch fragments instead of building rows in JavaScript.** The
  directory and the dashboard's bookings list ask their own URL for the same Twig partial
  again - the request carries `X-Requested-With`, and the controller skips the layout - so
  the row markup has exactly one source. `paged-results.js` only swaps the card and edits
  the address bar; with no script at all, the pager links and the search form do the same
  job the slow way.
- **Timestamps** are stored as UTC `TIMESTAMP WITHOUT TIME ZONE` (`date.timezone=UTC` in
  `docker/php/php.ini`), so the database never has to guess a zone.
- **`assets/vendor/` is committed** on purpose (the AssetMapper recipe ignores it by
  default) so image builds and CI never depend on a CDN.
- **OPcache is not installed as an extension.** PHP 8.5's official images compile it in
  statically and enable it (`php -m` lists `Zend OPcache`, and there is no `opcache.so`),
  so `docker-php-ext-install opcache` builds no shared module and its `make install` fails
  on `cp modules/*`. `docker/php/opcache.ini` configures the built-in copy; the dev image
  overrides `validate_timestamps` on top of it.
- **nginx falls back to the front controller for `/assets/`.** In prod the digest-hashed
  files exist on disk and nginx serves them directly. In dev `public/assets` is not
  compiled at all and AssetMapper serves each file through `index.php`, so the `try_files`
  fallback in `docker/nginx/conf.d/app.conf` is what keeps dev CSS and JS from 404ing.
