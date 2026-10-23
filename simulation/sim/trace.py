"""A term of marking, as the server would receive it (docs/build-plan.md 5.1).

The generator plants edits whose truth is known: which teacher made each one, when it
really happened, what each device's clock said instead, and what each device knew
when it made it. Every resolver is then scored against that truth (truth.py, score.py).

The model, in the order it is built:

1. Each class has one teacher, who marks it. Some teachers also mark on a second device.
2. Each device's clock is off by a fixed amount, anywhere up to `skew` either way. The
   amount is drawn once per device as a fraction of `skew`, so a sweep over `skew` replays
   the same term with only the clocks changed.
3. A device reaches the server now and then: the gaps between its syncs are random, with
   a mean of `offline_mean_days`. At a sync it sends what it has, then pulls.
4. Every cell of every sitting is entered once by its teacher, in one sitting session a few
   days after the exam. Sometimes a cover teacher also enters part of a class's sitting,
   and sometimes disagrees on a value. Sometimes a teacher corrects a cell later: anywhere
   from an hour to two weeks after, spread evenly on a log scale, so a correction the next
   morning is as likely as one the next week.
5. A device that edits one cell twice between syncs sends only the last value, as the
   app's outbox does; the earlier one was the same person changing their own mind.

All times are true times in seconds from the start of term. A device's own claim of the
time is `at`, which is the true time plus its clock's offset, and is the only time a
resolver that trusts device clocks ever sees.
"""

from __future__ import annotations

import bisect
import math
import random
from dataclasses import dataclass

DAY = 86_400.0


@dataclass(frozen=True)
class Scenario:
    classes: int = 4
    students: int = 40
    criteria: int = 5
    assessments: int = 6
    term_days: float = 90.0
    # Entries for a sitting happen within this many days of it.
    marking_days: float = 3.0
    # Share of teachers who also mark on a second device.
    second_device: float = 0.3
    # Chance that a class's sitting also gets entries from a cover teacher.
    cover: float = 0.1
    # Share of that class's cells the cover teacher also enters.
    cover_overlap: float = 0.3
    # Chance that a second person's value for a cell differs from the first.
    disagree: float = 0.3
    # Chance that a cell is corrected later by its own teacher.
    correction: float = 0.05
    # Mean gap between a device's syncs.
    offline_mean_days: float = 2.0
    # Each device's clock is off by a fixed amount up to this, either way, in seconds.
    skew: float = 0.0


@dataclass(frozen=True)
class Device:
    id: str
    teacher: str
    # Its clock minus the true time, in seconds.
    offset: float
    # True times it reached the server, ascending; the last one is after every edit.
    syncs: tuple[float, ...]


@dataclass(frozen=True)
class Edit:
    device: str
    cell: str
    value: int
    time: float


@dataclass(frozen=True)
class Submission:
    """One value for one cell, as the server receives it."""

    id: int
    device: str
    teacher: str
    cell: str
    value: int
    # True time of the edit.
    made: float
    # What the device's clock said when it was made: made plus the device's offset.
    at: float
    # True time the server received it: the device's first sync after the edit.
    received: float
    # True time of the device's last sync before the edit, or -inf if it had none: the
    # device knows everything the server had received by then, and nothing later.
    pulled: float


@dataclass(frozen=True)
class Trace:
    scenario: Scenario
    seed: int
    devices: dict[str, Device]
    # In the order the server received them.
    submissions: tuple[Submission, ...]


def knew(later: Submission, earlier: Submission) -> bool:
    """Whether the device that made `later` had pulled `earlier` before making it."""
    return earlier.received <= later.pulled


def generate(scenario: Scenario, seed: int) -> Trace:
    rng = random.Random(seed)
    # Clocks come from their own stream, so changing `skew` changes nothing else.
    clock_rng = random.Random(f"{seed}:clocks")
    end = (scenario.term_days + scenario.marking_days + 30) * DAY

    teachers = [f"t{c}" for c in range(scenario.classes)]
    devices: dict[str, Device] = {}
    owned: dict[str, list[str]] = {}
    for teacher in teachers:
        names = [f"{teacher}-a"] + ([f"{teacher}-b"] if rng.random() < scenario.second_device else [])
        owned[teacher] = names
        for name in names:
            devices[name] = Device(
                id=name,
                teacher=teacher,
                offset=clock_rng.uniform(-1.0, 1.0) * scenario.skew,
                syncs=_syncs(rng, scenario.offline_mean_days * DAY, end),
            )

    edits = _edits(rng, scenario, teachers, owned)
    return Trace(scenario, seed, devices, _submissions(edits, devices))


def _syncs(rng: random.Random, mean_gap: float, end: float) -> tuple[float, ...]:
    times = []
    t = rng.uniform(0, mean_gap)
    while t < end:
        times.append(t)
        t += rng.expovariate(1 / mean_gap)
    times.append(end)
    return tuple(times)


def _edits(rng: random.Random, s: Scenario, teachers: list[str], owned: dict[str, list[str]]) -> list[Edit]:
    edits: list[Edit] = []

    def other_value(value: int) -> int:
        return rng.choice([v for v in range(21) if v != value])

    for a in range(s.assessments):
        sitting = (a + 0.5) * s.term_days / s.assessments * DAY
        for c, teacher in enumerate(teachers):
            cells = [f"a{a}:c{c}:s{st}:k{k}" for st in range(s.students) for k in range(s.criteria)]
            values: dict[str, int] = {}

            session = sitting + rng.uniform(0, s.marking_days) * DAY
            device = rng.choice(owned[teacher])
            for i, cell in enumerate(cells):
                values[cell] = rng.randint(0, 20)
                edits.append(Edit(device, cell, values[cell], session + i * 30))

            if len(teachers) > 1 and rng.random() < s.cover:
                cover = rng.choice([t for t in teachers if t != teacher])
                cover_device = rng.choice(owned[cover])
                cover_session = sitting + rng.uniform(0, s.marking_days) * DAY
                for i, cell in enumerate(rng.sample(cells, round(len(cells) * s.cover_overlap))):
                    value = other_value(values[cell]) if rng.random() < s.disagree else values[cell]
                    edits.append(Edit(cover_device, cell, value, cover_session + i * 30))

            for cell in cells:
                if rng.random() < s.correction:
                    later = session + math.exp(rng.uniform(math.log(3600), math.log(14 * DAY)))
                    edits.append(Edit(rng.choice(owned[teacher]), cell, other_value(values[cell]), later))
    return edits


def _submissions(edits: list[Edit], devices: dict[str, Device]) -> tuple[Submission, ...]:
    # The last edit per (device, cell, sync): earlier ones never left the device.
    latest: dict[tuple[str, str, float], Edit] = {}
    for edit in sorted(edits, key=lambda e: e.time):
        syncs = devices[edit.device].syncs
        latest[(edit.device, edit.cell, syncs[bisect.bisect_right(syncs, edit.time)])] = edit

    rows = []
    for (device_id, _cell, received), edit in latest.items():
        device = devices[device_id]
        before = bisect.bisect_right(device.syncs, edit.time) - 1
        rows.append((received, device_id, edit.time, edit, device, device.syncs[before] if before >= 0 else float("-inf")))
    rows.sort(key=lambda r: (r[0], r[1], r[2]))

    return tuple(
        Submission(
            id=i,
            device=device.id,
            teacher=device.teacher,
            cell=edit.cell,
            value=edit.value,
            made=edit.time,
            at=edit.time + device.offset,
            received=received,
            pulled=pulled,
        )
        for i, (received, _d, _t, edit, device, pulled) in enumerate(rows)
    )
