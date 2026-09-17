# 5. Routing is the existing Location type mapped to URLs, not a router library

Date: 2026-09-17

## Status

Accepted.

## Context

Since the Assessments list, every place in the application has been a typed value,
`Location` in `frontend/src/app/location.ts`: a screen, and for some screens the
record within it. Screens receive it and ask to change it; nothing reaches into the
root. What was missing was the URL: reloading lost the user's place, the browser's
back and forward did nothing, and no screen was linkable.

`react-router` is the standard answer. What it adds over the model already here is
nested layouts, route-level data loading, and route-level code splitting. This
application uses none of them: data comes from the local store, not from loaders;
the whole application is one precached bundle by design (ADR 0004), so there is
nothing to split; and the shell is one layout.

## Decision

Routing is two pure functions and one hook. `pathFor(location)` and
`parseLocation(pathname, search)` in `frontend/src/app/routes.ts` map between the
`Location` type and URLs, and are tested in both directions. `useLocation()` in
`frontend/src/app/useLocation.ts` keeps `Location` in step with the browser history
(`pushState` on navigation, `popstate` for back and forward). `App` reads and writes
the URL through it and nothing else changes.

The URL scheme:

| Location | URL |
|---|---|
| dashboard | `/` |
| assessments list | `/assessments`, `/assessments?new` to open the create dialog |
| marking grid, report | `/assessments/<id>/grid`, `/assessments/<id>/report` |
| classes list, a stream, a student | `/classes`, `/classes/<classId>`, `/classes/<classId>/students/<studentId>` |
| a teacher | `/teachers/<teacherId>` |
| sync, with a highlighted conflict | `/sync`, `/sync?highlight=<conflictId>` |
| reports | `/reports` |

An unknown URL is the dashboard. A URL naming a record that does not exist falls back
to that screen's list, which the screens already do for a missing id.

## Consequences

- No dependency, and the whole routing layer is about a hundred lines that a
  developer can read in one sitting.
- Links, reloads, and back and forward work; the service worker answers any of these
  paths with the shell, so a deep link works offline too.
- Route-level code splitting or data loading would be the reason to adopt a router
  library, recorded in a later ADR. Until then, adding a screen means adding a
  `Location` variant and two lines in `routes.ts`.
