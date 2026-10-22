# Contributing

How the two of us (and any coding agent we point at the repository) work. The rules
of the code itself, the data rules above all, are in [`AGENTS.md`](AGENTS.md); this
file is about the workflow around it.

## Setting up

[`README.md`](README.md) has the full guide, including the Windows and WSL2 notes.
The short version: Docker, Node 22, `make`, then `make setup`.

## The board

Work is tracked on the GitHub Project board, kanban style: pick the next ticket you
can do, move it to In progress, branch, and open the merge request with `Closes #N`
in its body so the ticket closes on merge. Milestones are the dated states in
[`docs/build-plan.md`](docs/build-plan.md); tickets carry that document's five
fields (Task, Read first, Constraints, Done when, Do not) so an item can be pasted
into an agent session verbatim. Nobody is assigned work; the board shows who took
what.

## Branches and merge requests

- Branch from `main` as `feat/`, `fix/`, `docs/`, or `chore/` followed by a short
  slug: `feat/marking-grid`, `chore/dev-containers`.
- One merge request per completed piece of work, squash merged into `main`, so `main`
  reads as one commit per work item.
- Do not stack merge requests. Wait for the base to merge, then branch again from
  `main`. A merge request opened against an already-merged branch never reaches
  `main` and its commits have to be carried by hand.
- The description is written as if defending the work to a senior: the decisions
  made, the patterns used, and the rationale with sources (documentation links,
  specification references, file paths). Keep it high level: the overview and the
  three or so steps that matter, not every detail. This doubles as rehearsal for the
  oral examination, where every part of the system must be explained.
- "Merge request", not "pull request", even though GitHub calls it the latter.

## Before a commit

1. `make check`: frontend typecheck, lint, and Vitest, then the API's Pest suite on
   Postgres. All of it must pass. The type check is not optional; the original
   Figma export contained syntax errors that the bundler stripped silently.
   Also ask: does this change leave existing dev databases wrong (an edited or removed
   migration, new seed data, a new rule about what the sync log must contain)? If so,
   bump `api/database/DEV_DATA_EPOCH` before you commit, and say so in the merge request
   (see "When a change needs everyone's dev database rebuilt" below).
2. `coderabbit review --uncommitted -c AGENTS.md`, then address or explicitly decline
   each finding. Local and merge request reviews share one quota, so review in
   sweeps: one local pass per finished chunk, then let the bot on the merge request
   confirm. The bot comments; it does not block.
3. Commit with a message whose first line says what changed and why in one sentence.

## What a merge sets off

While production is up, merging is releasing (ADR 0015):

- Every merge to `main` deploys, once `check` has passed on it: the API first, then
  the PWA. Migrations must stay additive, because devices on the previous frontend keep
  talking to the new API until they reload.
- A merge request that touches `infra/production/` or the `infra` workflow gets a
  Terraform plan posted as a comment; merging it applies the change to production.
  Read the plan before approving.

While production is taken down, the **deploy** and **infra** workflows are disabled in
GitHub, so merges release nothing. Leave them disabled until production is brought back
([`infra/README.md`](infra/README.md)); enabling **infra** with production gone would
rebuild it on the next merge that touches `infra/production/`.

## When a change needs everyone's dev database rebuilt

A non-additive migration, new seed data, or a change to what the sync log must contain
leaves existing dev databases wrong in ways a plain `migrate` does not fix. Do not rely
on telling people. In the same merge request, bump the number on line 1 of
`api/database/DEV_DATA_EPOCH` and put the reason on line 2. `make up` and `make setup`
compare it with the epoch recorded on each machine and run `migrate:fresh --seed` when
they differ, saying why. Set `SKIP_DEV_RESET=1` to defer it; `make api-fresh` does it on
demand. Additive migrations do not need a bump. CI enforces this: the `epoch` check fails a
merge request that edits or deletes an existing migration, or touches a seeder, without
bumping the epoch. A change that really needs none puts `Dev-data-epoch: not needed` on a line
of its own in its description.

## Architecture decision records

A decision goes in `docs/adr/` when it is made, not after, as `NNNN-short-slug.md`
with Status, Context, Decision, and Consequences. Write one when a choice would need
defending at the examination, when it reverses an earlier one (ADR 0004 reversed a
hand-written service worker to Workbox and says why), or when a future contributor
would otherwise reopen the question. A development environment choice is not an ADR;
it belongs in the README.

The Status line says where the decision stands:

- **Proposed** while it is under discussion or not yet built.
- **Accepted**, with the date, once the merge request that implements it merges. Name
  that merge request.
- When a later ADR changes a decision, the earlier ADR's Status names the later one and
  which of its decisions it replaces ("Decisions 3 and 4 superseded by ADR 0014"). The
  earlier text stays as written: it is the record of what was decided then and why.

## What is ours and what is a dependency

The examination scrutinises the project's contribution, which is the offline
synchronisation layer: the local store and outbox, the version check, field-level
merge, and the conflict flow under ADRs 0001 and 0002, and the server side that
accepts or rejects a write. Its logic and parameters are our own implementation.
Libraries underneath it are fine; a managed service or framework that owns that
behaviour is not.

Everything else (service worker, containers, tooling, test runners) may use
maintained dependencies freely. Hand-rolling infrastructure is not a virtue here; it
cost real time once already.
