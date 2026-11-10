# Threat model

Status: first draft of the data flow diagram and the personal data inventory, for
review. No threats are recorded yet.

`threat-model.json` is an [OWASP Threat Dragon](https://owasp.org/www-project-threat-dragon/)
model (format 2.x). Open it in the Threat Dragon desktop app, or in the web app with
"Open an existing threat model" and a local file. It is edited there from now on, and
changes go through a merge request like any other file.

## The plan

1. Agree the data flow diagram and this inventory. **This step.**
2. STRIDE over the diagram, weighted towards the sync layer. Each threat marked
   mitigated cites the ADR, spec section, or test that mitigates it.
3. LINDDUN over a copy of the same diagram, for personal data.
4. A risk register, with each open threat becoming an issue for the hardening pass
   (`docs/build-plan.md` 5.3).

## What the diagram shows

One level 1 diagram with three trust boundaries: the teacher's device, the AWS account,
and the application host inside it. The institution boundary is logical and is not
drawn: every institution shares one database and one API, separated by `institution_id`
under the Eloquent global scope.

Production is modelled as declared in `infra/` (ADR 0015), although it is currently
taken down.

Not on the diagram, on purpose:

- Parents and students. They are data subjects, not users; a report card reaches them
  on paper or forwarded by a teacher, outside the system.
- Terraform's own plan and apply against the AWS control plane. The deploy workflow is
  drawn; the infrastructure workflow shares its actor and its guard (only a run on
  `main` can assume the apply role).
- Local development (Sail, RustFS) and the simulation in `simulation/`.

## Personal data inventory

| Data | Subject | Server | Teacher device | Leaves the school |
| --- | --- | --- | --- | --- |
| Name, gender, date of birth | Student | `students` | Yes, every student of the institution | Name on the report card PDF only |
| Class and year | Student | `enrolments` | Yes | Stream and history on the report card |
| Marks, absences, results, and levels | Student | `marks`, `results` | Yes | Levels on the report card; levels and trends to the model for comments; figures including percentages to the model for the assistant, under a handle |
| Report card remark | Student | `comments` | Yes | On the report card |
| Report card PDF | Student | S3, `reports` | On request, by presigned URL | To the family, outside the system |
| Name and email | Teacher, admin | `users` | Teachers' names and emails | No |
| Password hash, authenticator secret, and recovery codes | Teacher, admin | `users` | No | No |
| Device token | Teacher | `personal_access_tokens` (hashed) | Yes, in the `sessions` store | No |
| Who edited, proposed, resolved, unlocked, or closed, and when | Teacher | `marks.last_edited_by`, `conflicts`, `unlock_notes`, `term_closures`, `sync_mutations` | Yes, except `unlock_notes` and `sync_mutations` | No |
| Questions typed to the assistant | Teacher, and any student named in one | Cache table, short expiry, in handle form | No | To the model; a name in lower case or off the roster is not masked (ADR 0013, decision 4) |
| Every changed field of every record, kept indefinitely | Student, teacher | `sync_changes` | Applied to the local copy | No |

Two properties of the design matter for the privacy pass and are recorded here so they
are not rediscovered:

- Read access is institution-wide by design (`docs/spec/access-model.md`), so each
  teacher's device holds the whole school's student data, not only that teacher's
  classes.
- Rows are soft deleted and the change log is append-only, so nothing a device has
  synced is ever erased on the server today.

## Open questions for the review

- Is anything missing from the diagram: an external party, a store, or a flow?
- Is the admission number stored anywhere? The specs mention it only as something
  never sent to the model, and `students` has no such column.
- Should the infrastructure workflow have its own element, or does the deploy actor
  cover it?
