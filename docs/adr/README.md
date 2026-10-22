# Architecture decision records

One decision per file, numbered in the order they were made. Each opens with its
status; `CONTRIBUTING.md` says what the statuses mean. The specs in `docs/spec/` say
what the system does; these say why.

| ADR | Decision | Status |
|---|---|---|
| [0000](0000-backend-platform.md) | Laravel and PostgreSQL over Amplify Gen 1 | Accepted |
| [0001](0001-record-versioning.md) | Record versioning and conflict detection | Accepted |
| [0002](0002-conflict-resolution.md) | Who may resolve a mark conflict | Accepted |
| [0003](0003-charts-without-a-library.md) | Charts are our own SVG components | Accepted |
| [0004](0004-offline-with-workbox.md) | Offline install with Workbox | Accepted, reversing its own first version |
| [0005](0005-router-from-location.md) | Routing maps the Location type to URLs | Accepted |
| [0006](0006-own-sync-engine.md) | We write the synchronisation layer ourselves | Accepted; its evidence waits on the simulation |
| [0007](0007-queue-driver.md) | Queues run on the database driver | Accepted |
| 0008 | Never used: the number was skipped | |
| [0009](0009-admin-panel.md) | The admin panel is a Filament app | Accepted, partly superseded by 0014 |
| [0010](0010-sync-change-log.md) | The pull cursor is a change-log sequence | Accepted |
| [0011](0011-outbox-table.md) | Local writes wait in an outbox table | Accepted |
| [0012](0012-term-report-cards.md) | Reports are per student per term | Accepted |
| [0013](0013-report-assistant.md) | The report assistant answers through tools | Accepted; decision 4 provisional |
| [0014](0014-roles-accounts-and-terms.md) | Separate accounts, teacher roles, admin-opened terms | Accepted |
| [0015](0015-infrastructure-as-code.md) | Production is Terraform, deployed on every merge | Accepted |

The sync layer, the project's examined contribution, is 0001, 0002, 0006, 0010, and
0011, with `docs/spec/sync-protocol.md`.
