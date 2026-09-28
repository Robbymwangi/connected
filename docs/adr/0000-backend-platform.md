# 0. Laravel and PostgreSQL over Amplify Gen 1

Date: 2026-09-14

## Status

Accepted.

## Context

ConnectED began on AWS Amplify Gen 1: AppSync for the API, DynamoDB for
storage, Cognito for authentication, DataStore for the client-side sync
layer, and three Lambda functions for the server-side logic that didn't fit
that shape. The move to a Laravel API on PostgreSQL happened before the
frontend port started; by the time that port began, on 14 September, the
API was already a Laravel skeleton, and #14 later put it on Postgres in
place of the SQLite the skeleton shipped with.

Two arguments carried this decision, and only two. A third, who owns the
offline synchronisation layer, sounds like it belongs here and doesn't:
DataStore was optional on Amplify, not the only way to sync on that
platform, so its absence isn't evidence against the platform itself. That
question is ADR 0006's, decided on its own terms.

## Decision

Move to Laravel on PostgreSQL, Sanctum for authentication, and Laravel's
own queues, for two reasons.

1. **The domain is relational.** Every figure the Reports screen shows
   (`docs/spec/analytics.md`, #38) is a grouped aggregate over marks: pass
   rate, mean, the criterion breakdown, the histogram, all of them `GROUP
   BY` and `AVG` over rows in one table, not a traversal of a document
   graph. Conflict detection is a version comparison on one row (ADR 0001),
   not a distributed structure. A document store with a resolver layer on
   top reconstructs, by hand, relations a relational schema already gives
   for free; and DynamoDB's access patterns are designed around a
   partition key chosen in advance, while this domain is read by class, by
   subject, by student, and by term, patterns that don't reduce to one key.
2. **Auth, authorisation, and the three Lambdas collapse into one readable
   codebase.** Sanctum tokens, Eloquent policies, and queued jobs replace
   Cognito, custom resolvers, and three separate functions, each configured
   through a console rather than written and reviewed as code. The system
   can be read top to bottom and defended line by line at the oral
   examination, which four managed components, each doing part of the job,
   cannot be in the same way.

## Consequences

- The frontend and the API are two separate applications sharing one
  repository, not a single Amplify-generated client; AGENTS.md's "Not
  Inertia" rule and the API's JSON-only surface both follow from this.
- Multi-tenancy is an Eloquent global scope on `institution_id`, not
  Cognito user pools or groups.
- The offline synchronisation layer has to be written, since nothing in
  this stack provides one the way DataStore did on Amplify. That's ADR
  0006's decision; this ADR is only responsible for having created the
  need for it.
- Deployment is one EC2 host plus RDS and S3 (`docs/build-plan.md` item
  5.2), not a serverless graph of managed services triggering one another.
- Argument 2 is about managed components that replace application logic
  being readable and defensible as code, not about managed infrastructure
  as such: RDS and S3 remain managed pieces underneath a system that is,
  itself, written rather than configured. The objection was never to a
  managed database or a managed bucket; it was to Cognito, AppSync's
  resolvers, and Lambda standing in for code this project needs to be able
  to explain.
