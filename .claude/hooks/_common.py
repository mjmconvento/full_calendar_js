"""Helpers shared by this project's Claude Code hooks. Standard library only.

Hooks receive the event as JSON on stdin and answer with JSON on stdout; see
https://code.claude.com/docs/en/hooks for the protocol.
"""

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(os.environ.get("CLAUDE_PROJECT_DIR") or Path(__file__).resolve().parents[2]).resolve()


def read_event() -> dict:
    try:
        return json.load(sys.stdin)
    except ValueError:
        return {}


def tool_input(event: dict) -> dict:
    return event.get("tool_input") or {}


def project_path(event: dict) -> str | None:
    """The edited file as a POSIX path relative to the project root, or None outside it."""
    raw = tool_input(event).get("file_path")
    if not raw:
        return None
    path = Path(raw)
    if not path.is_absolute():
        path = Path(event.get("cwd") or ROOT) / path
    try:
        return path.resolve().relative_to(ROOT).as_posix()
    except ValueError:
        return None


def _emit(payload: dict) -> None:
    print(json.dumps(payload))
    sys.exit(0)


def deny(reason: str) -> None:
    """PreToolUse: refuse the tool call and tell Claude why."""
    _emit({"hookSpecificOutput": {"hookEventName": "PreToolUse", "permissionDecision": "deny", "permissionDecisionReason": reason}})


def ask(reason: str) -> None:
    """PreToolUse: hand the decision to the user, with the reason on screen."""
    _emit({"hookSpecificOutput": {"hookEventName": "PreToolUse", "permissionDecision": "ask", "permissionDecisionReason": reason}})


def respond(event_name: str, notes: list[str], problems: list[str] | None = None) -> None:
    """Post-event answer: problems go back to Claude as a blocking reason, notes as context."""
    payload: dict = {}
    if problems:
        payload["decision"] = "block"
        payload["reason"] = "\n\n".join(problems)
    if notes:
        payload["hookSpecificOutput"] = {"hookEventName": event_name, "additionalContext": "\n".join(notes)}
    if payload:
        _emit(payload)
    sys.exit(0)


def run(cmd: list[str], cwd: Path = ROOT, timeout: float = 30) -> tuple[int, str]:
    """Run a command; (127, message) when it is missing or times out."""
    try:
        done = subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired) as exc:
        return 127, str(exc)
    return done.returncode, (done.stdout + done.stderr).strip()


def committed(rel: str) -> bool:
    """True when the path exists in HEAD, i.e. it has already been shared."""
    return run(["git", "cat-file", "-e", f"HEAD:{rel}"], timeout=5)[0] == 0


def has(tool: str) -> bool:
    return shutil.which(tool) is not None


def running_services() -> list[str] | None:
    """Compose services currently running, or None when Docker is unavailable."""
    if not has("docker"):
        return None
    code, out = run(["docker", "compose", "ps", "--status", "running", "--services"], timeout=5)
    return sorted(out.split()) if code == 0 else None


def clip(text: str, limit: int = 3000) -> str:
    return text if len(text) <= limit else text[:limit] + "\n… (truncated)"
