"""The sweep's charts (docs/build-plan.md 5.1, step 3), drawn from results/summary.csv.

    pip install -r requirements-plots.txt     # matplotlib, for this file only
    python3 -m sim.plots                      # after python3 -m sim.sweep

One PNG per measure and term, with clock error along the bottom on a log scale and each
resolver's mean over the seeds as a line inside its 95% interval. "No clock error" sits at the
left edge, since zero has no place on a log scale.
"""

from __future__ import annotations

import argparse
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402
from matplotlib.ticker import PercentFormatter  # noqa: E402

from .resolvers import LastWriterWins, ServerVersions, VectorClocks  # noqa: E402
from .sweep import RESOLVERS, SCENARIOS, SKEWS, Summary, read_summary  # noqa: E402

# Distinct in print and for colour-blind readers; each resolver also has its own marker.
# Vector clocks are dashed, since they often lie on top of this system's line.
STYLE = {
    LastWriterWins.name: ("#c1440e", "o", "-"),
    ServerVersions.name: ("#1f5fbf", "s", "-"),
    VectorClocks.name: ("#555555", "^", "--"),
}

CHARTS = {
    "lost": "Values lost with nobody told, per submission",
    "reversed": "Corrections thrown away for an older value, per submission",
    "caught": "Real conflicts raised for a person",
}


def chart(summary: list[Summary], scenario: str, rate: str, path: Path) -> None:
    # Zero clock error is drawn at a tenth of the smallest other value, labelled "none".
    smallest = min(s for s, _ in SKEWS if s > 0)
    x_of = lambda skew: skew if skew > 0 else smallest / 10

    fig, ax = plt.subplots(figsize=(6.4, 3.6), dpi=200)
    for R in RESOLVERS:
        points = sorted((s for s in summary if s.scenario == scenario and s.rate == rate and s.resolver == R.name), key=lambda s: s.skew)
        if not points:
            continue
        colour, marker, line = STYLE[R.name]
        xs = [x_of(p.skew) for p in points]
        ax.plot(xs, [p.mean for p in points], color=colour, marker=marker, markersize=4, linewidth=1.5, linestyle=line, label=R.name)
        ax.fill_between(xs, [p.mean - p.half_width for p in points], [p.mean + p.half_width for p in points], color=colour, alpha=0.15, linewidth=0)

    ax.set_xscale("log")
    ax.set_xticks([x_of(s) for s, _ in SKEWS], [label for _, label in SKEWS], rotation=35, ha="right", fontsize=8)
    ax.minorticks_off()
    ax.yaxis.set_major_formatter(PercentFormatter(1.0, decimals=1 if rate != "caught" else 0))
    ax.set_xlabel("Device clock error, up to", fontsize=9)
    ax.set_title(f"{CHARTS[rate]} ({scenario} term)", fontsize=10)
    ax.grid(True, axis="y", alpha=0.3)
    ax.legend(fontsize=8, frameon=False)
    fig.tight_layout()
    path.parent.mkdir(parents=True, exist_ok=True)
    fig.savefig(path)
    plt.close(fig)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--results", type=Path, default=Path("results"))
    args = parser.parse_args()

    summary = read_summary(args.results / "summary.csv")
    for scenario in SCENARIOS:
        for rate in CHARTS:
            path = args.results / f"{rate}-{scenario}.png"
            chart(summary, scenario, rate, path)
            print(path)


if __name__ == "__main__":
    main()
