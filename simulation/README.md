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
- **Server versions (this system).** The rules of `docs/spec/sync-protocol.md`, POST /sync
  rules 2 to 4, for a mark. The server keeps one integer per cell and raises it only when
  the value changes. A device sends the version it last pulled with its edit: at the
  current version the edit is applied; behind it with the same value nothing changes (the
  "auto" conflict, which needs nobody); behind it with a different value it is a conflict,
  the cell keeps its value, and a person decides. Two offline creates of one cell meet the
  same way, since both are sent at version 0.
- **Vector clocks.** The textbook alternative with no central counter. Every value carries
  one counter per device that has edited the cell; a device's edit takes the merge of every
  vector it pulled and adds one to its own. A value that covers another replaces it;
  concurrent values that differ are a conflict, kept side by side as siblings (as Dynamo
  does) until a person settles them.

What a device sends is never read from a clock or a true time. Each resolver keeps a
history of what it held for each cell, and the device's base version or vector is that
history read back at the device's last sync: exactly what it pulled.

A mark's kind and score move together, so a cell here is one field, and the field-level
merge of rule 4 never arises: a stale base always overlaps. That merge matters for other
tables; this simulation does not test it.

## What is measured (`sim/score.py`)

- **Lost:** values that vanished with nobody told. A real conflict settled silently loses
  one value; so does a correction thrown away because the value it corrected carries a
  clock that ran ahead (a **reversal**).
- **Caught, missed, false alarms:** real conflicts raised, real conflicts not raised, and
  conflicts raised that were not real.
- **Covered:** the part of "missed" that loses nothing. Every value the edit disagreed with
  unseen either repeats a value its device had seen in that cell (typed again by someone
  else; the value itself was knowingly replaced), or was already raised as a conflict and
  is waiting for a person. Nobody settles conflicts in the simulation, so a raised conflict
  stays waiting.
- **Metadata:** how many numbers each resolver keeps per cell to decide, on average and at
  most. A vector's entries are each a device identity and a counter, so this undercounts
  its size.

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

## Second result: this system and vector clocks

The same term, seed 1, now with all three resolvers. Neither of the new two reads a clock,
and their figures are identical at every clock error, so one row each stands for all four
(a test holds them to that):

| Clock error up to | Resolver | Real conflicts caught | Missed (covered) | Lost | Reversals | Numbers per cell (most) |
|---|---|---|---|---|---|---|
| any | Server versions (this system) | 173 of 175 | 2 (2) | 0 | 0 | 1 |
| any | Vector clocks | 175 of 175 | 0 | 0 | 0 | 1.09 (3) |
| none | Last writer wins | 0 of 175 | 175 | 173 | 0 | 1 |
| 1 week | Last writer wins | 0 of 175 | 175 | 181 | 7 | 1 |

A busier term (half of sittings get a cover teacher, one cell in five corrected) gives the
same picture: this system catches 377 of 383, misses 6, all covered, and loses nothing;
vector clocks catch all 383; last writer wins loses 382, and 415 with clocks a week out.

**What this shows.** Both designs that never trust a clock lose nothing at any clock error,
and raise no false alarms. Vector clocks catch every real conflict; this system catches
all but a few, and every one it misses is covered: either the edit corrects a value its
device had already seen, typed again by someone else, which a server version rightly
treats as no change at all, or the value it disagrees with is already in an open conflict
a person has to settle. Vector clocks raise those as further conflicts; this system does
not add a second question about a value it is already asking about.

So a single server-incremented version does what vector clocks do here, with one number
per cell instead of a counter per device that touched it, because every write passes
through one server, which can put them in one order. Vector clocks earn their size where
there is no such server. The case for ADR 0006 is not only that clocks are wrong; it is
that a timestamp can only pick a winner, while a version can tell that two people
disagreed. Final figures, over many seeds and clock errors, come with the sweep (step 3).
