# Deploying the Booking Calendar for free

Written 2026-09-12. Every claim about *this repo* below was verified by running
it - the commands and their output are included so you can re-check them.
Provider limits are quoted from the two sibling guides written against the same
accounts (`brgy-project/ai_docs/deployment.md`, 2026-09-08, and
`save_furry_friend/ai_docs/deployment.md`, 2026-08-12), which read them from the
providers' own pages on those dates. **Free tiers change constantly** - re-check
a number before you rely on it.

Target: a portfolio demo. One live URL, zero real visitors, no SLA. Cold starts
are fine. That goal is what makes this $0.

---

## Contents

- [You need three of your accounts](#you-need-three-of-your-accounts)
- [What this costs](#what-this-costs)
- [Six repo changes were required](#six-repo-changes-were-required)
- [How the pieces wire together](#how-the-pieces-wire-together)
- [Step by step](#step-by-step)
- [Cold start](#cold-start)
- [When it doesn't work](#when-it-doesnt-work)
- [What we did not use, and when you would add it](#what-we-did-not-use-and-when-you-would-add-it)

---

## You need three of your accounts

| Service | Needed | Why |
|---|---|---|
| **Render** web service | **Yes** | Runs the container. One service, Docker runtime, Free plan, Singapore. |
| **Neon** Postgres | **Yes** | The database. Neon is Postgres-only and this app was MySQL, so the app was moved to PostgreSQL wholesale - development, tests and production now run one engine. Details in [change 1](#1-the-app-was-mysql-neon-is-postgres). |
| **Brevo** | **Yes** | Sign-up emails a verification link and the account is closed until it is followed, so mail has to reach real inboxes. Render Free blocks outbound SMTP (25/465/587), so the mailer speaks Brevo's **HTTPS API**. Reuses the account, validated sender and transactional activation you already have; only a new API key is needed. Walkthrough: [email-verification.md](email-verification.md). |
| MongoDB Atlas | No | Nothing here is a document. |
| Cloudflare R2 | No | No file storage of any kind - no uploads, no avatars. |
| Cloudflare Pages | No | Pages hosts static sites. This app is server-rendered Twig; nginx *inside* the container serves the compiled AssetMapper output from `public/assets`. There is no separate front end to host. |

So: **Render + Neon + Brevo**, and no new sign-ups. TiDB Cloud Starter and
Aiven's free MySQL would have kept the MySQL code untouched but meant a fourth
account; you chose to reuse Neon instead, and the port is done.

---

## What this costs

**$0/month, permanently, with no card on file.** Neither Render nor Neon asks
for one; Brevo does not either.

Measured against the real limits:

| Limit | Free allowance | This app | Headroom |
|---|---|---|---|
| Neon storage | 0.5 GB per project | **8 MB** with the schema, the session table and a handful of rows | 1.6% used |
| Neon compute | 100 CU-hours/month, scales to zero after 5 idle minutes | idle between requests; `/healthz` never touches it | ~400 h/month at 0.25 CU |
| Render RAM | **512 MB** for the whole container | **72 MiB** peak under 30 concurrent signed-in requests | 14% used |
| Render CPU | **0.1 CPU** | see [cold start](#cold-start) | the real constraint |
| Render instance hours | 750/month per workspace; spun-down services consume none | one service | ample |
| Brevo | 300 emails/day | one per sign-up, one per "send again" (throttled to one a minute per address) | ample |

Two Render facts to internalise before you start, both from
<https://render.com/docs/free>:

- **No shell access.** Free web services have neither SSH nor a dashboard shell
  nor one-off jobs. This app needs none: the entrypoint migrates the database at
  boot, the first account is created through `/register`, and the console
  commands you might want (`app:verification-link`) can be run from your laptop
  against Neon - [step 6](#6-verify).
- **Render's own free Postgres expires 30 days after creation.** That is why the
  database is Neon, whose free plan has no expiry.

The 8 MB is measured, not estimated:

```
$ psql -tAc "SELECT pg_size_pretty(pg_database_size('booking_calendar'))"
8150 kB
```

A booking is a few hundred bytes. You will not reach 0.5 GB.

---

## Six repo changes were required

The image in this repo could not be deployed anywhere until these were made.
All six are applied - this section is so you know why the files look the way
they do, and so you can recognise the failure if you undo one.

### 1. The app was MySQL; Neon is Postgres

Everything that talked SQL was moved to PostgreSQL 18, and the same engine now
runs in `compose.yaml` (`postgres:18-alpine`, host port **15432**), so the
migrations and every query are exercised locally against the dialect production
uses. What changed:

- `Dockerfile` installs `pdo_pgsql` (`libpq`) instead of `pdo_mysql`.
- The seven hand-written MySQL migrations were replaced by one PostgreSQL
  migration, `migrations/Version20260912150000.php`, generated from the entity
  mapping (`doctrine:migrations:diff`) and then annotated. Nothing running
  Postgres ever held any of the intermediate states, so a history of them would
  have been fiction. The old files are in git history.
- The MySQL-only table options (`utf8mb4`, `InnoDB`) came off the entities.
- Consequence for the README's "upgrading a 2016 database" story: the legacy
  `calendar` import was MySQL-to-MySQL and went with the migration chain. See the
  README section of the same name for what to do instead.

Two queries were **silently engine-dependent**, and the suite could not tell:

**Customers who have never been dealt with sorted to the top of the directory on
Postgres.** `CustomerRepository::findDirectoryPage()` ordered by
`MAX(r.reservedOn) DESC` and relied on NULL sorting last, which MySQL and SQLite
do and PostgreSQL does not:

```
$ psql -tAc "SELECT string_agg(COALESCE(x::text,'NULL'), ' ' ORDER BY x DESC) FROM (VALUES (1),(NULL),(2)) v(x)"
NULL 2 1
```

With the old ORDER BY, against Postgres 18:

```
1) App\Tests\CustomersPageTest::testAFutureBookingDoesNotMoveACustomerUpTheDirectory
2) App\Tests\CustomersPageTest::testCustomersWithNoBookingAtOrBeforeTodayComeLastInNameOrder
Tests: 5, Assertions: 24, Failures: 2.
```

and the same tests on SQLite: `OK (5 tests, 24 assertions)` - which is exactly
how it would have shipped. Fixed with an explicit
`CASE WHEN MAX(r.reservedOn) IS NULL THEN 1 ELSE 0 END` first, as the day page's
query already did for its own NULLs.

**Name order was case-sensitive.** MySQL's `utf8mb4_unicode_ci` sorted `dasd`
before `Kamatayan`; Postgres sorts by the database collation, which on the
alpine image (musl) is code-point order, so `dasd` came after `MJ`. The
tie-break and the search ordering now go through `LOWER(c.fullName)`, which is
the same everywhere.

The suite is green on both engines the project runs, 73 tests each:

| Engine | Command | Result |
|---|---|---|
| SQLite (schema from the mapping) | `vendor/bin/phpunit` | **OK (73 tests, 435 assertions)** |
| PostgreSQL 18 (`booking_calendar_test`, created by `docker/postgres/init/`) | `make test-postgres` | **OK (73 tests, 435 assertions)** |

SQLite is kept green deliberately: it is the cheapest possible check that no
engine-specific SQL has crept back in. PHPStan level 8: no errors.

### 2. The image spoke FastCGI, not HTTP

*Same finding as both sibling guides, and it applies unchanged.*

The `prod` stage ends `EXPOSE 9000` / `CMD ["php-fpm"]`, with nginx as a
separate compose service. A free PaaS gives you **one** container and routes HTTP
to **one** injected port; Render pointed at that image would connect and receive
FastCGI bytes it cannot parse.

Added a final `render` stage (`FROM prod`): nginx + php-fpm under supervisord,
answering HTTP on `$PORT`. Its vhost is `docker/nginx/render.conf.template`,
rendered at boot by the entrypoint (nginx cannot read env; only the literal
`${PORT}` token is substituted). It also maps `X-Forwarded-Proto: https` onto
the `HTTPS` FastCGI param, so Symfony builds `https://` links behind Render's
TLS terminator without a `trusted_proxies` setting.

`compose.yaml` pins `target: prod`, so the dev workflow is untouched. A plain
`docker build .` - which is what Render runs - builds `render`, because it is
the last stage. **Keep it last.**

### 3. Sessions lived on the container's disk

Symfony's default session handler writes to `var/sessions`. Render's free plan
has no persistent disk and spins the instance down after 15 idle minutes, so
every cold start would sign everyone out and, worse, invalidate the CSRF token
of any form still open in a browser ("Your session expired" on the next click).
The pending-verification address shown after sign-up lives there too.

In the `prod` environment sessions now go to a `sessions` table in Postgres
through `PdoSessionHandler` (`config/packages/framework.yaml`,
`config/services.yaml`). Two details cost real time:

- The handler is handed **Doctrine's PDO instance**, not `DATABASE_URL`. Its own
  URL parser (`buildDsnFromUrl`) drops the query string for `pgsql`, which is
  where Neon's `sslmode=require` lives - a DSN-string handler would have failed
  to connect to Neon with no obvious cause.
- Because the connection is shared, it uses **advisory** locking
  (`pg_advisory_lock`) rather than the default transactional locking, which holds
  a transaction open on that connection for the whole request and makes
  Doctrine's own `flush()` fail with "There is already an active transaction".

Verified in the built image: sign in, `docker restart` the container, same
cookie → `GET /dashboard` **200**. Two rows in `sessions` afterwards.

### 4. php-fpm was sized for a laptop, not a 512 MB instance

*Same finding as the brgy guide.*

`docker/php/www.conf` allows `pm.max_children = 20` at `memory_limit = 256M`,
and `opcache.ini` reserves 256 MB of shared memory plus a 64 MB JIT buffer. On
0.1 CPU / 512 MB for nginx, supervisord and php-fpm together, that is a
container waiting to be OOM-killed; the symptom is intermittent 502s that read
like an application bug.

The `render` stage layers `docker/php/render-php.ini` and
`docker/php/render-fpm.conf` on top, named to load last:

| Setting | Dev | `render` stage |
|---|---|---|
| `pm` | `dynamic` | `ondemand` |
| `pm.max_children` | 20 | 4 |
| `memory_limit` | 256M | 128M |
| `opcache.memory_consumption` | 256 | 64 |
| `opcache.jit_buffer_size` | 64M | 16M |
| `request_slowlog_timeout` | 10s | 0 - the slowlog uses `ptrace()`, which a container does not permit, and at 0.1 CPU the first cold request trips it |

Verified by holding the built image to exactly Render's plan
(`docker run --memory 512m --cpus 0.1`) and firing 30 concurrent signed-in
dashboard requests:

```
      30 200
before: mem 63.61MiB / 512MiB
after:  mem 71.9MiB  / 512MiB (14.04%)
OOMKilled=false  Restarts=0
```

### 5. There was no health endpoint

Render polls a path to decide whether the container is alive.
`src/Controller/HealthController.php` answers `/healthz` with `ok` - **through
php-fpm**, so a dead PHP fails the check and gets the container replaced instead
of 502-ing forever, and **without touching the database or the session**, so the
probe every few seconds does not keep Neon's compute awake and spend the
100 CU-hours on nothing. It is public in `security.yaml`.

### 6. The entrypoint knew one command and one database

`docker/php/entrypoint.sh` bootstrapped only when starting `php-fpm` and waited
on MySQL's port. It now bootstraps for `supervisord` too, defaults the wait to
Postgres' 5432, and renders the nginx vhost when the template is present. The
wait is a plain TCP connect, which Neon's proxy answers even while the compute is
suspended, so boot is not held up by scale-to-zero.

---

## How the pieces wire together

One value per row. Get these right and nothing else is hard; get one wrong and
the failure looks like a different problem entirely.

| Variable | Value | Consequence of getting it wrong |
|---|---|---|
| `APP_SECRET` | `openssl rand -hex 32`, set once | Unset → Symfony refuses to sign anything: the first sign-up dies with `A non-empty secret is required.` Rotated → every outstanding verification link and remember-me cookie is invalid. |
| `DATABASE_URL` | Neon **direct** string in Doctrine form - see below | Pooled (`-pooler`) → boot-time migrations and the session handler's advisory locks run through PgBouncer transaction pooling, which drops both. Missing `sslmode=require` → `SSL connection is required`. |
| `DEFAULT_URI` | the service's own `https://…onrender.com` | Only the console-generated verification link uses it (the web flow uses the request's host), but a wrong value signs that link for the wrong origin and it reads "not valid". |
| `MAILER_DSN` | `brevo+api://xkeysib-…@default` | `brevo+smtp://` → port 465, blocked on Render. Wrong key → `Key not found (code 401)`; SMTP key instead of API key is the usual cause. |
| `MAILER_FROM` | `Booking Calendar <your.validated@gmail.com>`, in `render.yaml` | Not exactly a validated Brevo sender → `sender not valid (code 400)` on every send; the account is created but no email goes out. |
| `APP_ENV` / `APP_DEBUG` | `prod` / `0` (in `render.yaml`) | `dev` → no session table is used, the profiler is on, and errors show stack traces to the public. |
| `PORT` | injected by Render (10000) | The entrypoint renders it into the nginx vhost. |

`SYMFONY_TRUSTED_PROXIES` is **not** needed: the vhost derives `HTTPS` from
`X-Forwarded-Proto` itself.

### The Neon connection string, in Doctrine's form

Neon shows you `postgresql://USER:PASS@ep-xxx.ap-southeast-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require`.
Use it as-is if you like - Doctrine passes unknown query parameters through to
libpq - but add `serverVersion=18&charset=utf8` so Doctrine picks the dialect
without a round trip at boot:

```
postgresql://USER:PASS@ep-xxx.ap-southeast-1.aws.neon.tech/neondb?serverVersion=18&charset=utf8&sslmode=require
```

### Direct, not pooled, on purpose

Neon offers two endpoints for one database. The pooled one (`-pooler` in the
hostname) runs PgBouncer in transaction mode, which drops session state:
prepared statements, advisory locks, `SET`. This app relies on both -
`doctrine:migrations:migrate` at boot, `pg_advisory_lock` for sessions - so it
uses the **direct** endpoint for everything. That is fine here because the
pooler exists to absorb bursts of connections, and this php-fpm opens at most
**four** (`pm.max_children = 4`), well inside Neon's free-plan limit.

The brgy guide had to run its migrations from a laptop against the direct
endpoint because its runtime used the pooled one. This app does not have that
step: migrations run at boot, from the container, on every deploy.

---

## Step by step

### 0. Choose names, and get the code onto GitHub

```bash
APP_URL=https://booking-calendar.onrender.com    # Render service name: booking-calendar
```

`render.yaml` already uses `booking-calendar` and region `singapore`. If the
name is taken on Render you will be offered `booking-calendar-xxxx`; that is fine,
just use the URL Render gives you wherever `APP_URL` appears below.

Render deploys from GitHub, from the repository's `master` branch (its default).
The Symfony rewrite - `src/`, `config/`, `Dockerfile`, `render.yaml` and the rest -
was committed and pushed there on 2026-09-12, replacing the 2016 files. Before
every later deploy, the same rule: commit everything except secrets and push.

```bash
git add -A
git status                      # .env is committed on purpose (defaults only); .env.local is ignored
git commit -m "..."
git push origin master
```

Never commit `.env.local`, database URLs with passwords, or `APP_SECRET`.

### 1. Postgres - Neon

1. <https://console.neon.tech> → **New Project**.
2. Fill in:

   | Field | Value |
   |---|---|
   | Project name | `booking-calendar` |
   | Postgres version | **18** (what the suite is verified against) |
   | Cloud / region | AWS, **Singapore** `ap-southeast-1` - same as Render. **Region is permanent.** |
   | Plan | Free |

3. Keep the generated branch, database (`neondb`) and role (`neondb_owner`).
   Do not enable Neon Auth; this app has its own sign-in.
4. **Connect** → **Connection pooling OFF** → copy the string. That is the
   **direct** endpoint (no `-pooler` in the hostname), the only one this app uses.
   Rewrite it into Doctrine's form as shown above and save it as `DATABASE_URL`.

Nothing to migrate yet: the container does it at boot.

### 2. Brevo - a new API key on the account you already have

Ten minutes, all in [email-verification.md](email-verification.md) step 1: the
activation, validated Gmail sender and IP-blocking setting carry over from the
previous projects; create one new API key (`booking-calendar-render`, no expiry,
not the SMTP key), and note the exact validated sender address for
`MAILER_FROM`.

### 3. Generate the secret

```bash
openssl rand -hex 32
```

Save it as `APP_SECRET`. Set once; see the wiring table for what rotating it
costs.

### 4. Deploy - Render

1. <https://dashboard.render.com> → **New → Blueprint** → connect the
   `mjmconvento/full_calendar_js` repository, branch `master`. Render reads
   `render.yaml`.
2. Before applying, edit `render.yaml`'s `MAILER_FROM` placeholder to your
   validated sender (it is not a secret, so it lives in the file) - or set it
   in the dashboard afterwards.
3. It prompts for the four `sync: false` values:

   | Prompt | Value |
   |---|---|
   | `APP_SECRET` | step 3 |
   | `DATABASE_URL` | step 1, direct endpoint, Doctrine form |
   | `DEFAULT_URI` | `https://booking-calendar.onrender.com` (or whatever Render assigned) |
   | `MAILER_DSN` | `brevo+api://xkeysib-…@default` from step 2 |

4. **Apply.** The first build takes several minutes: it compiles `intl`,
   `pdo_pgsql`, `zip` and APCu, runs `composer install --no-dev`, compiles the
   AssetMapper output, then adds nginx and supervisord. Watch the deploy log for
   the entrypoint lines:

   ```
   [entrypoint] waiting for database at ep-xxx.ap-southeast-1.aws.neon.tech:5432 (up to 60s)
   [entrypoint] running doctrine migrations
    [OK] Successfully migrated to version: DoctrineMigrations\Version20260912150000
   [entrypoint] rendering the nginx vhost on port 10000
   [entrypoint] starting: supervisord -c /etc/supervisord.conf
   ```

Everything non-secret is already in `render.yaml` - do not set it twice in the
dashboard, the blueprint will fight you on the next sync.

If you would rather click through the UI than use the blueprint: **New → Web
Service**, connect the repo, runtime **Docker**, plan **Free**, region
**Singapore**, health check path `/healthz`, then add every variable from
`render.yaml` by hand.

### 5. Create the first account

Open `$APP_URL/register` in a browser and sign up with an address you can read.
The verification email arrives from `you@<account>.t-sender-sib.com` with a
"Sent with Brevo" footer - both expected on the free plan. Follow the link:
you land on the dashboard, signed in.

Registration stays open after that. Anyone with a working mailbox can create an
account and then see and edit every booking, so either keep the URL to yourself
or add the invite check the `RegistrationController` docblock describes before
handing it out. Decide that deliberately.

Demo data is **not** loadable in production: `doctrine/doctrine-fixtures-bundle`
is a dev dependency and is not in the image. If you want the demo customers and
bookings on the live site, load them from your laptop against Neon, and note
the `--append` - without it the command **purges every table first**:

```bash
docker compose run --rm --no-deps -T \
  -e DATABASE_URL='postgresql://USER:PASS@ep-xxx.ap-southeast-1.aws.neon.tech/neondb?serverVersion=18&charset=utf8&sslmode=require' \
  php php bin/console doctrine:fixtures:load --no-interaction --append
```

That also creates `manager@booking-calendar.test` / `booking-calendar`, a
published password. Delete that row before the URL is public.

### 6. Verify

```bash
curl -s -o /dev/null -w '%{http_code}\n' $APP_URL/healthz                  # 200
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' $APP_URL/         # 302 -> /login
curl -s $APP_URL/login | grep -c 'name="_csrf_token"'                      # 1
curl -sI $APP_URL/assets/ | grep -i cache-control                          # (only on a real asset URL: public, max-age=31536000, immutable)
```

Then in a browser: sign in, open **Customers** and check the order - people
with a past booking first, most recent first, then everyone else
alphabetically. That is the Postgres-specific behaviour from change 1, and the
check most likely to catch a regression.

For reference, the same sequence against the built image locally, constrained
to Render's Free plan, warm:

```
GET /login         -> 200   4,963 B
POST /login        -> 302 -> /dashboard
GET /dashboard     -> 200  14,997 B   0.83 s
GET /customers     -> 200   9,951 B   1.14 s
GET /day/2026-09-12 -> 200 10,940 B   1.02 s
GET /api/reservations?start=…&end=…  -> 200
sessions rows in Postgres: 2
```

**Expect it to feel slow, and do not chase that as a bug.** A tenth of a CPU is
the binding constraint - not memory, not the database. About a second per page
is what Free costs; $7/month of Render Starter is what fixes it.

Console commands against production run from your laptop, with the Neon URL:

```bash
docker compose run --rm --no-deps -T \
  -e DATABASE_URL='postgresql://…&sslmode=require' -e DEFAULT_URI=$APP_URL \
  php php bin/console app:verification-link someone@example.com
```

### 7. Keepalive - the step people skip

Render Free spins down after 15 idle minutes; Neon's compute scales to zero
after 5. Neither *deletes* anything - the cost of idling is purely the cold
start below.

If you are showing this to someone at a scheduled time, hit `$APP_URL/healthz` a
few minutes beforehand. A free cron-ping service pointed at `/healthz` every 10
minutes keeps Render warm **without waking Neon** - the endpoint touches no
database - so unlike the brgy setup this costs no CU-hours. The first real page
after a quiet spell still pays Neon's few-hundred-millisecond resume. Do not
point a keepalive at `/`: that redirects to `/login`, which renders a page and
starts a session for nothing.

---

## Cold start

First request after idle: **roughly a minute.** Render has to pull and start the
container, then the entrypoint runs `cache:clear`, `cache:warmup` and
`doctrine:migrations:migrate` before php-fpm accepts anything. Measured on the
built image at 0.1 CPU: **healthy after ~60 s** from a cold pull, **~42 s** on a
restart with the image already present. Neon adds a few hundred milliseconds
for its own resume on the first query.

Nothing to be done about it on a free plan; it is the cost of scale-to-zero.
Warm requests settle at the 0.8–1.1 s in step 6.

---

## When it doesn't work

| Symptom | Cause |
|---|---|
| Build succeeds, service never becomes healthy, log ends at `starting: supervisord` | Health check path is not `/healthz`, or `/healthz` was taken out of `access_control` so it redirects to `/login` (302 is not healthy). |
| `SSL connection is required` / `no pg_hba.conf entry` in the boot log | `DATABASE_URL` lacks `&sslmode=require`. |
| Boot waits 60 s on `database:5432` then dies | `DATABASE_URL` is unset on Render, so the entrypoint waits for the compose service name. |
| Migrations hang or fail with prepared-statement errors at boot | You pasted the **pooled** (`-pooler`) Neon URL. Use the direct one. |
| Every request 500s with `relation "sessions" does not exist` | The migration did not run (see the two rows above) or was rolled back; `sessions` is created by `Version20260912150000`. |
| Signed out on every deploy / "Your session expired" on forms | `APP_ENV` is not `prod`, so the file session handler is in use; or `PdoSessionHandler` was re-pointed at a DSN string and cannot reach Neon (the URL parser drops `sslmode`). |
| `There is already an active transaction` | The session handler's `lock_mode` was changed back to transactional while sharing Doctrine's connection. Change 3. |
| `A non-empty secret is required.` on the first sign-up | `APP_SECRET` is empty. Step 3. |
| Container healthy, `connect() failed (111)` in nginx log **once** at boot | Normal: nginx started before php-fpm was listening. Repeated forever → php-fpm is crashing; look for `FATAL` above it. |
| Intermittent 502s under light traffic | Container OOM-killed: the `render` stage's php-fpm overrides were dropped and the dev pool's 20 workers are running on 512 MB. Change 4. |
| `could not find driver` | `pdo_pgsql` missing - you are deploying a stage other than `render`/`prod`, or the Dockerfile's extension list was edited. |
| Directory lists customers with no bookings first | The `CASE WHEN MAX(r.reservedOn) IS NULL` ordering was reverted. Change 1. |
| Registration works, no email, `Unable to send an email: … (code 401)` in `render logs` | Wrong Brevo key - most often the SMTP key. [email-verification.md](email-verification.md), troubleshooting. |
| `sender not valid (code 400)` | `MAILER_FROM` is not exactly the validated sender. |
| Verification link in the email starts with `http://` | The `HTTPS` fastcgi_param map was removed from `render.conf.template`. Links still work if Render redirects http→https, but fix the template. |
| No way to run `bin/console` on the service | Correct - Free web services have no shell. Run it from your laptop against Neon's direct URL, as in step 6. |

---

## What we did not use, and when you would add it

| Service | Add it when |
|---|---|
| **Cloudflare R2** | The app gains file uploads - customer photos, attachments. There are none today. |
| **Cloudflare Pages** | Only if the Twig front end is ever split into a JS app talking to `/api`. The JSON API exists, but the pages are server-rendered on purpose (see the README's implementation notes). |
| **MongoDB Atlas** | No plausible trigger: customers and reservations are relational and the foreign key does real work (`ON DELETE RESTRICT`). |
| **Cloudflare DNS** | You buy a domain. Point it at Render; then `DEFAULT_URI` and Render's custom-domain setting change together. |
| **Neon pooled endpoint** | Traffic that opens many short connections - not this app at `pm.max_children = 4`. If you ever switch, migrations must move out of the boot sequence and back to a laptop-run step against the direct endpoint. |

### If the free tier stops being enough

In order of what breaks first:

1. **Cold starts become unacceptable.** Render Starter is $7/month and never
   spins down. The first thing worth paying for.
2. **Neon's 100 CU-hours run out.** Only if something polls the database
   continuously; nothing here does, and `/healthz` is deliberately database-free.
3. **0.5 GB of storage.** At 8 MB with the schema in place and a few hundred
   bytes per booking, that is on the order of a million bookings. You will never
   hit this.
