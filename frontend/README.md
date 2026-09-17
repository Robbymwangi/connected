# ConnectED frontend

Offline-first PWA for examination management. React and TypeScript on Vite; see
`../AGENTS.md` for the rules and `../docs/adr/` for the decisions.

## Scripts

| Command | What it does |
|---|---|
| `npm run dev` | Development server on port 5173 with live reload. **No service worker**: it is unregistered here on purpose, so the app does not work offline in this mode. |
| `npm run offline` | Production build served on port 4173. This is the app as installed: load it once online, then it opens offline, survives reloads, new tabs, and restarts. |
| `npm run build` | Production build into `dist/`, including `sw.js` with the precache list. |
| `npm run preview` | Serve an existing `dist/` on port 4173. |
| `npm run typecheck`, `npm test`, `npm run lint` | All three must pass before a commit. |

## Trying the offline behaviour

1. `npm run offline`, open http://localhost:4173, let it load once.
2. In DevTools, Network tab, set Offline. Reload, open a deep link such as
   `/assessments/a1/grid` in a new tab, close and reopen the browser: all served
   from the cache by `src/sw.js` (ADR 0004).
3. Rebuild, reload once online: an "update ready" prompt appears; the new version
   takes over only when you press Reload.

What does not survive a closed tab yet is your work: the store is in memory until the
IndexedDB store with its outbox exists (ADR 0001).
