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

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('customers:read', 'Kunden ansehen', 'customers'),
            new PermissionDef('customers:write', 'Kunden verwalten', 'customers'),
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

        // Widget summary.
        $app->get('/customers/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'customers:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['count' => $c->get(CustomerRepository::class)->count()]);
        });

        // Admin-only `{id,name}` list for membership pickers (base user editor).
        $app->get('/admin/customers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['customers' => $c->get(CustomerRepository::class)->adminList()]);
        });

        // Directory CRUD.
        $app->get('/customers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'customers:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['customers' => $c->get(CustomerRepository::class)->all()]);
        });

        $app->post('/customers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'customers:write', $res)) !== null) {
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
        });

        $app->get('/customers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'customers:read', $res)) !== null) {
                return $deny;
            }
            $customer = $c->get(CustomerRepository::class)->find((int) $args['id']);
            return $customer === null
                ? self::json($res, ['error' => 'Not found'], 404)
                : self::json($res, $customer);
        });

        $app->patch('/customers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'customers:write', $res)) !== null) {
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
        });

        $app->delete('/customers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'customers:write', $res)) !== null) {
                return $deny;
            }
            $c->get(CustomerRepository::class)->delete((int) $args['id']);
            return self::json($res, ['ok' => true]);
        });
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

    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
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
