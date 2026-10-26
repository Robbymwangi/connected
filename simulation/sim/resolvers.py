"""The ways a server can settle what it receives, each in a few lines.

A resolver sees submissions one at a time, in the order the server received them, and
says what it did with each, measured against the value the cell held before:

    applied    the submission is the cell's value now
    discarded  it was thrown away; the cell kept its value
    conflict   it was kept aside as a conflict for a person; the cell kept its value
    unchanged  it carried the value the cell already held

A resolver sees only what its own design lets a server see. Last writer wins sees each
device's own clock (`at`); it never sees true times or what a device knew.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Literal, Protocol

from .trace import Submission

Kind = Literal["applied", "discarded", "conflict", "unchanged"]


@dataclass(frozen=True)
class Outcome:
    kind: Kind
    # The submission whose value the cell held when this one arrived, if any.
    against: int | None


class Resolver(Protocol):
    name: str

    def receive(self, s: Submission) -> Outcome: ...


class LastWriterWins:
    """Keeps whichever value carries the latest device clock. It never reports a
    conflict: two values for one cell are settled by the timestamps alone."""

    name = "Last writer wins (device clocks)"

    def __init__(self) -> None:
        self.current: dict[str, Submission] = {}

    def receive(self, s: Submission) -> Outcome:
        cur = self.current.get(s.cell)
        if cur is None:
            self.current[s.cell] = s
            return Outcome("applied", None)
        newer = s.at > cur.at
        if newer:
            self.current[s.cell] = s
        if s.value == cur.value:
            return Outcome("unchanged", cur.id)
        return Outcome("applied" if newer else "discarded", cur.id)
