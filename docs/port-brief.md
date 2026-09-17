# Frontend port brief

Source: a Figma Make export, one file, `src/App.tsx`, roughly 4,400 lines, kept outside
the repository as the visual reference. It contains
the shell (sidebar drawer, top bar, notifications and user menu popups) and five
screens: Dashboard, Sync, Classes, Assessments (including the marking grid and the
assessment report), and Reports (including an "Ask AI" dialog). There is no routing:
`activeNav` state selects which screen renders. Treat the export as a visual reference,
not as a codebase to preserve.

An earlier, dashboard-only version of the export was ported first. Where the two
disagree, this brief records the decision.

Target: `frontend/`, a standalone Vite React PWA in TypeScript, talking to a separate
Laravel JSON API. Not Inertia, not server-rendered. The application must function with
no connectivity at all, which is its normal resting state.

## Target structure

```
frontend/src/
  app/            App.tsx (router later), providers, root layout
  styles/         tokens.css, fonts.css, fonts/
  components/     Button, Card, ChevronRow, KpiTile, NavItem, Popover, StatusPill
  layout/         AppShell, Sidebar, TopBar, SyncStatusIndicator,
                  NotificationsPopup, UserMenu, navigation
  features/
    dashboard/    Dashboard.tsx, AttentionBanner.tsx, cards/
    sync/
    classes/
    assessments/  including the marking grid
    reports/
  fixtures/       static data standing in for the local store and the API
  lib/            theme, connectivity, time, useClickOutside
```

## Discard from the export

The entire `.figma/` directory; `.mise.toml`; `AGENTS.md`; `plans/`; `src/imports/`;
the Figma Make plugins in `vite.config.ts`; the `<!-- figma:* -->` comment slots in
`index.html`; and every hand-written SVG icon component.

## Known defects in the export

The export has never been type-checked. `npm run typecheck` runs `tsc --noEmit` and
must pass before any session is considered finished.

The theme toggle mutates `document.documentElement.classList` inside a `useState`
setter and persists nothing.

Fixture ids are numbers. Every id in the port is a string, because primary keys are
client-generated UUIDs.

## Corrections required, with reasons

**Fonts.** The export imports Google Sans Flex from `fonts.googleapis.com`. Removed.
Fraunces for display and headings, Figtree for body and tabular data, both self-hosted
as variable woff2 under `styles/fonts/` and precached by the service worker.

**Icons.** `lucide-react` replaces the inline SVGs.

**Theme.** Light is the default; dark is available via the toggle. The choice is
persisted to `localStorage` and applied by an inline script in `index.html` before
React mounts, so there is no white flash. Local only; never a network request.

**Colour.** No raw colour in components. Status colours (`success`, `info`, `warning`,
`danger`) and chart colours are semantic tokens in `styles/tokens.css`. The export's
`green-500`, `blue-600`, and hex literals all map to these.

**Connectivity and sync indicators.** Two distinct facts, both shown. The pill reports
connectivity from `navigator.onLine` plus, once the API has a health route, a periodic
lightweight request. The line beneath reports sync state from the outbox, with a
pending count taking visual precedence. The manual online/offline toggle exists only
in development builds.

**Sidebar.** Hover on the left edge or the hamburger peeks the drawer; clicking the
hamburger pins it. Touch devices have no hover, so pinning is the path that must
always work.

## Decisions where the revised design and the earlier brief disagreed

**Notifications: build it.** The bell is the surface for long-running and asynchronous
work (report generation, sync conflicts, enrolment changes). The intended source is
records the device already holds, synced like any other table, so the feed works
offline and needs no push infrastructure. This becomes an ADR when the sync engine
exists. Until then the popup reads a typed fixture.

**My Classes card: stat tiles.** Class mean and cohort for the current assessment,
each with a delta from the previous one. The bell curve is gone from the dashboard and
the revised Reports screen uses histograms, so the normal-distribution code is not
retained.

**Class selector: kept.** The `<select>` on My Classes is the dashboard's one scope
control. It changes which class the figures describe; it does not drill. CLAUDE.md's
rule is amended to: "Dashboard cards are static: no drag or drill. The class selector
on My Classes is the one scope control; interactive analytics live in Reports."

## Decisions since

**Charts.** Decided: no library (ADR 0003). Bar and line charts are our own SVG
components in `components/charts/`.

**"Ask AI" dialog.** Decided: it lives in Reports, scoped to the report on screen, and
needs a connection; offline it says so. Until the API's assistant exists, answers are
templated from the report's own figures and labelled as a stand-in.

## Data

No API calls at this stage. All screens render from static fixtures in `fixtures/`. Do
not invent API client code, data-fetching hooks, or a state management library. State
shared between screens (active conflicts, for instance) is lifted to `App` until the
local store exists.

## Sequence

1. Tokens and fonts. Done (MR 3).
2. Shell and theme. Done, realigned to the revised design (MR 3, 4).
3. Dashboard. Done (MR 3).
4. Assessments and the marking grid. Done (MR 3, 5).
5. Sync, under ADR 0002. Done (MR 7).
6. Classes, with charts under ADR 0003. Done (MR 8).
7. Reports with computed analytics, the assistant stand-in, and the assessment
   report. Done (MR 9, 10).
8. Service worker and precache; router. Open: ADRs 0004 and 0005 to decide
   hand-written versus a library for each.
9. A screen-by-screen check against the export at 1440px.

## Definition of done for the port

`npm run typecheck` and `npm run build` both pass; each screen renders at 1440px
matching the export's visual design apart from the corrections above; no remote
runtime dependencies of any kind.
