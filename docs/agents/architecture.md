# Architecture

## Routes

Admin-facing (`companies:read` / `companies:write`):

| Route | Purpose |
|---|---|
| `GET` / `POST /companies` | List / create |
| `GET` / `PATCH` / `DELETE /companies/{id:[0-9]+}` | Detail; email uniqueness → **409** |
| `GET /companies/summary` | Widget count |
| `GET /admin/companies` | Admin-only `{companies:[{id,name}]}`, consumed by the base user management for membership editing |
| `GET /me/companies` | `{companies:[{id,name,active}]}` for the caller's **own** memberships |

Every route also answers at its old `/customers…` path; see the rename section below.

## `/me/companies`

`/admin/companies` is admin-only by design, so a portal user couldn't resolve their own
company's name, and the profile menu would print `Firma #7`. `/me/companies` fixes that:

- **No permission gate beyond being signed in.** Your own company's name isn't
  `companies:read` material.
- **Scoped to the ids in the verified token** via the contract's optional
  `MultiCompanyContext` (contract ≥ 1.8.0), checked with `instanceof`. A principal whose
  context predates the capability gets an empty list, not an error.
- **An admin gets `[]`.** Their reach is "any company", which isn't membership.
- **It short-circuits before resolving the repository** when there are no ids. The shell
  calls it on every page load, so the admin case must not build a DB-backed repository, and
  the profile menu keeps rendering while the database is down. `CustomersModuleTest` binds
  no PDO, so a regression fails loudly.

**Trap:** `instanceof` on a class that doesn't exist is silently `false`. If the vendored
contract lags, the route returns `[]` for everyone while the suite stays green.
`testTheCapabilityInterfaceIsActuallyResolvable` guards this; if it fails, run
`composer update tracht-digital-solutions/tds-frontend-contract`. CI is unaffected (the
gateway's `_assemble.yml` checks the contract out from `main`).

## Data model

- Table **`company`** (id, name, email, phone, note). Migrations `CreateCustomersCustomer`
  and `CustomersRenameToCompany`.
- `tds-auth-api` `app_user_company.company_id` references these ids. Preserve ids in any
  data migration.
- Distinct from `tds-ext-lexware-pkg`'s own `lx_customer` billing directory; no collision.
- This extension replaced the company list that used to come from `tds-customer-api`.

## The `customer` → `company` rename

The table always held a company (Firma); people are `app_user` rows in `tds-auth-api`. The
schema, permission ids (`customers:*` → `companies:*`), the panel route (`/customers` →
`/firmen`) and all labels now say so.

**Dual-accepted during the transition:**

| Surface | Current | Also accepted |
|---|---|---|
| API paths | `/companies…`, `/admin/companies` | `/customers…`, `/admin/customers` |
| Response key | `companies` | `customers` (both emitted) |
| Permission ids | `companies:read/write` | `customers:read/write` |

The panel, the extensions and the composed backend ship independently, so a build calling
the old path must keep working; a missing route is an unrecoverable 404. Tokens minted
before `tds-auth-api` 0.6.0 carry `customers:*` for up to an hour, which the alias lookup in
`require()` covers.

- **Handlers are defined once and mapped twice.** Two copies of a permission check is how one
  ends up wrong.
- The `/customers…` doc entries are **generated** from the canonical list in
  `php/docs/api.php`.
- **Follow-up cleanup:** delete the alias route mappings, `PERMISSION_ALIASES`, the second
  response key and the `$aliases` derivation in the docs. Otherwise the old names work
  forever.
- **Deliberately unchanged:** the module id (`customers`), the npm and Composer package names
  and the repo name. They are publishing identity, pinned by both products and referenced in
  `Modules::enabled()`. Renaming them is a separate, mechanical repo-rename step, never part
  of a data migration.

## Frontend

- `islands/CustomersList.tsx` on `pages/Index.astro` (`/firmen`), plus the count widget.
- **Saving and deleting report via toast**, naming what happened (create vs edit). The
  duplicate-email 409 stays in the in-flow banner, because it points at a field in the
  still-open form.
- Delete hits the requested id and does **not** reload when the backend refuses (it refuses
  while memberships or invoices still reference the company).
- A non-OK list response never puts the directory on screen.
- Motion (tds-shared ≥ 0.38.7): the form and the validation hint open with `Collapse`, not
  `Presence`, so the form appears on the same frame as the click. The table stays static
  (transforms on `<tr>` render unreliably). Closing content stays in the DOM briefly
  (`aria-hidden` + `inert`); tests `waitFor`.

## Wiring

`new CustomersModule()` is registered in `tds-core-frontend-api`'s `Modules::enabled()`, and
the manifest is in the admin product's `frontendHost({ extensions })`.
