<?php
declare(strict_types=1);

namespace Tds\Ext\Customers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Customers\Domain\CustomerRepository;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\MultiCompanyContext;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\UserContext;

/**
 * Backend Module for the customer/company directory — the panel's canonical
 * customer list. Replaces the legacy `tds-customer-api` directory that the base
 * user-management still reads for membership editing.
 *
 * Auth via the core {@see UserContext}: reads require `customers:read`, mutations
 * `customers:write` (admins bypass). `GET /admin/customers` is the admin-only
 * `{customers:[{id,name}]}` list the base user-editor consumes. Data via the core
 * shared PDO.
 */
final class CustomersModule extends AbstractModule implements ApiDocSource
{
    public function id(): string
    {
        return 'customers';
    }

    /**
     * @return PermissionDef[]
     *
     * The ids are `companies:*` now. tds-auth-api rewrote the stored strings
     * (migration 20260814000007) and normalises the old spelling on read, so
     * the catalog only ever needs to publish the current one — but a TOKEN
     * minted before the rename still carries `customers:*` for up to an hour,
     * which is what {@see self::require()} handles.
     */
    public function permissions(): array
    {
        return [
            new PermissionDef('companies:read', 'Firmen ansehen', 'companies'),
            new PermissionDef('companies:write', 'Firmen verwalten', 'companies'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(CustomerRepository::class)) {
            $c->set(CustomerRepository::class, static fn ($c) => new CustomerRepository($c->get(PDO::class)));
        }

        // --- the customer → company rename -------------------------------
        //
        // Every route below is mounted at BOTH `/companies…` (current) and
        // `/customers…` (deprecated). The panel, the thirteen extensions and
        // this backend ship independently, so a build that still calls the old
        // path has to keep working for one release — and unlike a missing
        // permission, a missing ROUTE is a 404 the caller cannot recover from.
        //
        // The handlers are defined once and mapped twice: two copies of a
        // permission check is how one of them ends up wrong.
        //
        // Responses carry BOTH keys (`companies` and `customers`) for the same
        // reason. Drop the aliases — paths and keys — in the follow-up release.

        // Widget summary.
        $summary = function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'companies:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['count' => $c->get(CustomerRepository::class)->count()]);
        };
        $app->get('/companies/summary', $summary);
        $app->get('/customers/summary', $summary);

        // Admin-only `{id,name}` list for membership pickers (base user editor).
        $adminList = function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $rows = $c->get(CustomerRepository::class)->adminList();
            return self::json($res, ['companies' => $rows, 'customers' => $rows]);
        };
        $app->get('/admin/companies', $adminList);
        $app->get('/admin/customers', $adminList);

        // The caller's OWN companies, for the shell's profile menu.
        //
        // Needed because `/admin/customers` above is admin-only by design, so a
        // portal user cannot resolve even their own company's name — and the
        // menu would have to print "Firma #7". Scoped to the ids in the
        // verified token, so this reads no more than the principal already
        // proves membership of.
        //
        // No permission gate beyond being signed in: your own company's NAME is
        // not `customers:read` material, and requiring that permission would
        // mean every portal user needs the directory read right just to see a
        // header.
        $app->get('/me/companies', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (!$user->isAuthenticated()) {
                return self::json($res, ['error' => 'Unauthorized'], 401);
            }

            // Optional capability (contract 1.8.0) — probe, never assume.
            $ids = $user instanceof MultiCompanyContext ? $user->companyIds() : [];

            // Short-circuit BEFORE resolving the repository. An admin
            // legitimately has no memberships (their reach is "any company",
            // which is not belonging to one), and the shell calls this on
            // every page — so the common admin case must not construct a
            // DB-backed repository to run no query. It also means the profile
            // menu still renders for an admin while the database is down.
            if ($ids === []) {
                return self::json($res, ['companies' => []]);
            }

            $active = $user->activeCompanyId();
            $companies = array_map(
                static fn (array $row): array => $row + ['active' => $row['id'] === $active],
                $c->get(CustomerRepository::class)->byIds($ids),
            );

            return self::json($res, ['companies' => $companies]);
        });

        // Directory CRUD.
        $list = function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'companies:read', $res)) !== null) {
                return $deny;
            }
            $rows = $c->get(CustomerRepository::class)->all();
            return self::json($res, ['companies' => $rows, 'customers' => $rows]);
        };
        $app->get('/companies', $list);
        $app->get('/customers', $list);

        $create = function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'companies:write', $res)) !== null) {
                return $deny;
            }
            $data = self::payload((array) $req->getParsedBody());
            if (is_string($data)) {
                return self::json($res, ['error' => $data], 422);
            }
            $repo = $c->get(CustomerRepository::class);
            if ($data['email'] !== null && $repo->emailTakenBy($data['email'])) {
                return self::json($res, ['error' => 'E-Mail bereits vergeben'], 409);
            }
            return self::json($res, ['id' => $repo->create($data)], 201);
        };
        $app->post('/companies', $create);
        $app->post('/customers', $create);

        $show = function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'companies:read', $res)) !== null) {
                return $deny;
            }
            $company = $c->get(CustomerRepository::class)->find((int) $args['id']);
            return $company === null
                ? self::json($res, ['error' => 'Not found'], 404)
                : self::json($res, $company);
        };
        $app->get('/companies/{id:[0-9]+}', $show);
        $app->get('/customers/{id:[0-9]+}', $show);

        $update = function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'companies:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CustomerRepository::class);
            $id = (int) $args['id'];
            if ($repo->find($id) === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $data = self::payload((array) $req->getParsedBody());
            if (is_string($data)) {
                return self::json($res, ['error' => $data], 422);
            }
            if ($data['email'] !== null && $repo->emailTakenBy($data['email'], $id)) {
                return self::json($res, ['error' => 'E-Mail bereits vergeben'], 409);
            }
            $repo->update($id, $data);
            return self::json($res, ['ok' => true]);
        };
        $app->patch('/companies/{id:[0-9]+}', $update);
        $app->patch('/customers/{id:[0-9]+}', $update);

        $delete = function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'companies:write', $res)) !== null) {
                return $deny;
            }
            $c->get(CustomerRepository::class)->delete((int) $args['id']);
            return self::json($res, ['ok' => true]);
        };
        $app->delete('/companies/{id:[0-9]+}', $delete);
        $app->delete('/customers/{id:[0-9]+}', $delete);
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Validate + normalise a customer payload. @param array<string,mixed> $body
     * @return array{name:string,email:?string,phone:?string,note:?string}|string
     */
    private static function payload(array $body): array|string
    {
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return 'name is required';
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'email is invalid';
        }
        return [
            'name' => mb_substr($name, 0, 200),
            'email' => $email === '' ? null : $email,
            'phone' => self::optional($body['phone'] ?? null, 40),
            'note' => self::optional($body['note'] ?? null, 2000),
        ];
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }

    /**
     * Legacy → current permission id, for the transition window.
     *
     * @var array<string,string>
     */
    private const PERMISSION_ALIASES = [
        'companies:read' => 'customers:read',
        'companies:write' => 'customers:write',
    ];

    /**
     * Gate on a permission, accepting the PRE-RENAME spelling too.
     *
     * A token issued before tds-auth-api 0.6.0 carries `customers:read` and
     * stays valid for up to an hour. Checking only the new id would 403 every
     * one of those users — right after a deploy, for a right they demonstrably
     * hold. Drop the alias lookup together with the rest of the aliases in the
     * follow-up release.
     */
    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }

        $legacy = self::PERMISSION_ALIASES[$permission] ?? null;
        if (!$user->has($permission) && ($legacy === null || !$user->has($legacy))) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }

        return null;
    }

    private static function requireAdmin(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->isAdmin()) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Route documentation for the admin frontend's API reference. Kept in its
     * own file so the prose does not sit in the middle of the wiring.
     *
     * @return list<array<string, mixed>>
     */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }
}
