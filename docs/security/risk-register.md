# Risk register

Every open threat in `threat-model.json`, grouped into the pieces of work that close it. Generated from the model, so threat numbers, titles, and scores match the Threat Dragon report.

48 open threats: 39 in 19 work items, and 9 recommended for acceptance. Accepting a risk is the team's decision, so those stay Open in the model until it is made.

## How to read it

- **Severity** is the highest of the item's threats. STRIDE threats are scored with CVSS 3.1 and LINDDUN threats by likelihood times impact (see README), so the Score column shows which.
- **Effort:** S is a day or less, M a few days, L more than a week or dependent on a decision.
- **Priority:** 1, before a real school's data is loaded; 2, before the pilot ends; 3, when convenient.
- **Note** flags work that AGENTS.md says to propose and wait on (Tier 2 auth, Tier 3 sync layer), or that needs `DEV_DATA_EPOCH` bumped.

## Work items

| Item | Work | Closes | Severity | Kind | Effort | Priority | Note |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R1 | Pin third-party actions and audit dependencies | 80, 81 | Critical | CI | S | 1 |  |
| R2 | Give the deploy its own narrow role | 79 | High | Infrastructure | S | 1 |  |
| R3 | Verify the release archive on the host | 84 | High | CI | S | 1 |  |
| R4 | Encrypt the leg from CloudFront to the host | 55 | High | Infrastructure | M | 1 |  |
| R8 | Device tokens that expire | 6, 2 | High | Code | M | 1 | Tier 2 (auth): propose and wait. |
| R17 | Settle the legal questions | 101, 106, 102, 103, 71 | High | Decision | M | 1 | May change ADRs 0013 and 0015. |
| R7 | Security headers at CloudFront | 4, 33, 39 | Medium | Infrastructure | S | 1 |  |
| R10 | Narrow the plan role | 82 | Medium | Infrastructure | S | 1 |  |
| R6 | A per-user API rate limit | 25, 48, 66 | Medium | Code | S | 1 |  |
| R13 | Make the change log append-only | 29 | Medium | Code | S | 1 | Sync layer: Tier 3, propose and wait. |
| R5 | Rate-limit at the edge | 37 | High | Infrastructure | S | 2 |  |
| R9 | Device data hygiene | 10, 12, 93 | High | Code | M | 2 | Touches the local store: Tier 3, propose and wait. |
| R11 | Detect a breach and know what to do | 114 | High | Infrastructure and process | M | 2 |  |
| R14 | Retention and erasure | 98, 99, 30, 112, 70, 67, 104 | High | Code and decision | L | 2 | Log compaction is a sync-layer change: ADR first, Tier 3. |
| R15 | Tell people what happens to their data | 94, 107, 97 | High | Code and content | S | 2 |  |
| R12 | Record who did what in the panel | 35 | Medium | Code | M | 2 | Changes what the sync log holds: bump DEV_DATA_EPOCH. |
| R16 | Answer a family's request for their data | 100 | Medium | Code | M | 3 |  |
| R19 | Equal login timing | 44 | Low | Code | S | 3 | Tier 2 (auth): propose and wait. |
| R18 | Extend the assistant's evaluation set | 59 | Low | Code | S | 3 |  |

## What each item does

### R1. Pin third-party actions and audit dependencies

Pin every action in .github/workflows to a full commit SHA with the tag in a comment; add composer audit and npm audit to check.yml; add a Dependabot configuration for security updates.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 80 | A third-party action is replaced upstream | Tampering | 9.0 | Critical | GitHub Actions (deploy and infra) (diagram 4) |
| 81 | A compromised dependency ships in a release | Tampering | 8.0 | High | GitHub Actions (deploy and infra) (diagram 4) |

### R2. Give the deploy its own narrow role

A third OIDC role for deploy.yml holding only what it uses: put and delete under _releases/, ssm:SendCommand to the tagged host, sync to the frontend bucket, and one CloudFront invalidation. The apply role stays for Terraform alone.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 79 | The application deploy runs as an account administrator | Elevation of privilege | 8.0 | High | GitHub Actions (deploy and infra) (diagram 4) |

### R3. Verify the release archive on the host

Pass the archive's SHA-256 in the Systems Manager command and check it before unpacking, or stage releases where the application role cannot write.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 84 | The release archive is swapped in the bucket before the host unpacks it | Tampering | 8.0 | High | Application release (code on the host) (diagram 4) |

### R4. Encrypt the leg from CloudFront to the host

A domain, a certificate on the host, and origin_protocol_policy https-only, as ADR 0015, decision 7 already describes. Required before a real school's data is loaded.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 55 | Traffic between CloudFront and the host is unencrypted | Information disclosure | 7.4 | High | /api and /admin (plain HTTP, origin secret header) (diagram 2) |

### R8. Device tokens that expire

A token lifetime renewed by each successful sync, so an active device never notices and a stolen or abandoned token ages out; a password prompt before finalize and before resolving a conflict.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 6 | The bearer token is read from the device | Information disclosure | 7.1 | High | Session (Dexie: sessions) (diagram 1) |
| 2 | Someone else uses a signed-in device as the teacher | Spoofing | 6.1 | Medium | Teacher (incl. HOD, DOS) (diagram 1) |

### R17. Settle the legal questions

Put to the advisory ADR 0013 already awaits: whether a school's records must be held in Kenya; the basis and safeguards for sending pseudonymised results to the model provider; whether consent is the basis for a child's data. Then write a short DPIA (this threat model is most of its input) and a controller and processor agreement template.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 101 | All school data is stored outside Kenya | Non-compliance | 6 | High | Domain tables (Postgres) (diagram 1) |
| 106 | Pseudonymised student results leave Kenya without a recorded basis | Non-compliance | 6 | High | Questions and tool results (handles, percentages) (diagram 3) |
| 102 | No record that a child's data may lawfully be processed | Non-compliance | 6 | High | Admin panel (Filament, /admin) (diagram 2) |
| 103 | Roles and duties under the Act are not documented | Non-compliance | 4 | Medium | Admin panel (Filament, /admin) (diagram 2) |
| 71 | A student's name reaches the model provider in a question | Information disclosure | 3.0 | Low | Model provider (Anthropic API) (diagram 3) |

### R7. Security headers at CloudFront

One response headers policy: a Content-Security-Policy allowing only the site's own origin, Strict-Transport-Security, and frame-ancestors 'none'. Check the admin panel's needs (Livewire) before tightening script-src.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 4 | Injected script runs inside the app | Tampering | 6.8 | Medium | PWA (React app and service worker) (diagram 1) |
| 33 | A first visit is downgraded to HTTP | Spoofing | 6.4 | Medium | Online calls: login, reads, unlock, term close, reports, assistant (diagram 1) |
| 39 | The panel or the app is framed by another site | Tampering | 5.3 | Medium | CloudFront (diagram 2) |

### R10. Narrow the plan role

Replace ReadOnlyAccess with a policy that can run terraform plan but cannot read report cards and reads only the state object, or bind the role to infra.yml through the job_workflow_ref claim. Verify what ReadOnlyAccess grants first.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 82 | Any pull request can read production secrets and report cards | Information disclosure | 6.8 | Medium | GitHub Actions (deploy and infra) (diagram 4) |

### R6. A per-user API rate limit

A named limiter on the authenticated API group, keyed by user, generous enough for a device draining a long offline outbox; it also bounds remark regeneration and the unpaged list routes.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 25 | One account floods the sync endpoints | Denial of service | 6.5 | Medium | Sync API (GET and POST /sync) (diagram 1) |
| 48 | Read endpoints are unlimited and unpaged | Denial of service | 4.3 | Medium | Online API (reads, unlock, term close, reports) (diagram 2) |
| 66 | Remarks are regenerated without limit | Denial of service | 4.3 | Medium | Queue worker (diagram 3) |

### R13. Make the change log append-only

The same model guard sync_mutations has, on SyncChange, and a database trigger refusing UPDATE and DELETE on both log tables.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 29 | The change log is altered to hide an edit | Tampering | 4.4 | Medium | Sync logs (sync_changes, sync_mutations) (diagram 1) |

### R5. Rate-limit at the edge

A rate-based rule in front of CloudFront for /api and /admin, sized for a pilot school.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 37 | The site is flooded from the internet | Denial of service | 7.5 | High | CloudFront (diagram 2) |

### R9. Device data hygiene

Delete the user's local database at sign-out when the outbox is empty, and warn when it is not; request persistent storage at sign-in; stop syncing fields no screen displays. Whether a device needs the whole school is a decision for an ADR.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 93 | Every device holds the whole school's student records | Disclosure of information | 6 | High | Local records (Dexie tables) (diagram 1) |
| 10 | Records stay on the device after sign-out | Information disclosure | 6.2 | Medium | Local records (Dexie tables) (diagram 1) |
| 12 | The browser evicts the local store and unsynced marks are lost | Denial of service | 5.1 | Medium | Local records (Dexie tables) (diagram 1) |

### R11. Detect a breach and know what to do

A CloudTrail trail to a locked bucket; application and nginx logs shipped to CloudWatch with a few alarms (failed sign-ins, 5xx spikes, panel sign-ins); a one-page breach procedure naming who notifies the Data Commissioner and the school.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 114 | A breach would not be noticed in time to report it | Non-compliance | 6 | High | Application release (code on the host) (diagram 4) |

### R14. Retention and erasure

Decide retention periods with a school; then compact the sync logs past the period, add an admin action that anonymises a departed student through Syncable, delete a report card's object when it is unwound, add a lifecycle rule to the reports bucket, prune expired cache rows, and clear a deactivated teacher's email after the period.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 98 | The change log keeps every value a record has ever held | Non-compliance | 6 | High | Sync logs (sync_changes, sync_mutations) (diagram 1) |
| 99 | A student's data cannot be erased | Non-compliance | 6 | High | Domain tables (Postgres) (diagram 1) |
| 112 | Report cards are kept with no retention period | Non-compliance | 6 | High | Report card PDFs (S3 bucket) (diagram 3) |
| 70 | Superseded report cards are never deleted | Information disclosure | 4.4 | Medium | Report card PDFs (S3 bucket) (diagram 3) |
| 104 | Former staff's personal data is kept indefinitely | Non-compliance | 3 | Medium | Accounts and tokens (Postgres) (diagram 2) |
| 30 | The logs grow without limit | Denial of service | 3.1 | Low | Sync logs (sync_changes, sync_mutations) (diagram 1) |
| 67 | Assistant conversations outlive their expiry | Information disclosure | 2.2 | Low | Jobs and cache (Postgres) (diagram 3) |

### R15. Tell people what happens to their data

A privacy notice behind the existing 'Privacy and Security' menu entry, shown once at first sign-in: what is recorded and why, the sync logs' purpose and retention, what goes to the model provider, and how report cards should be shared; a one-line notice beside the assistant's question box.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 94 | Teachers are not told what the system records about them or sends elsewhere | Unawareness | 6 | High | PWA (React app and service worker) (diagram 1) |
| 107 | Sensitive information typed into a question reaches the provider | Disclosure of information | 6 | High | Model provider (Anthropic API) (diagram 3) |
| 97 | A permanent record of each teacher's work could be used to monitor staff | Non-repudiation | 4 | Medium | Sync logs (sync_changes, sync_mutations) (diagram 1) |

### R12. Record who did what in the panel

An actor on each sync_changes row, or a small admin action log covering grants, deactivation, and device revocation.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 35 | An admin denies a change made in the panel | Repudiation | 4.9 | Medium | School admin (back office) (diagram 2) |

### R16. Answer a family's request for their data

An admin export of one student's records: profile, enrolments, results, remarks, and report cards.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 100 | A family cannot find out what the school holds about their child | Unawareness | 4 | Medium | Domain tables (Postgres) (diagram 1) |

### R19. Equal login timing

Check a dummy hash when no account matches the email.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 44 | Response time reveals whether an email has an account | Information disclosure | 3.7 | Low | Auth API (/login, /logout, /me) (diagram 2) |

### R18. Extend the assistant's evaluation set

Add cases where an assessment name carries an instruction, and where a student is named in lower case; quote stored text in tool results if the first case succeeds.

| Threat | Title | Type | Score | Severity | Element |
| --- | --- | --- | --- | --- | --- |
| 59 | Text stored in the school's data steers the assistant | Tampering | 2.6 | Low | Report assistant (/reports/assistant) (diagram 3) |

## Recommended for acceptance

Each stays Open in the model until the team decides; marking it Accepted in Threat Dragon records the decision in the report.

| Threat | Title | Type | Score | Severity | Why accept |
| --- | --- | --- | --- | --- | --- |
| 36 | An admin gives themselves academic authority | Elevation of privilege | 6.5 | Medium | Admins are trusted with the school's setup by design (ADR 0009); R12 makes the act visible. |
| 8 | Cached app code is replaced on the device | Tampering | 5.7 | Medium | Whoever controls the browser controls the code it runs; the server trusts nothing the client sends. |
| 75 | A presigned report card link is passed on | Information disclosure | 5.3 | Medium | A ten minute window is a reasonable cost for downloads without a session. |
| 9 | A lost or stolen device exposes the whole school's records | Information disclosure | 4.6 | Medium | The residual once R9 is done and devices use a screen lock; whether a device needs the whole school is R9's decision. |
| 26 | Reads leave no record | Repudiation | 4.3 | Medium | Logging pulls would add a second record of teachers' activity (see threat 97) for little security gain. |
| 113 | Downloaded report cards leave the system's control | Unawareness | 4 | Medium | Outside the system; guidance in the privacy notice (R15). |
| 19 | A create reveals that an id exists in another school | Information disclosure | 3.5 | Low | Already accepted in docs/spec/sync-protocol.md: ids are unguessable and only existence is revealed. |
| 16 | Unsynced work is tied to one browser profile | Denial of service | 3.3 | Low | Outside the app's reach; covered by guidance in the privacy notice (R15). |
| 105 | Server logs link addresses to the records requested | Linkability | 2 | Low | Short-lived logs on a private host; the retention goes in the privacy notice (R15). |

## By priority

- **1, before a real school's data:** R1 (pin third-party actions and audit dependencies), R2 (give the deploy its own narrow role), R3 (verify the release archive on the host), R4 (encrypt the leg from cloudfront to the host), R8 (device tokens that expire), R17 (settle the legal questions), R7 (security headers at cloudfront), R10 (narrow the plan role), R6 (a per-user api rate limit), R13 (make the change log append-only).
- **2, before the pilot ends:** R5 (rate-limit at the edge), R9 (device data hygiene), R11 (detect a breach and know what to do), R14 (retention and erasure), R15 (tell people what happens to their data), R12 (record who did what in the panel).
- **3, when convenient:** R16 (answer a family's request for their data), R19 (equal login timing), R18 (extend the assistant's evaluation set).
