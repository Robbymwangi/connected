# 7. Queues run on the database driver; the batch is polled by a self-releasing job

Date: 2026-10-12

## Status

Accepted 2026-10-12. Implemented by the comment generation jobs (#151).

## Context

Two things are queued: comment generation (build-plan 4.3), which submits to the
Anthropic batch API and collects the answers hours later, and report rendering (4.4),
which writes a PDF to S3. Job volume is one comment batch per class per term and one report job per student, a few hundred small jobs per class per term, arriving over days as comments are accepted. Everything a
job needs is already in Postgres, and a job that arrives late costs a teacher a wait,
never a wrong figure.

## Decision

1. **The `database` queue driver.** The `jobs` and `failed_jobs` tables live in the
   Postgres the application already runs and backs up. The worker is the existing Sail
   `queue` service in development and a process on the app host in production.
2. **Dispatch after commit.** A job is dispatched with `afterCommit()`, so a worker
   never reads a row its own transaction has not yet written.
3. **Polling is a job releasing itself, not a scheduler.** `CollectCommentBatch`
   retrieves the batch; if it has not ended it releases itself with backoff and a
   `retryUntil()` of about 26 hours, a little past the batch API's 24 hour expiry. The
   worker's `--tries=3 --timeout=90` governs each attempt, not the wait.
4. **No job holds `SyncLog::transaction` across an HTTP call.** That lock serialises
   every push in the school; a slow upstream would stall them all.
5. **The Anthropic batch API is called through Laravel's HTTP client**, with no SDK.
   The surface is three calls (create, retrieve, stream results), and a new dependency
   would buy typed responses and retries we can write in a few lines. Tests fake it with
   `Http::fake()` and refuse stray requests.

## Considered and rejected

- **Redis.** An in-memory store on a single-instance deployment costs memory and an
  operational component for throughput the system does not need. A queue that loses
  jobs on a restart would also need its own recovery story.
- **A scheduler (`schedule:work`) polling for ended batches.** Sail has no scheduler
  service, and a second moving part to learn for a job that can wait on itself.
- **SQS.** A managed service for the same volume, and a second place to look when a
  job goes missing.

## Consequences

- A job is as durable as the database: a restart resumes it, and `failed_jobs` is a
  table a developer can query.
- Throughput is bounded by Postgres polling. That is fine at this volume and is the
  trade a reader should weigh if a school ever runs thousands of students at once.
- The 26 hour retry window means a stuck batch is visible as a job still released, and
  as a comment still `pending`; failure handling is in `docs/spec/workflow.md`.
