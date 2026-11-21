---
name: deploy
description: Ship the Booking Calendar to production (one Render container, Neon Postgres, Brevo email).
disable-model-invocation: true
---

# Deploy

Production is one Render web service built from the Dockerfile's final `render` stage (nginx +
php-fpm under supervisord on `$PORT`), sized for 512 MB / 0.1 CPU. Confirm with the user before
each production step.

## 0. Preconditions

- The `verify` skill passes, including `make test-postgres` and `doctrine:schema:validate`.
- `render` is still the **last** Dockerfile stage; `main` is pushed.

## 1. Deploy

Render deploys `main` from the Blueprint (`render.yaml`, region Singapore). The entrypoint runs
Doctrine migrations at boot, so a migration ships with its code. Secrets live in Render only:

| Variable | Value |
| --- | --- |
| `APP_SECRET` | `openssl rand -hex 32`; also signs verification links, so changing it voids sent links |
| `DATABASE_URL` | Neon **direct** endpoint (not `-pooler`: migrations and advisory session locks need a real session), `…?serverVersion=18&charset=utf8&sslmode=require` |
| `DEFAULT_URI` | the service's own `https://…onrender.com` (verification links are signed for this host) |
| `MAILER_DSN` | `brevo+api://KEY@default` (the API key, not SMTP) |

`MAILER_FROM` is in `render.yaml` and must be exactly the sender validated in Brevo.

## 2. Smoke test

- `curl -fsS https://<service>.onrender.com/healthz` (php-fpm only; touches neither database nor session).
- Sign in, open the calendar, book and cancel a test reservation, open `/customers`.
- Register a throwaway account and confirm the verification email arrives.

Expect about a minute of cold start after 15 idle minutes. Registration is open on the public URL;
remind the user if that is not intended.
