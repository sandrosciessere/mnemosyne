"""Issue model, failure exceptions, status derivation and the stage deadline.

Severities:
- ``hard_block``  — processing is unsafe/impossible; envelope status ``failed``;
  never overrideable.
- ``reviewable``  — a human may accept the book anyway; status ``needs_review``.
- ``warning``     — informational; status ``passed_with_warnings``.
"""

import time
from dataclasses import dataclass, field

SEVERITY_HARD_BLOCK = "hard_block"
SEVERITY_REVIEWABLE = "reviewable"
SEVERITY_WARNING = "warning"


@dataclass
class Issue:
    code: str
    severity: str
    message: str
    overrideable: bool = False
    details: dict = field(default_factory=dict)

    def to_dict(self) -> dict:
        return {
            "code": self.code,
            "severity": self.severity,
            "message": self.message,
            "overrideable": self.overrideable,
            "details": self.details,
        }


def hard_block(code: str, message: str, **details) -> Issue:
    return Issue(code, SEVERITY_HARD_BLOCK, message, overrideable=False, details=details)


def reviewable(code: str, message: str, overrideable: bool = True, **details) -> Issue:
    return Issue(code, SEVERITY_REVIEWABLE, message, overrideable=overrideable, details=details)


def warning(code: str, message: str, **details) -> Issue:
    # Warnings never block, so they are overrideable by convention.
    return Issue(code, SEVERITY_WARNING, message, overrideable=True, details=details)


def derive_status(issues: list[Issue]) -> str:
    severities = {issue.severity for issue in issues}
    if SEVERITY_HARD_BLOCK in severities:
        return "failed"
    if SEVERITY_REVIEWABLE in severities:
        return "needs_review"
    if SEVERITY_WARNING in severities:
        return "passed_with_warnings"
    return "passed"


class EpubFailure(Exception):
    """Hard, unrecoverable failure of a stage; carries the blocking issue."""

    def __init__(self, issue: Issue):
        super().__init__(issue.message)
        self.issue = issue


class StageTimeout(Exception):
    """Raised by Deadline.check() when the soft parse timeout is exceeded."""


@dataclass
class Deadline:
    """Cooperative soft timeout, checked at spine-item/stage boundaries.

    This is the documented timeout approach: stages run synchronously in
    FastAPI's threadpool and call ``check()`` at each safe boundary
    (before opening the zip, per spine item, before writing artifacts),
    which is simple, robust and leaves no abandoned threads behind.
    """

    expires_at: float

    @classmethod
    def start(cls, seconds: float) -> "Deadline":
        return cls(expires_at=time.monotonic() + seconds)

    def check(self) -> None:
        if time.monotonic() > self.expires_at:
            raise StageTimeout()
