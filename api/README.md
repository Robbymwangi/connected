# ConnectED API

Laravel JSON API for the ConnectED PWA: Sanctum token auth, PostgreSQL, queued jobs
for report PDFs. JSON only; the frontend in `../frontend/` is a separate application.
The rules for the data model (client UUIDs, integer versions, soft deletes, the
`institution_id` global scope, field-level sync payloads) are in `../AGENTS.md`.

## Running it

The API runs in Docker through Laravel Sail. `compose.yaml` here defines three
services: `api` (PHP, published on port 8000), `pgsql` (PostgreSQL 18), and `queue`
(a `queue:work` worker). From the repository root, `make setup` the first time and
`make up` after that; see the root README for the platform notes.

From this directory, anything Sail offers:

```sh
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:model Assessment -m
./vendor/bin/sail composer require some/package
./vendor/bin/sail shell
./vendor/bin/sail logs -f queue
```

## Tests

```sh
./vendor/bin/sail test        # or `make api-test` from the root
```

Pest runs against a `testing` database on the same Postgres container, created by
Sail's init script, so tests exercise the engine the API ships on rather than SQLite.

## Configuration

`.env.example` is the working development configuration; `make setup` copies it to
`.env`. `APP_PORT` and `APP_SERVICE` are read by the `sail` script itself. Laravel
Boost's MCP config carries an absolute path, so `.mcp.json` is untracked; copy
`.mcp.json.example` and fill in your own path if you use it.
