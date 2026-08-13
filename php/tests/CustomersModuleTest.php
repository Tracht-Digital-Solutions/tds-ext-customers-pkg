<?php
declare(strict_types=1);

namespace Tds\Ext\Customers\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Customers\CustomersModule;
use Tds\Frontend\Contract\MultiCompanyContext;
use Tds\Frontend\Contract\UserContext;

/** A configurable UserContext double (no live JWT needed). */
class FakeUser implements UserContext
{
    /** @param string[] $perms */
    public function __construct(
        private bool $auth = true,
        private bool $admin = false,
        private array $perms = [],
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->auth;
    }

    public function userId(): ?int
    {
        return 1;
    }

    public function email(): ?string
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return $this->perms;
    }

    public function has(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->perms, true);
    }

    public function activeCompanyId(): ?int
    {
        return $this->activeCompany;
    }

    public ?int $activeCompany = null;
}

/**
 * A principal that DOES carry the optional capability. Kept separate from
 * {@see FakeUser} on purpose: the whole point of `MultiCompanyContext` being a
 * companion interface is that a plain `UserContext` still works, so both
 * shapes have to exist in the suite.
 */
final class FakeMultiCompanyUser extends FakeUser implements MultiCompanyContext
{
    /** @param list<int> $ids */
    public function __construct(
        private array $ids = [],
        bool $auth = true,
        bool $admin = false,
        array $perms = [],
    ) {
        parent::__construct($auth, $admin, $perms);
    }

    public function companyIds(): array
    {
        return $this->ids;
    }
}

/**
 * Route + RBAC coverage that needs no DB: auth + payload validation short-circuit
 * before any repository access. Data paths are covered when deployed against MySQL.
 */
final class CustomersModuleTest extends TestCase
{
    private function appWith(UserContext $user): App
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new CustomersModule())->register($app);
        return $app;
    }

    private function get(App $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /** @param array<string,mixed> $body */
    private function post(App $app, string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
        return $app->handle($req);
    }

    public function testMetadata(): void
    {
        $module = new CustomersModule();
        self::assertSame('customers', $module->id());
        $perms = array_map(static fn ($p): string => $p->id, $module->permissions());
        self::assertSame(['customers:read', 'customers:write'], $perms);
        self::assertDirectoryExists($module->migrations()[0]);
    }

    public function testUnauthenticatedGetsUnauthorized(): void
    {
        self::assertSame(401, $this->get($this->appWith(new FakeUser(auth: false)), '/customers')->getStatusCode());
    }

    public function testReadWithoutPermissionForbidden(): void
    {
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: [])), '/customers')->getStatusCode());
    }

    public function testAdminListRequiresAdmin(): void
    {
        $res = $this->get($this->appWith(new FakeUser(perms: ['customers:read'])), '/admin/customers');
        self::assertSame(403, $res->getStatusCode());
    }

    public function testCreateRequiresWrite(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['customers:read'])), '/customers', ['name' => 'ACME']);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testCreateValidatesName(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['customers:write'])), '/customers', ['name' => '']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testCreateValidatesEmail(): void
    {
        $res = $this->post(
            $this->appWith(new FakeUser(perms: ['customers:write'])),
            '/customers',
            ['name' => 'ACME', 'email' => 'not-an-email'],
        );
        self::assertSame(422, $res->getStatusCode());
    }

    public function testMyCompaniesRequiresOnlyASession(): void
    {
        // Deliberately NOT gated on customers:read — your own company's name
        // is not directory-read material, and requiring that permission would
        // mean every portal user needs it just to render a header.
        self::assertSame(
            401,
            $this->get($this->appWith(new FakeUser(auth: false)), '/me/companies')->getStatusCode(),
        );
        self::assertSame(
            200,
            $this->get($this->appWith(new FakeMultiCompanyUser(perms: [])), '/me/companies')->getStatusCode(),
        );
    }

    public function testMyCompaniesIsEmptyForAPrincipalWithoutTheCapability(): void
    {
        // The contract's optional-capability pattern: a plain UserContext must
        // still work. `instanceof` on a class that is not implemented is
        // silently false, so this asserts the DEGRADED path is reached rather
        // than erroring — the failure mode it guards is a green suite that
        // proves nothing because the interface was never resolvable.
        $res = $this->get($this->appWith(new FakeUser(perms: ['customers:read'])), '/me/companies');

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['companies' => []], $this->body($res));
    }

    public function testMyCompaniesIsEmptyForAnAdminAndTouchesNoDatabase(): void
    {
        // An admin's reach is "any company", which is not belonging to one.
        // The container here has NO PDO binding at all, so if the route ever
        // stopped short-circuiting and resolved the repository, this would
        // blow up rather than quietly pass — which is the point: the shell
        // calls this on every page load.
        $res = $this->get($this->appWith(new FakeMultiCompanyUser(admin: true)), '/me/companies');

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['companies' => []], $this->body($res));
    }

    public function testTheCapabilityInterfaceIsActuallyResolvable(): void
    {
        // Guards the trap this feature was written into: the vendored contract
        // can lag behind, `instanceof` fails silently, and every test above
        // still passes while the route returns [] forever in production.
        self::assertTrue(
            interface_exists(MultiCompanyContext::class),
            'MultiCompanyContext missing — the vendored contract is stale, so /me/companies '
            . 'would silently return [] for everyone. Run composer update for the contract.',
        );
    }

    /** @return array<string,mixed> */
    private function body(\Psr\Http\Message\ResponseInterface $res): array
    {
        $res->getBody()->rewind();
        return json_decode($res->getBody()->getContents(), true);
    }
}
