# Threat model

Status: the data flow diagrams and the personal data inventory are drafted, and the
STRIDE pass is done for every diagram: 92 threats, 60 mitigated and 32 open. The
LINDDUN pass is still to come.

`threat-model.json` is an [OWASP Threat Dragon](https://owasp.org/www-project-threat-dragon/)
model (format 2.x). Threat Dragon reads and writes this file directly, so it is the model
itself and not an export of one. Changes go through a merge request like any other file.

## Getting the report

The report (cover sheet, each diagram, and a table of threats for every element) is
produced by Threat Dragon from this file:

1. Install the Threat Dragon desktop app from its
   [releases page](https://github.com/OWASP/threat-dragon/releases).
2. Open `docs/security/threat-model.json` ("Open an existing threat model", then the
   local file).
3. Choose "Report", tick the options wanted ("Show out of scope elements" off keeps the
   tables short), and save it as a PDF.

Without installing anything, the web app runs from its official image (the model was
checked this way, against Threat Dragon 2.6.2):

```bash
docker run --rm -p 3000:3000 \
  -e ENCRYPTION_JWT_SIGNING_KEY=$(openssl rand -hex 32) \
  -e ENCRYPTION_JWT_REFRESH_SIGNING_KEY=$(openssl rand -hex 32) \
  -e ENCRYPTION_KEYS='[{"isPrimary":true,"id":0,"value":"'$(openssl rand -hex 16)'"}]' \
  -e NODE_ENV=development -e SERVER_API_PROTOCOL=http \
  threatdragon/owasp-threat-dragon:stable
```

Then open `http://localhost:3000`, choose "Login to Local Session", "Open an existing
threat model", paste the file's contents, and choose "Import". "Report" then "Print"
gives the PDF. A local session keeps the model in the browser; nothing is uploaded.

Each threat table has the columns Number, Title, Type, Severity, Status, Score,
Description, and Mitigations. The tables are empty until step 2 of the plan below is
done.

## The plan

1. Agree the data flow diagrams and this inventory. Done.
2. STRIDE over the diagrams, weighted towards the sync layer. Each threat marked
   mitigated cites the ADR, spec section, or test that mitigates it. **Done.**
3. LINDDUN over the same diagrams, for personal data.
4. A risk register, with each open threat becoming an issue for the hardening pass
   (`docs/build-plan.md` 5.3).

## How threats are scored

Each threat carries a CVSS 3.1 base score in the Score column, with its vector at the
end of the description, and the severity is the score's standard band (Low below 4.0,
Medium below 7.0, High below 9.0, Critical from 9.0). The score describes the threat as
if nothing stopped it; the Status column says whether something does. A mitigated
threat with a high score is therefore a control worth being able to explain, not a
problem.

Nothing is changed in the code by this analysis. An open threat is a finding for the
hardening pass, where each becomes an issue.

## The diagrams

Five small diagrams rather than one large one, so that flows do not cross:

| Diagram | Shows |
| --- | --- |
| 0. Context | Who and what the system talks to. A summary; carries no threats. |
| 1. Teacher device and sync | The local store, outbox, sync runner, Sync API, and sync logs: the project's contribution. |
| 2. API, accounts, and back office | CloudFront, nginx, login and tokens, the online routes, and the admin panel. |
| 3. Reports, comments, and the model provider | The assistant, the queue worker, report card PDFs, and what is sent to the model. |
| 4. Deployment | How code and settings reach production. |

Three conventions hold across them:

- Each element is modelled, and carries its threats, on exactly one diagram. Where it
  appears on another it is dashed and marked out of scope, with a pointer to its home
  diagram, so no threat is recorded twice.
- The diagrams are of Threat Dragon's Generic type, which accepts both STRIDE and
  LINDDUN threats on the same element. One set of diagrams serves both methods.
- The institution boundary is logical and is not drawn: every institution shares one
  database and one API, separated by `institution_id` under the Eloquent global scope.

Production is modelled as declared in `infra/` (ADR 0015), although it is currently
taken down.

Not on the diagrams, on purpose:

- Parents and students. They are data subjects, not users; a report card reaches them
  on paper or forwarded by a teacher, outside the system.
- Terraform's own plan and apply against the AWS control plane. The GitHub Actions
  element covers both the deploy and the infrastructure workflows, which share a guard
  (only a run on `main` can assume the apply role).
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

## Assumptions

- No admission number is stored. `students` has no such column today; if one is added
  later it joins the first row of the inventory and the model is revisited.
