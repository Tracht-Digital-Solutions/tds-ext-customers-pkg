# AGENTS.md — tds-ext-customers-pkg

Frontend extension for the **company directory** (Firmen): the platform's canonical
`company` table. Membership editing, billing, projects, documents, messages and the portal
all key off these ids. It contributes a PHP `Module` to `tds-core-frontend-api` and the
`/firmen` page plus a count widget to the admin product.

Read `tds-frontend-contract-pkg/AGENTS.md` first. `tds-ext-lexware-pkg` and
`tds-ext-support-tickets-pkg` show the same container-first Module + RBAC pattern.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit: RBAC + validation (DB-free)
```

## Hard rules

- An edit PATCHes the row it opened; never POST a second copy (a duplicate splits a customer across two ids).
- Duplicate email → 409 "E-Mail bereits vergeben", shown in-flow. Other outcomes are toasts.
- `/me/companies` stays ungated, token-scoped, `[]` for admins, and short-circuits before the repository.
- Route handlers are defined once and mapped to both `/companies…` and the legacy `/customers…` paths.
- Never rename the module id `customers` or the package names as part of a schema change.
- Call the API with `apiFetch`, never a relative `fetch`. Never mount a `ToastHost`.
- Every route change updates `php/docs/api.php`. Migration classes are prefixed `Customers`.
- Stay in the `0.1.x` line. Versions move via the release workflow only.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, `/me/companies`, the data model or the rename aliases |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
