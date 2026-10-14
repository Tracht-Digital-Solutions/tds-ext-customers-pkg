# tds-ext-customers-pkg

**Customer/company directory** for the TDS frontend — the canonical `customer` list.
A build-time-composed extension for the frontend platform (`tds-frontend-contract-pkg` +
`tds-core-frontend-*`).

## Features

- Directory CRUD (name / email / phone / note), email-uniqueness guard.
- `GET /admin/customers` → `{customers:[{id,name}]}`, the list the base
  user-management uses for company-membership editing.
- Count widget + a `/customers` page (list + create + inline edit + delete).

Admin-facing (`customers:read` / `customers:write`); shipped in the **admin**
product target.

## Migration role

This is the new home of the customer/company directory that was never ported off
`tds-customer-api`. The new frontend's user editor currently reads that list live from
the legacy service; this extension replaces it and is the foundation for the
billing / projects / documents / messages extensions. On cutover, preserve existing
customer ids (`tds-auth-api` memberships reference them) and repoint the frontend's
`CUSTOMER_API_URL` at `GET /admin/customers`. Table `customer` is distinct from
`tds-ext-lexware-pkg`'s own `lx_customer` billing directory.

## Develop

```bash
npm install --no-package-lock   # pulls tds-frontend-contract from GitHub Packages (needs NPM_TOKEN)
npm run type-check && npm run build
composer install                # resolves tds-frontend-contract from its public VCS repo
composer test                   # phpunit: Module RBAC + validation (DB-free)
```

Enable it: add the manifest to the admin `astro.config.mjs`
(`frontendHost({ extensions: [...] })`) and `new CustomersModule()` to
`tds-core-frontend-api`'s `Modules::enabled()`.

## Versioning

Semver; the release workflow bumps `package.json` **and** `composer.json` in
lockstep and pushes an annotated tag (the Composer release ref). npm →
GitHub Packages (public); the PHP half is consumed via that git tag.

## Lizenz / License

**Öffentlich einsehbar, alle Rechte vorbehalten.** Der Code kann gelesen und
zur Beurteilung der Programmierkenntnisse betrachtet werden. Es besteht keine
allgemeine Erlaubnis zum Kopieren, Verändern oder kommerziellen Weiterverwenden.
Die vollständigen Bedingungen und Ausnahmen stehen in [LICENSE](LICENSE).

**Publicly viewable, all rights reserved.** Public access permits inspection of
the code, but grants no general permission to copy, modify or commercially reuse
it. See [LICENSE](LICENSE) for the complete terms and exceptions.
