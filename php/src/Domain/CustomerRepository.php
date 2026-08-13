<?php
declare(strict_types=1);

namespace Tds\Ext\Customers\Domain;

use PDO;

/**
 * The panel's canonical company directory (`company`, renamed from `customer`).
 * All access via
 * the core shared PDO. `adminList()` is the lightweight `{id,name}` list the
 * base user-management consumes for company-membership editing (replacing the
 * legacy `tds-customer-api` `GET /admin/customers`).
 */
final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, name, email, phone, note, created_at FROM company ORDER BY name ASC'
        )->fetchAll();
        return array_map([self::class, 'map'], $rows);
    }

    /** Lightweight `{id,name}` list for membership pickers. @return list<array{id:int,name:string}> */
    public function adminList(): array
    {
        $rows = $this->pdo->query('SELECT id, name FROM company ORDER BY name ASC')->fetchAll();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $rows);
    }

    /**
     * `{id,name}` for a specific set of ids — the caller's OWN memberships.
     *
     * Deliberately not `adminList()` filtered in PHP: that would read the whole
     * directory to hand back two rows, and this route is reachable by every
     * portal user. Ids are bound as integers, never interpolated.
     *
     * @param list<int> $ids
     * @return list<array{id:int,name:string}>
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name FROM company WHERE id IN ({$placeholders}) ORDER BY name ASC"
        );
        $stmt->execute($ids);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, phone, note, created_at FROM company WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::map($row);
    }

    /** @param array{name:string,email:?string,phone:?string,note:?string} $d */
    public function create(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO company (name, email, phone, note) VALUES (:name, :email, :phone, :note)'
        );
        $stmt->execute([':name' => $d['name'], ':email' => $d['email'], ':phone' => $d['phone'], ':note' => $d['note']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{name:string,email:?string,phone:?string,note:?string} $d */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE company SET name = :name, email = :email, phone = :phone, note = :note WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':name' => $d['name'], ':email' => $d['email'], ':phone' => $d['phone'], ':note' => $d['note']]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM company WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM company')->fetchColumn();
    }

    /** Whether an email is already taken by a different company (unique-guard). */
    public function emailTakenBy(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM company WHERE email = :email';
        $params = [':email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $r */
    private static function map(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => $r['email'] !== null ? (string) $r['email'] : null,
            'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at' => isset($r['created_at']) ? (string) $r['created_at'] : null,
        ];
    }
}
