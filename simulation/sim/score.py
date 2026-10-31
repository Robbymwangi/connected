"""How a resolver did against the truth.

    lost        values that vanished with nobody told: overwritten or thrown away while
                still live (truth.py), without a conflict being raised
    reversals   the part of `lost` where a newer edit was thrown away in favour of one
                its own device had already seen: a clock ran ahead, nothing more
    caught      real conflicts the resolver raised
    missed      real conflicts it did not raise
    covered     the part of `missed` that loses nothing: every value the submission
                disagreed with unseen either repeats a value its device had seen in the cell
                (that value was knowingly replaced, whichever device typed it), or had itself
                been raised as a conflict and is waiting for a person
    false_alarm conflicts it raised that were not real
    metadata    the numbers it keeps per cell to decide, on average and at most

A value replaced by someone who had seen it is not lost: that is a correction. Two equal
values are never lost, whichever is kept.
"""

from __future__ import annotations

from dataclasses import dataclass

from .resolvers import Resolver
from .trace import Trace, knew
from .truth import witnesses


@dataclass(frozen=True)
class Score:
    resolver: str
    skew: float
    submissions: int
    real: int
    caught: int
    missed: int
    covered: int
    false_alarm: int
    lost: int
    reversals: int
    metadata_mean: float
    metadata_max: int

    @property
    def lost_rate(self) -> float:
        return self.lost / self.submissions if self.submissions else 0.0

    @property
    def caught_rate(self) -> float:
        return self.caught / self.real if self.real else 1.0


def score(trace: Trace, resolver: Resolver) -> Score:
    real = witnesses(trace)
    by_id = {s.id: s for s in trace.submissions}
    kinds: dict[int, str] = {}
    caught = false_alarm = covered = lost = reversals = 0

    for s in trace.submissions:
        outcome = resolver.receive(s)
        kinds[s.id] = outcome.kind
        prior = by_id[outcome.against] if outcome.against is not None else None

        if outcome.kind == "conflict":
            if s.id in real:
                caught += 1
            else:
                false_alarm += 1
        elif s.id in real:
            w = real[s.id]
            seen = {p.value for p in w.seen}
            if all(kinds[p.id] == "conflict" or p.value in seen for p in w.unseen):
                covered += 1
        if outcome.kind == "applied" and prior is not None and prior.value != s.value and not knew(s, prior):
            # The cell's value was replaced by someone who never saw it.
            lost += 1
        elif outcome.kind == "discarded" and prior is not None and prior.value != s.value:
            lost += 1
            if knew(s, prior):
                reversals += 1

    meta = resolver.metadata()
    return Score(
        resolver=resolver.name,
        skew=trace.scenario.skew,
        submissions=len(trace.submissions),
        real=len(real),
        caught=caught,
        missed=len(real) - caught,
        covered=covered,
        false_alarm=false_alarm,
        lost=lost,
        reversals=reversals,
        metadata_mean=sum(meta) / len(meta) if meta else 0.0,
        metadata_max=max(meta, default=0),
    )
