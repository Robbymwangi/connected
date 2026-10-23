"""How a resolver did against the truth.

    lost        values that vanished with nobody told: overwritten or thrown away while
                still live (truth.py), without a conflict being raised
    reversals   the part of `lost` where a newer edit was thrown away in favour of one
                its own device had already seen: a clock ran ahead, nothing more
    caught      real conflicts the resolver raised
    missed      real conflicts it did not raise
    false_alarm conflicts it raised that were not real

A value replaced by someone who had seen it is not lost: that is a correction. Two equal
values are never lost, whichever is kept.
"""

from __future__ import annotations

from dataclasses import dataclass

from .resolvers import Resolver
from .trace import Trace, knew
from .truth import real_conflicts


@dataclass(frozen=True)
class Score:
    resolver: str
    skew: float
    submissions: int
    real: int
    caught: int
    missed: int
    false_alarm: int
    lost: int
    reversals: int

    @property
    def lost_rate(self) -> float:
        return self.lost / self.submissions if self.submissions else 0.0

    @property
    def caught_rate(self) -> float:
        return self.caught / self.real if self.real else 1.0


def score(trace: Trace, resolver: Resolver) -> Score:
    real = real_conflicts(trace)
    by_id = {s.id: s for s in trace.submissions}
    caught = false_alarm = lost = reversals = 0

    for s in trace.submissions:
        outcome = resolver.receive(s)
        prior = by_id[outcome.against] if outcome.against is not None else None

        if outcome.kind == "conflict":
            if s.id in real:
                caught += 1
            else:
                false_alarm += 1
        elif outcome.kind == "applied" and prior is not None and prior.value != s.value and not knew(s, prior):
            # The cell's value was replaced by someone who never saw it.
            lost += 1
        elif outcome.kind == "discarded" and prior is not None and prior.value != s.value:
            lost += 1
            if knew(s, prior):
                reversals += 1

    return Score(
        resolver=resolver.name,
        skew=trace.scenario.skew,
        submissions=len(trace.submissions),
        real=len(real),
        caught=caught,
        missed=len(real) - caught,
        false_alarm=false_alarm,
        lost=lost,
        reversals=reversals,
    )
