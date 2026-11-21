#!/usr/bin/env python3
"""PostToolUse: lint the file just written and surface the cross-file rules it touches."""

from __future__ import annotations

from _common import ROOT, clip, has, project_path, read_event, respond, run, running_services

DIALECT_SENSITIVE = (
    "src/Repository/",
    "src/Booking/DashboardMetrics.php",
    "src/Booking/DaySchedule.php",
)
DOMAIN_FILES = (
    "src/Booking/CustomerResolver.php",
    "src/Booking/ReservationBooker.php",
    "src/Repository/CustomerMatch.php",
)
FRONTEND_PREFIXES = ("templates/", "assets/styles/", "assets/calendar/", "assets/paged-results.js")


def in_container(*cmd: str) -> tuple[int, str] | None:
    if "php" not in (running_services() or []):
        return None
    return run(["docker", "compose", "exec", "-T", "php", *cmd])


def lint(rel: str) -> str | None:
    if rel.endswith(".php"):
        result = run(["php", "-l", rel]) if has("php") else in_container("php", "-l", rel)
        if result and result[0] not in (0, 127):
            return f"PHP syntax error in {rel}:\n{clip(result[1])}"
    if rel.endswith(".twig"):
        result = in_container("php", "bin/console", "lint:twig", rel)
        if result and result[0] not in (0, 127):
            return f"Twig lint failed for {rel}:\n{clip(result[1])}"
    return None


def main() -> None:
    rel = project_path(read_event())
    if rel is None or not (ROOT / rel).is_file():
        return
    notes: list[str] = []
    problems: list[str] = []

    if rel.startswith("src/Entity/"):
        notes.append(
            "Doctrine mapping changed. The test suite (SQLite and `make test-postgres` alike) builds its schema from the mapping, "
            "so a missing migration passes every test and only fails in production. Generate one with "
            "`make console ARGS=\"make:migration\"`, check the PostgreSQL DDL, `make migrate`, then "
            "`make console ARGS=\"doctrine:schema:validate\"`."
        )
    if rel.startswith(DIALECT_SENSITIVE):
        notes.append(
            "Query code changed. Run `make test-postgres` as well as `make test`: PostgreSQL sorts NULL first in DESC order and "
            "orders names by collation, both of which SQLite hides (the customer directory's CASE ordering and LOWER() tie-break exist for this)."
        )
    if rel in DOMAIN_FILES:
        notes.append(
            "Customer matching rules: an explicit customerId wins; otherwise phone, then email, then name, case-insensitively. "
            "Phone or email matches may update the stored profile; a name-only match attaches the booking but never overwrites "
            "contact details. A booking form merges (blank = not asked); the profile form replaces. Customers are never deleted."
        )
    if rel.startswith("src/Api/") or rel.startswith("src/Controller/Api/"):
        notes.append(
            "JSON API changed. Consumers: assets/calendar/reservations-api.js, booking-calendar.js and customer-picker.js; the "
            "README's HTTP API section documents every endpoint. Ranges are half-open [start, end); errors stay problem+json."
        )
    if rel.startswith(FRONTEND_PREFIXES):
        notes.append(
            "Front-end rules: the Classic theme obfuscates FullCalendar's class names, so style it through the --fc-classic-* "
            "tokens, roles and [data-date], not .fc-* selectors. Colours come from the tokens in assets/styles/app.css "
            "(text pairs meet WCAG AA). The day page and the pagers must keep working without JavaScript."
        )

    problem = lint(rel)
    if problem:
        problems.append(problem)
    respond("PostToolUse", notes, problems)


if __name__ == "__main__":
    main()
