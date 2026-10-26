"""Which submissions are real conflicts, decided with knowledge no server has.

A submission is a real conflict when it arrives while the cell holds a value from
someone its device never saw, and the two values differ: two people each entered a
number without seeing the other's, and only a person can say which is right.

"Never saw" is decided from true times (trace.knew): the device had not synced since
the other value reached the server. No device clock and no version number is read
here, so this is the truth every resolver is scored against, not one of them.

The cell's live values are the ones nobody has knowingly replaced yet. A submission
replaces every live value its device had seen and joins the rest.
"""

from __future__ import annotations

from .trace import Submission, Trace, knew


def real_conflicts(trace: Trace) -> set[int]:
    live: dict[str, list[Submission]] = {}
    conflicts: set[int] = set()
    for s in trace.submissions:
        unseen = [p for p in live.get(s.cell, []) if not knew(s, p)]
        if any(p.value != s.value for p in unseen):
            conflicts.add(s.id)
        live[s.cell] = unseen + [s]
    return conflicts
