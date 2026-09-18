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
2. `coderabbit review --uncommitted -c AGENTS.md`, then address or explicitly decline
   each finding. Local and merge request reviews share one quota, so review in
   sweeps: one local pass per finished chunk, then let the bot on the merge request
   confirm. The bot comments; it does not block.
3. Commit with a message whose first line says what changed and why in one sentence.

## Writing

- No em dashes in prose or comments; use semicolons, conjunctions, or colons.
- Oxford comma.
- Comments explain why, not what, and match the density of the code around them.

## Architecture decision records

A decision goes in `docs/adr/` when it is made, not after, as `NNNN-short-slug.md`
with Status, Context, Decision, and Consequences. Write one when a choice would need
defending at the examination, when it reverses an earlier one (ADR 0004 reversed a
hand-written service worker to Workbox and says why), or when a future contributor
would otherwise reopen the question. A development environment choice is not an ADR;
it belongs in the README.

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
