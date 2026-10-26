# The distributed-systems simulation

`docs/build-plan.md` item 5.1, and the evidence ADR 0006 cites: what each way of
settling concurrent edits loses, measured against a term of marking whose truth is
known, as device clocks get worse.

Standalone and outside the application. Standard-library Python 3.11 or later; only the
charts (a later step) use matplotlib.

```sh
make sim-test      # the tests, from the repository root
```

## The question

A server receives marks from devices that were offline for days and whose clocks are
wrong by some amount. When two values meet in one cell, it must decide. Does trusting
device clocks lose marks, and how many, as the clocks get worse?

## The model (`sim/trace.py`)

A term for one small school: by default 4 classes of 40 students, 5 criteria per
assessment, 6 assessments, so 4,800 cells.

1. **Teachers and devices.** Each class has one teacher, who marks it. Three in ten
   teachers also mark on a second device.
2. **Clocks.** Each device's clock is off by a fixed amount, anywhere up to `skew` either
   way. The sweep raises `skew` and replays the *same* term with only the clocks changed,
   so any difference in the results is the clocks' doing.
3. **Connectivity.** A device reaches the server now and then, on average every two days.
   At a sync it sends what it has, then pulls. Between syncs it knows nothing new.
4. **Edits.** Every cell is entered once by its teacher, a few days after the sitting.
   One class sitting in ten also gets entries from a cover teacher on a third of its
   cells, who disagrees with the teacher on three cells in ten. One cell in twenty is
   corrected later by its own teacher, anywhere from an hour to two weeks after.
5. **The outbox.** A device that edits one cell twice between syncs sends only the last
   value, as the app does.

Every number above is a parameter of `Scenario`, and every run takes a seed, so a result
can be reproduced exactly.

## The truth (`sim/truth.py`)

The generator knows what no server can: the true time of every edit, and what each
device had pulled when it made it. From that alone (no device clock, no version) it
decides which arrivals are **real conflicts**: a value arriving while the cell holds a
different value from someone that device never saw. Only a person can say which is
right. A value replaced by someone who *had* seen it is a correction, not a conflict.

## The resolvers (`sim/resolvers.py`)

- **Last writer wins (device clocks).** Keeps whichever value carries the later device
  timestamp. Never raises a conflict.
- *Next step:* this system's own rules (server versions, `docs/spec/sync-protocol.md`)
  and vector clocks.

## What is measured (`sim/score.py`)

- **Lost:** values that vanished with nobody told. A real conflict settled silently loses
  one value; so does a correction thrown away because the value it corrected carries a
  clock that ran ahead (a **reversal**).
- **Caught, missed, false alarms:** real conflicts raised, real conflicts not raised, and
  conflicts raised that were not real.

## First result, and what it changes

Last writer wins, one seed, the default term:

| Clock error up to | Lost | Of which reversals |
|---|---|---|
| none | 173 of 5,296 (3.3%) | 0 |
| 1 hour | 173 (3.3%) | 0 |
| 1 day | 175 (3.3%) | 2 |
| 1 week | 181 (3.4%) | 7 |

The loss does not start with bad clocks. **With perfect clocks, last writer wins already
loses every real conflict, silently**: two teachers' different marks for one cell, and one
simply disappears. Clock error adds reversals only when two edits to a cell are closer
together than the clocks are wrong, and in a term of marking they are usually days
apart. So the case against device timestamps here is less "bad clocks reorder edits"
than "a timestamp can only ever pick a winner; it can never tell you that two people
disagreed". Final figures, over many seeds, come with the sweep.
