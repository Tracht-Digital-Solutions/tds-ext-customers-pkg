# AGENTS.md — tds-ext-customers-pkg

The **customer/company directory** frontend extension: the frontend's canonical
`customer` list. Read `tds-frontend-contract-pkg`'s AGENTS.md first (extensions
implement that contract); `tds-ext-lexware-pkg` / `tds-ext-support-tickets-pkg` are the
worked references for the container-first Module + RBAC pattern.

> Status (2026-07-20): **published @0.1.1** (GitHub Packages `@latest`, tag `v0.1.1`).
> Remaining go-live: wire into the admin product's `astro.config` (dep `^0.1.1` + the
> extensions array) — this ext's `/admin/customers` then replaces the legacy
> `tds-customer-api` company-list call the frontend user-management uses. See the root
> `MIGRATION-STATUS.md` (issue #3).

## What it does

Admin-facing directory (`customers:read` / `customers:write`) with one page + a
count widget. It owns the canonical **`customer`** table (id/name/email/phone/note)
and exposes:

- CRUD: `GET/POST /customers`, `GET/PATCH/DELETE /customers/{id}` (email uniqueness
  → 409).
- `GET /customers/summary` — widget count.
- **`GET /admin/customers`** — the admin-only `{customers:[{id,name}]}` list the
  **base user-management** consumes for company-membership editing (replacing the
  legacy `tds-customer-api` endpoint the new frontend still calls today).

## Why it exists / migration role

Replaces the customer/company directory that never got ported off `tds-customer-api`
— the new `tds-core-frontend-pkg` user editor reads the company list live from
that legacy service. This extension is that list's new home and the foundation the
billing / projects / documents / messages extensions build on. See the org's
migration epic.

**Cutover notes:**
- `tds-auth-api` `app_user_customer.customer_id` references these ids — when
  migrating, preserve existing customer ids (data migration), and repoint the
  frontend's `CUSTOMER_API_URL` to this extension's `GET /admin/customers`.
- The table is deliberately named `customer` (canonical), distinct from
  `tds-ext-lexware-pkg`'s own `lx_customer` billing directory — no collision.

## Conventions (from the template — don't regress)

- **Outcomes are toasts (tds-shared `>=0.16.0`); a 409 is not.** Saving and
  deleting report through `toast`, and the confirmation names what actually
  happened (create vs edit). The duplicate-email 409 stays in the in-flow
  banner, because it points at a field to fix in the form that is still open.
  Never mount a `ToastHost` here — the frontend host owns the only one.

- Contract dep is the **published** `^1.0.0` via the public **VCS** repo (no path
  repo — CI fatals on a missing one); npm from GitHub Packages (`.npmrc` +
  `NPM_TOKEN` from `PACKAGE_TOKEN`).
- CI installs with **`npm install --no-package-lock`**; prune steps are
  `continue-on-error`. Release bumps `package.json` + `composer.json` in lockstep;
  the pushed **annotated** tag is the Composer release ref.
- Migration class prefix `Customers*` (globally unique — shared in-process migrator);
  migration **versions** must also be unique across extensions (shared `phinxlog`).

## Tests (frontend)

```bash
npm run test:run    # vitest, 76 tests (jsdom per-file via a @vitest-environment docblock)
```

This directory is the ROOT of the customer graph — membership editing, billing
and the portal all key off these ids — so the assertions concentrate on:

- **an edit PATCHes the row it opened**, never POSTing a second copy. A
  duplicate company here silently splits one customer's invoices, portal access
  and tickets across two ids.
- **a 409 says "E-Mail bereits vergeben"**, not a generic error. That is the one
  failure an admin can act on: the customer already exists under another row.
- **delete hits the id it was asked for**, and does *not* refresh the list when
  the backend refuses (a reload would look like the row simply vanished — the
  backend refuses when memberships or invoices still reference it).
- **a non-OK list response never puts the directory on screen.**

Error-path tests deliberately answer with a POPULATED body and a non-OK status.
Against an EMPTY error body the `res.ok` check is unobservable.

Two tests exist only because the mutation pass proved the obvious versions
blind, and both are worth remembering as a pattern:

- asserting a row *contains* both the email and the phone passes when the two
  COLUMNS are swapped — the assertion is per-cell now;
- `value={null}` on a controlled input still reads back as `""`, so the `?? ""`
  coercion is invisible in the DOM. It only shows in the PATCH body, which is
  where it is asserted (the create path sends `""`, so an edit must match).

Verified by mutation: 35 deliberate breakages introduced, 35 caught.

## Commands

```bash
composer install && composer test    # phpunit: Module RBAC + validation (DB-free)
npm install --no-package-lock && npm run type-check && npm run test:run && npm run build
```

Register `new CustomersModule()` in `tds-core-frontend-api`'s `Modules::enabled()` and
add the manifest to the admin target's `frontendHost({ extensions })`.
