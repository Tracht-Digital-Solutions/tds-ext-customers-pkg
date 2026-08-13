<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/CustomersApiDocsTest.php` fails the build if the documented set and
 * the registered set drift apart in either direction.
 *
 * ### The `/customers*` twins are GENERATED, not written out
 *
 * Every directory route is mounted at both `/companies…` (current) and
 * `/customers…` (deprecated for one release). Hand-writing both would be
 * fourteen entries where seven differ only in a path segment — and the pair
 * that eventually disagrees is exactly the one nobody re-reads. The aliases are
 * derived from the canonical list at the bottom of this file and marked
 * deprecated; deleting the derivation is all it takes to retire them.
 */

declare(strict_types=1);

$payload = [
    ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Firmenname, auf 200 Zeichen gekürzt.'],
    ['in' => 'body', 'name' => 'email', 'type' => 'string', 'description' => 'Wird kleingeschrieben und validiert; leer bedeutet NULL.'],
    ['in' => 'body', 'name' => 'phone', 'type' => 'string', 'description' => 'Optional, auf 40 Zeichen gekürzt.'],
    ['in' => 'body', 'name' => 'note', 'type' => 'string', 'description' => 'Optional, auf 2.000 Zeichen gekürzt.'],
];

$idParam = [
    ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id der Firma.'],
];

/**
 * The canonical directory routes. Each gets a deprecated `/customers…` twin.
 *
 * @var list<array<string,mixed>> $canonical
 */
$canonical = [
    [
        'method' => 'GET',
        'pattern' => '/companies/summary',
        'summary' => 'Anzahl der Firmen',
        'description' => 'Die `dataEndpoint`-Route des Dashboard-Widgets.',
        'permission' => 'companies:read',
        'responses' => [
            ['status' => 200, 'description' => '`{count: <Anzahl>}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `companies:read`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/companies',
        'summary' => 'Schlanke `{id, name}`-Liste für Mitgliedschafts-Auswahlen',
        'description' => 'Die Firmenliste, die der Benutzer-Editor der Basis beim Bearbeiten '
            . 'von Mitgliedschaften lädt. **Admin-only, nicht `companies:read`** — wer '
            . 'Mitgliedschaften vergibt, ist ohnehin Admin, und die Liste soll nicht '
            . 'über den Umweg eines Leserechts an jeden Portalnutzer fallen. Die Antwort '
            . 'trägt den Schlüssel `companies` **und** `customers`, solange die alte '
            . 'Schreibweise unterstützt wird.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{companies: [{id, name}], customers: […]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/companies',
        'summary' => 'Firmenverzeichnis',
        'description' => 'Alle Firmen, nach Name sortiert.',
        'permission' => 'companies:read',
        'responses' => [
            ['status' => 200, 'description' => '`{companies: [{id, name, email, phone, note, created_at}], customers: […]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `companies:read`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/companies',
        'summary' => 'Firma anlegen',
        'description' => 'E-Mail ist optional, aber eindeutig, wenn gesetzt.',
        'permission' => 'companies:write',
        'params' => $payload,
        'responses' => [
            ['status' => 201, 'description' => '`{id: <neue Id>}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `companies:write`.'],
            ['status' => 409, 'description' => 'E-Mail bereits vergeben.'],
            ['status' => 422, 'description' => '`name` fehlt oder `email` ist keine gültige Adresse.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/companies/{id:[0-9]+}',
        'summary' => 'Eine Firma lesen',
        'permission' => 'companies:read',
        'params' => $idParam,
        'responses' => [
            ['status' => 200, 'description' => '`{id, name, email, phone, note, created_at}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `companies:read`.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
        ],
    ],
    [
        'method' => 'PATCH',
        'pattern' => '/companies/{id:[0-9]+}',
        'summary' => 'Firma ändern',
        'description' => 'Ersetzt alle vier Felder; die E-Mail-Eindeutigkeit ignoriert die '
            . 'eigene Zeile.',
        'permission' => 'companies:write',
        'params' => array_merge($idParam, $payload),
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `companies:write`.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 409, 'description' => 'E-Mail bereits vergeben.'],
            ['status' => 422, 'description' => '`name` fehlt oder `email` ist keine gültige Adresse.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/companies/{id:[0-9]+}',
        'summary' => 'Firma löschen',
        'description' => 'Meldet auch dann Erfolg, wenn die Id nicht existierte — löschen '
            . 'ist idempotent.',
        'permission' => 'companies:write',
        'params' => $idParam,
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `companies:write`.'],
        ],
    ],
];

/**
 * The deprecated twins. Same handler, same gate, older path.
 *
 * @var list<array<string,mixed>> $aliases
 */
$aliases = array_map(
    static function (array $entry): array {
        $entry['pattern'] = str_replace(
            ['/companies', '/admin/companies'],
            ['/customers', '/admin/customers'],
            $entry['pattern'],
        );
        $entry['summary'] = '(veraltet) ' . $entry['summary'];
        $entry['description'] = 'Alter Pfad aus der Zeit vor der Umbenennung '
            . '`customer` → `company`. Identisch zur `/companies…`-Route und '
            . '**wird im Folge-Release entfernt** — neue Aufrufer nutzen den '
            . 'neuen Pfad. '
            . ($entry['description'] ?? '');

        return $entry;
    },
    $canonical,
);

return array_merge($canonical, $aliases, [
    [
        'method' => 'GET',
        'pattern' => '/me/companies',
        'summary' => 'Die eigenen Firmen des angemeldeten Benutzers',
        'description' => 'Liefert `{id, name, active}` für **genau die** Firmen, deren '
            . 'Mitgliedschaft im verifizierten Token steht — die Quelle für den Firmennamen '
            . 'im Profilmenü der Shell. Nötig, weil `GET /admin/companies` bewusst admin-only '
            . 'ist: ein Portalnutzer könnte sonst nicht einmal den Namen seiner eigenen Firma '
            . 'auflösen und das Menü müsste „Firma #7" anzeigen. Kein Rechte-Gate über die '
            . 'Anmeldung hinaus — der Name der eigenen Firma ist kein `companies:read`-Stoff, '
            . 'und ein solches Gate hieße, dass jeder Portalnutzer das Verzeichnis-Leserecht '
            . 'braucht, nur um eine Kopfzeile zu sehen. **Ein Admin bekommt eine leere Liste**: '
            . 'seine Reichweite ist „jede Firma", was nicht dasselbe ist wie einer anzugehören.',
        'responses' => [
            ['status' => 200, 'description' => '`{companies: [{id, name, active}]}`; leer für Admins.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
        ],
    ],
]);
