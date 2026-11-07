# AGENTS.md — HRMS application repository

Read `AGENTS.local.md` before working in this repository whenever the file is available. Codex does not discover it automatically.

Don't write design rationale, architecture decisions, or planning history into this file. `AGENTS.md` is an operational starting point only: how to work in this repository. The design corpus lives **outside** this repository, at the private wiki location specified in `AGENTS.local.md`.

## Workflow

- Inspect the relevant existing code and tests before editing.
- Make small, coherent changes. Do not modify unrelated modules.
- Respect Laravel conventions and the module boundaries already in place.
- Implement one testable slice at a time. Include happy-path, validation, and negative-authorization tests as appropriate.
- Do not mark work complete until relevant tests have run; clearly report failures and skipped checks.
- Avoid new runtime dependencies, workers, and external services without an explicit requirement.
- Keep the HRMS usable without UniversalMcpGateway. Do not access other applications' databases directly.
- Never commit `.env`, secrets, real employee data, production exports, or `AGENTS.local.md`.

## Common commands (verify against the installed project)

- PHP tests: `php artisan test`
- PHP formatting: `./vendor/bin/pint --test` (if Pint is installed)
- Frontend build: `npm run build`
- Database migrations: `php artisan migrate` (only for the intended environment; never use destructive reset commands without approval)

## End-of-task report

Summarize changed files, verification commands with results, intentional omissions, and the smallest next development step. If design knowledge or decision history needs updating, use the external wiki defined in `AGENTS.local.md`, not this file.
