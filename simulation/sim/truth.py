"""Which submissions are real conflicts, decided with knowledge no server has.

A submission is a real conflict when it arrives while the cell holds a value from
someone its device never saw, and the two values differ: two people each entered a
number without seeing the other's, and only a person can say which is right.

"Never saw" is decided from true times (trace.knew): the device had not synced since
the other value reached the server. No device clock and no version number is read
here, so this is the truth every resolver is scored against, not one of them.

The cell's live values are the ones nobody has knowingly replaced yet. A submission
replaces every live value its device had seen and joins the rest.

`witnesses` also says, for each real conflict, which live values it disagrees with unseen
and which values for the cell its device had seen, so score.py can tell a miss that loses
something from one that loses nothing.
"""

from __future__ import annotations

from dataclasses import dataclass

from .trace import Submission, Trace, knew


@dataclass(frozen=True)
class Witness:
    # Live values the submission's device never saw, that differ from it.
    unseen: tuple[Submission, ...]
    # Every earlier value for the cell its device had seen, live or since replaced.
    seen: tuple[Submission, ...]


def witnesses(trace: Trace) -> dict[int, Witness]:
    live: dict[str, list[Submission]] = {}
    every: dict[str, list[Submission]] = {}
    out: dict[int, Witness] = {}
    for s in trace.submissions:
        unseen = [p for p in live.get(s.cell, []) if not knew(s, p)]
        differing = tuple(p for p in unseen if p.value != s.value)
        if differing:
            out[s.id] = Witness(differing, tuple(p for p in every.get(s.cell, []) if knew(s, p)))
        live[s.cell] = unseen + [s]
        every.setdefault(s.cell, []).append(s)
    return out


def real_conflicts(trace: Trace) -> set[int]:
    return set(witnesses(trace))
