# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

This directory is the root of the customer graph, so the assertions concentrate on:

- **An edit PATCHes the row it opened**, never POSTs a copy.
- **A 409 says "E-Mail bereits vergeben"**, the one failure an admin can act on.
- **Delete hits the requested id** and doesn't refresh when the backend refuses.
- **A non-OK list response never shows the directory.**

| Suite | Covers |
|---|---|
| `islands/CustomersList.test.tsx` | The points above |
| `islands/WidgetBody.test.tsx` | Count tile |
| `src/index.test.ts` + `tests/packaging.test.ts` | Manifest and published specifiers |
| `php/tests/CustomersModuleTest.php` | RBAC, validation, `/me/companies` without a PDO, capability interface resolvable |
| `php/tests/CustomersApiDocsTest.php` | API-doc parity incl. generated alias entries |

Two patterns from the mutation pass:

- Asserting a row *contains* both email and phone passes when the two **columns** are swapped.
  Assert per cell.
- `value={null}` on a controlled input still reads back as `""`, so the `?? ""` coercion is
  invisible in the DOM. Assert it in the PATCH body.

Mutation check: 35 breakages, 35 caught.
