# AGENTS.md — tds-ext-customers-pkg

The **company directory** (Firmen) frontend extension: the frontend's canonical
`company` list. Read `tds-frontend-contract-pkg`'s AGENTS.md first (extensions
implement that contract); `tds-ext-lexware-pkg` / `tds-ext-support-tickets-pkg` are the
worked references for the container-first Module + RBAC pattern.

> Status (2026-07-20): **published @0.1.1** (GitHub Packages `@latest`, tag `v0.1.1`).
> Remaining go-live: wire into the admin product's `astro.config` (dep `^0.1.1` + the
> extensions array) — this ext's `/admin/companies` then replaces the legacy
> `tds-customer-api` company-list call the frontend user-management uses. See the root
> `MIGRATION-STATUS.md` (issue #3).

## What it does

Admin-facing directory (`companies:read` / `companies:write`) with one page
(`/firmen`) + a count widget. It owns the canonical **`company`** table
(id/name/email/phone/note) and exposes:

- CRUD: `GET/POST /companies`, `GET/PATCH/DELETE /companies/{id}` (email
  uniqueness → 409).
- `GET /companies/summary` — widget count.
- **`GET /admin/companies`** — the admin-only `{companies:[{id,name}]}` list the
  **base user-management** consumes for company-membership editing (replacing the
  legacy `tds-customer-api` endpoint the new frontend still calls today).

- **`GET /me/companies`** — `{companies:[{id,name,active}]}` for the caller's OWN
  memberships, which is what puts a company NAME in the shell's profile menu.

Every one of those also answers at its old `/customers…` path for one release —
see the rename section at the bottom.

### Why `/me/companies` exists next to `/admin/companies`

`/admin/companies` is admin-only **by design** ("wer Mitgliedschaften vergibt, ist
ohnehin Admin"), so a portal user cannot resolve even their own company's name and
the menu would have to print `Firma #7`. Four things about it are deliberate:

- **No permission gate beyond being signed in.** Your own company's name is not
  `companies:read` material; requiring it would mean every portal user needs the
  directory read right just to render a header.
- **Scoped to the ids in the verified token**, via the contract's optional
  `MultiCompanyContext` (1.8.0) — `instanceof`, never assumed. A principal whose
  context predates the capability degrades to an empty list rather than erroring.
- **An admin gets `[]`.** Their reach is "any company", which is not belonging to
  one; returning the directory here would turn a convenience accessor into an
  unbounded read.
- **It short-circuits before resolving the repository** when there are no ids. The
  shell calls this on every page load, so the common admin case must not build a
  DB-backed repository to run no query — and the profile menu keeps rendering for
  an admin while the database is down. `CustomersModuleTest` binds no PDO at all,
  so a regression here fails loudly instead of passing quietly.

> **The trap this feature was written into:** `instanceof` on a class that does not
> exist is silently `false`. The vendored contract in this repo lagged behind, so
> the route returned `[]` for everyone while the whole suite stayed green.
> `testTheCapabilityInterfaceIsActuallyResolvable` is the guard; if it fails, run
> `composer update tracht-digital-solutions/tds-frontend-contract`. CI is
> unaffected — the gateway's `_assemble.yml` checks the contract out from `main`.

## Why it exists / migration role

Replaces the customer/company directory that never got ported off `tds-customer-api`
— the new `tds-core-frontend-pkg` user editor reads the company list live from
that legacy service. This extension is that list's new home and the foundation the
billing / projects / documents / messages extensions build on. See the org's
migration epic.

**Cutover notes:**
- `tds-auth-api` `app_user_company.company_id` references these ids — when
  migrating, preserve existing company ids (data migration), and repoint the
  frontend's `CUSTOMER_API_URL` to this extension's `GET /admin/companies`.
- The table is named `company` (canonical), distinct from
  `tds-ext-lexware-pkg`'s own `lx_customer` billing directory — no collision.

## Conventions (from the template — don't regress)

- **Call the API with `apiFetch` from `@tracht-digital-solutions/tds-shared/api`,
  never a relative `fetch`.** Every island used to define its own
  `const api = (path, init) => fetch(path, { credentials: "include", ...init })`
  with a RELATIVE path. In a product that resolves against the product's own
  static host (`management.`/`app.tracht-digital.de`), not the API — and the
  static host answers unknown paths with its SPA fallback, i.e. **200 + HTML**.
  So `res.ok` is `true`, `res.json()` throws, and the usual
  `.catch(() => setRows([]))` renders a calm, permanent empty state with no
  error and no console warning. `apiFetch` resolves the base from
  `<meta name="tds-api-base">` (written by the frontend host) and routes 401s
  through the host's confirm-against-`/me` backstop, which extension calls
  previously skipped entirely.
  The island tests match on the request PATH (`pathOf()`), which a relative
  fetch satisfies just as well — so one assertion per suite pins the **absolute
  host**. That is the line that fails if this ever regresses.


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
- **CI runs `test:run` since 2026-08-25 — before that, none of these suites
  ever ran on a runner.** `_build.yml` had type-check, lint:primitives and
  build. That included the `ApiDocSource` parity test, whose entire job is to
  fail when a route gains or loses documentation.
- **The suites used to run against a tds-shared a dozen minors old, and the
  first honest run cost 30 failures across the twelve shipping extensions.**
  This package declares tds-shared as a **peer** with a `>=0.19.0` floor, so a
  fresh install resolved 0.19.0 while every product build composes the current
  one. Three separate behaviours had moved underneath the tests, and each is
  worth knowing because a new suite will hit them again:
  - `apiFetch` consults the host-side runtime config (`/tds-runtime.json`)
    before it resolves a URL, so `fetch.mock.calls[0]` is that probe, not the
    endpoint. Call **`primeRuntimeConfig(null)`** in `beforeEach` — the panel
    products never ship that file (they render `<meta name="tds-api-base">`),
    so "absent" is also what happens in production.
  - `apiFetch` is **async**: the request leaves on a later microtask than the
    render. Reading `mock.calls` on the line after `render(...)` yields
    `undefined`; `await waitFor(() => expect(fetch).toHaveBeenCalled())` first.
  - A multipart upload now carries an **empty** `headers` object rather than
    `undefined`. Identical to the browser — the boundary is still the
    browser's to set — so assert "no content-type header", never
    "headers is undefined".


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

## Mobile layout

This package ships **no CSS**, so every layout decision is a shared class or a
Tailwind utility, and neither is checked by anything at runtime. Two rules:

- **A row of more than two things — or any row holding a full-width field —
  goes on `.tds-row`, `.tds-list__row` or `.tds-toolbar`.** All three wrap.
  A hand-rolled `flex` does not, and on a 375px screen the overflow is not
  even visible: `body { overflow-x: hidden }` clips it, so the content simply
  is not there.
- **A `<table>` needs `tds-table` and nothing else.** The primitive turns
  itself into a horizontal scroller below 40rem; an extra `overflow-x`
  wrapper or an inline style is redundant. A table with no focusable cell
  also needs `tabindex="0"` + `role="region"` + a label, or its scrollport
  cannot be reached by keyboard.

`npm run lint:primitives` enforces the class part of this (including a
`<table>` without `tds-table` and a flex/grid table cell, which silently
drops the cell out of the column algorithm). It is a **regex scan**, so a tag
name written inside a comment counts as markup — name elements in prose.

## API-Referenz (`php/docs/api.php`)

This module implements the contract's optional `ApiDocSource`: `php/docs/api.php`
returns one entry per route (summary, params, responses, required permission),
and the admin frontend's API reference joins it onto the introspected Slim routes
by `"<METHOD> <pattern>"`. Two things to know before editing a route:

- **`pattern` must be the Slim pattern verbatim**, inline regex included
  (`/customers/{id:[0-9]+}`). A prettified path silently produces an orphan doc *and* an
  undocumented route rather than an error.
- **`php/tests/CustomersApiDocsTest.php` asserts both directions** — the documented
  set and the registered set must be the same set, every path placeholder must
  be described, and a named permission must exist in `permissions()`. Adding or
  renaming a route without touching `docs/api.php` fails there. That is the
  point: prose next to code rots, and a reference full of confident, wrong
  detail is worse than the bare route list it replaced.

## `customer` → `company` (the rename, and what stayed)

The table always held a **Firma**; the people are `app_user` rows in
tds-auth-api and always were. As of this change the schema says so:
`customer` → **`company`**, the permission ids `customers:*` →
**`companies:*`**, the panel route `/customers` → **`/firmen`**, and every label
reads "Firmen".

**What is dual-accepted for exactly one release:**

| Surface | Current | Also accepted |
|---|---|---|
| API paths | `/companies…`, `/admin/companies` | `/customers…`, `/admin/customers` |
| Response key | `companies` | `customers` (both emitted) |
| Permission ids | `companies:read/write` | `customers:read/write` |

None of that is politeness. The panel, the thirteen extensions and the composed
backend ship independently, so a build calling the old path has to keep working
— and a missing ROUTE is a 404 the caller cannot recover from, unlike a missing
permission. A token minted before tds-auth-api 0.6.0 carries `customers:*` for
up to an hour, which is what the alias lookup in `require()` covers.

**The route handlers are defined once and mapped twice.** Two copies of a
permission check is how one of them ends up wrong. The `/customers…` doc entries
are likewise *generated* from the canonical list in `php/docs/api.php` — hand
-writing fourteen entries where seven differ by a path segment guarantees the
pair nobody re-reads is the one that disagrees.

**Delete all of it in the follow-up release:** the alias route mappings, the
`PERMISSION_ALIASES` map, the second response key, and the `$aliases` derivation
in the docs. Leaving them means the old names work forever and the rename bought
nothing.

**What deliberately did NOT change:** the module id (`customers`), the npm and
Composer package names, and this repo's name. Those are publishing identity —
pinned by both products and referenced in `Modules::enabled()` — and moving them
is the separate, mechanical repo-rename step (the playbook already used twice in
2026-07). Renaming them in the same change as a data migration would move the
publishing identity and the schema at once.
