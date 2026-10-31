"""The ways a server can settle what it receives, each in a few lines.

A resolver sees submissions one at a time, in the order the server received them, and
says what it did with each, measured against the value the cell held before:

    applied    the submission is the cell's value now
    discarded  it was thrown away; the cell kept its value
    conflict   it was kept aside as a conflict for a person; the cell kept its value
    unchanged  it carried the value the cell already held

A resolver sees only what its own design lets a server see. Last writer wins sees each
device's own clock (`at`). Server versions and vector clocks see what the device sends with
its edit: the version, or the vector, of the cell as the device last pulled it. That is
worked out by `History`, from the resolver's own state at the device's last sync; true times
and device clocks never reach either of them.

`metadata` is the cost side: how many numbers the resolver keeps per cell to decide.
"""

from __future__ import annotations

import bisect
from dataclasses import dataclass, field
from typing import Generic, Literal, Protocol, TypeVar

from .trace import Submission

T = TypeVar("T")
Kind = Literal["applied", "discarded", "conflict", "unchanged"]


@dataclass(frozen=True)
class Outcome:
    kind: Kind
    # The submission whose value the cell held when this one arrived, if any.
    against: int | None


class Resolver(Protocol):
    name: str

    def receive(self, s: Submission) -> Outcome: ...

    def metadata(self) -> list[int]:
        """The numbers it keeps to decide, one count per cell."""
        ...


@dataclass
class History(Generic[T]):
    """What the server held for each cell after each submission it received, so that the
    state a device pulled at its last sync can be read back: that is all the device knows
    when it edits, and so all it can send. Submissions arrive in order of receipt, so each
    cell's list is already sorted by time."""

    times: dict[str, list[float]] = field(default_factory=dict)
    states: dict[str, list[T]] = field(default_factory=dict)

    def record(self, cell: str, received: float, state: T) -> None:
        self.times.setdefault(cell, []).append(received)
        self.states.setdefault(cell, []).append(state)

    def pulled(self, cell: str, at: float, empty: T) -> T:
        """The cell as the server held it at true time `at`; `empty` if it held nothing."""
        i = bisect.bisect_right(self.times.get(cell, []), at)
        return self.states[cell][i - 1] if i else empty


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

    def metadata(self) -> list[int]:
        # One timestamp per cell.
        return [1] * len(self.current)


class ServerVersions:
    """This system's rules for a mark (docs/spec/sync-protocol.md, POST /sync rules 2 to 4).

    The server keeps one integer per cell, raised only when the value changes. A device sends
    the version it last pulled with its edit (`baseVersion`, 0 for a cell it has never seen):

        base equals the version     applied, or unchanged if it repeats the value
        base behind, same value     unchanged: the "auto" conflict, which needs nobody
        base behind, other value    conflict: the cell keeps its value and a person decides

    A mark's kind and score move together, so a cell here is one field and the
    disjoint-field merge of rule 4 never arises; a stale base always overlaps.
    """

    name = "Server versions (this system)"

    def __init__(self) -> None:
        # Per cell: its version, and the submission whose value it holds.
        self.current: dict[str, tuple[int, Submission]] = {}
        self.history: History[int] = History()

    def receive(self, s: Submission) -> Outcome:
        base = self.history.pulled(s.cell, s.pulled, 0)
        cur = self.current.get(s.cell)
        if cur is None:
            # Rule 3: a create, at version 1.
            self.current[s.cell] = (1, s)
            self.history.record(s.cell, s.received, 1)
            return Outcome("applied", None)
        version, holder = cur
        if s.value == holder.value:
            return Outcome("unchanged", holder.id)
        if base < version:
            return Outcome("conflict", holder.id)
        self.current[s.cell] = (version + 1, s)
        self.history.record(s.cell, s.received, version + 1)
        return Outcome("applied", holder.id)

    def metadata(self) -> list[int]:
        # One version per cell.
        return [1] * len(self.current)


Vector = dict[str, int]


def _covers(a: Vector, b: Vector) -> bool:
    """Whether `a` has seen everything `b` has."""
    return all(a.get(device, 0) >= n for device, n in b.items())


def _merge(vectors: list[Vector]) -> Vector:
    out: Vector = {}
    for v in vectors:
        for device, n in v.items():
            out[device] = max(out.get(device, 0), n)
    return out


class VectorClocks:
    """Vector clocks, with the values that disagree kept side by side as siblings.

    Every value carries a vector: for each device that has edited the cell, how many of its
    edits lie behind this value. A device's edit takes the merge of every vector it pulled
    for the cell and adds one to its own entry. On arrival:

        it covers a sibling           that sibling is replaced: it was seen and superseded
        it covers none of another     the two are concurrent: neither device saw the other
        concurrent, values differ     conflict: kept as a sibling for a person to settle
        concurrent, values agree      both kept, nothing to settle

    The cell shows one sibling, the latest applied; `against` names it.
    """

    name = "Vector clocks"

    def __init__(self) -> None:
        self.siblings: dict[str, list[tuple[Vector, Submission]]] = {}
        self.shown: dict[str, Submission] = {}
        self.history: History[Vector] = History()

    def receive(self, s: Submission) -> Outcome:
        pulled = self.history.pulled(s.cell, s.pulled, {})
        vector = {**pulled, s.device: pulled.get(s.device, 0) + 1}
        siblings = self.siblings.get(s.cell, [])
        kept = [(v, p) for v, p in siblings if not _covers(vector, v)]
        shown = self.shown.get(s.cell)

        if any(p.value != s.value for _v, p in kept):
            outcome = Outcome("conflict", shown.id if shown else None)
        else:
            if shown is None:
                outcome = Outcome("applied", None)
            elif shown.value == s.value:
                outcome = Outcome("unchanged", shown.id)
            else:
                outcome = Outcome("applied", shown.id)
            if outcome.kind == "applied":
                self.shown[s.cell] = s
        self.siblings[s.cell] = kept + [(vector, s)]
        self.history.record(s.cell, s.received, _merge([v for v, _p in self.siblings[s.cell]]))
        return outcome

    def metadata(self) -> list[int]:
        # Every entry of every sibling's vector.
        return [sum(len(v) for v, _p in sibs) for sibs in self.siblings.values()]
