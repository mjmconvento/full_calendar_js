#!/usr/bin/env python3
"""SessionStart: tell Claude what is runnable on this machine right now."""

from __future__ import annotations

import json

from _common import ROOT, has, run, running_services


def main() -> None:
    facts = []

    code, branch = run(["git", "branch", "--show-current"], timeout=5)
    if code == 0:
        _, status = run(["git", "status", "--porcelain"], timeout=5)
        dirty = len([line for line in status.splitlines() if line.strip()])
        facts.append(f"Git branch: {branch or '(detached)'}; {dirty} uncommitted path(s).")

    _, remotes = run(["git", "branch", "-r", "--list", "origin/dependabot/*"], timeout=5)
    pending = [line.strip() for line in remotes.splitlines() if line.strip()]
    if pending:
        facts.append(f"{len(pending)} Dependabot branch(es) on origin (as of the last fetch); the dependency-upgrader agent triages them.")

    services = running_services()
    if services is None:
        facts.append("Docker is not available.")
    elif "php" in services:
        facts.append(f"Docker Compose services running: {', '.join(services)}. `make test`, `make test-postgres` and `make console` work.")
    else:
        facts.append("The Docker Compose stack is stopped; `make up` starts it.")

    if has("php") and (ROOT / "vendor").is_dir():
        facts.append("Host PHP and vendor/ are present, so `vendor/bin/phpunit` and `vendor/bin/phpstan analyse` also run without Docker.")

    print(json.dumps({"hookSpecificOutput": {"hookEventName": "SessionStart", "additionalContext": "\n".join(facts)}}))


if __name__ == "__main__":
    main()
