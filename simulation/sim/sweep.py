"""The sweep (docs/build-plan.md 5.1, step 3): every resolver, over many seeds, as device
clocks get worse.

For each term (`SCENARIOS`) and each seed, the same term is replayed at every clock error in
`SKEWS`; only the clocks change between them (trace.py). Every resolver is scored on every
replay. `run` gives one row per score, `summarize` the mean over seeds with a 95% interval.

    python3 -m sim.sweep                  # writes results/sweep.csv and results/summary.csv
    python3 -m sim.sweep --seeds 5        # a quicker look

Standard library only; plots.py draws the charts from summary.csv.
"""

from __future__ import annotations

import argparse
import csv
import math
import statistics
from dataclasses import asdict, dataclass, fields, replace
from pathlib import Path
from typing import Callable

from .resolvers import LastWriterWins, ServerVersions, VectorClocks
from .score import Score, score
from .trace import DAY, Scenario, generate

HOUR = 3600.0
# Clock error up to, in seconds, and how a table or chart names it.
SKEWS: tuple[tuple[float, str], ...] = (
    (0.0, "none"),
    (60.0, "1 minute"),
    (600.0, "10 minutes"),
    (HOUR, "1 hour"),
    (6 * HOUR, "6 hours"),
    (DAY, "1 day"),
    (3 * DAY, "3 days"),
    (7 * DAY, "1 week"),
    (14 * DAY, "2 weeks"),
)
SCENARIOS: dict[str, Scenario] = {
    "default": Scenario(),
    # Half of sittings get a cover teacher, and one cell in five is corrected later.
    "busy": Scenario(cover=0.5, correction=0.2),
}
RESOLVERS = (LastWriterWins, ServerVersions, VectorClocks)
SEEDS = 30


@dataclass(frozen=True)
class Row:
    scenario: str
    seed: int
    skew: float
    resolver: str
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


def run(scenarios: dict[str, Scenario], skews: list[float], seeds: range) -> list[Row]:
    rows = []
    for name, scenario in scenarios.items():
        for seed in seeds:
            for skew in skews:
                trace = generate(replace(scenario, skew=skew), seed)
                for resolver in RESOLVERS:
                    rows.append(_row(name, seed, score(trace, resolver())))
    return rows


def _row(scenario: str, seed: int, s: Score) -> Row:
    return Row(scenario, seed, **{f.name: getattr(s, f.name) for f in fields(Score)})


# The rates the summary reports, each from one row. A run with no real conflicts says
# nothing about catching them, so the two rates over real conflicts leave it out (None).
RATES: dict[str, Callable[[Row], float | None]] = {
    # Values that vanished with nobody told, per submission.
    "lost": lambda r: r.lost / r.submissions,
    # Corrections thrown away for an older value, per submission.
    "reversed": lambda r: r.reversals / r.submissions,
    # Real conflicts raised for a person.
    "caught": lambda r: r.caught / r.real if r.real else None,
    # Real conflicts neither raised nor covered: the misses that can lose something.
    "uncovered": lambda r: (r.missed - r.covered) / r.real if r.real else None,
    # Conflicts raised that were not real, per submission.
    "false_alarm": lambda r: r.false_alarm / r.submissions,
}


@dataclass(frozen=True)
class Summary:
    scenario: str
    skew: float
    resolver: str
    # The seeds the rate is over: fewer than were run when some had no real conflicts.
    seeds: int
    rate: str
    mean: float
    # Half the width of a 95% interval for the mean, from the spread across seeds.
    half_width: float


def summarize(rows: list[Row]) -> list[Summary]:
    groups: dict[tuple[str, float, str], list[Row]] = {}
    for r in rows:
        groups.setdefault((r.scenario, r.skew, r.resolver), []).append(r)
    out = []
    for (scenario, skew, resolver), group in groups.items():
        for rate, f in RATES.items():
            values = [v for v in map(f, group) if v is not None]
            if not values:
                continue
            out.append(Summary(scenario, skew, resolver, len(values), rate, statistics.fmean(values), _half_width(values)))
    return out


def _half_width(values: list[float]) -> float:
    """1.96 standard errors: a normal approximation, fair for 30 seeds."""
    if len(values) < 2:
        return 0.0
    return 1.96 * statistics.stdev(values) / math.sqrt(len(values))


def write_csv(path: Path, items: list) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=[x.name for x in fields(items[0])])
        writer.writeheader()
        writer.writerows(asdict(x) for x in items)


def read_summary(path: Path) -> list[Summary]:
    with path.open(newline="") as f:
        return [
            Summary(r["scenario"], float(r["skew"]), r["resolver"], int(r["seeds"]), r["rate"], float(r["mean"]), float(r["half_width"]))
            for r in csv.DictReader(f)
        ]


def table(summary: list[Summary], scenario: str, rate: str) -> str:
    """A Markdown table: one row per clock error, one column per resolver, as percentages."""
    names = [R.name for R in RESOLVERS]
    cell = {(s.skew, s.resolver): s for s in summary if s.scenario == scenario and s.rate == rate}
    lines = ["| Clock error up to | " + " | ".join(names) + " |", "|---" * (len(names) + 1) + "|"]
    for skew, label in SKEWS:
        if any((skew, n) in cell for n in names):
            values = [f"{cell[skew, n].mean:.2%} ± {cell[skew, n].half_width:.2%}" if (skew, n) in cell else "" for n in names]
            lines.append(f"| {label} | " + " | ".join(values) + " |")
    return "\n".join(lines)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--seeds", type=int, default=SEEDS)
    parser.add_argument("--out", type=Path, default=Path("results"))
    args = parser.parse_args()

    rows = run(SCENARIOS, [s for s, _ in SKEWS], range(args.seeds))
    summary = summarize(rows)
    write_csv(args.out / "sweep.csv", rows)
    write_csv(args.out / "summary.csv", summary)
    for scenario in SCENARIOS:
        for rate in ("lost", "reversed", "caught"):
            print(f"\n{scenario} term, {rate}, mean over {args.seeds} seeds ± 95% interval\n")
            print(table(summary, scenario, rate))


if __name__ == "__main__":
    main()
