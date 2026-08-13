<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/CustomersApiDocsTest.php` fails the build if the documented set and
 * the registered set drift apart in either direction.
 */

declare(strict_types=1);

$payload = [
    ['in' => 'body', 'name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Firmenname, auf 200 Zeichen gekürzt.'],
    ['in' => 'body', 'name' => 'email', 'type' => 'string', 'description' => 'Wird kleingeschrieben und validiert; leer bedeutet NULL.'],
    ['in' => 'body', 'name' => 'phone', 'type' => 'string', 'description' => 'Optional, auf 40 Zeichen gekürzt.'],
    ['in' => 'body', 'name' => 'note', 'type' => 'string', 'description' => 'Optional, auf 2.000 Zeichen gekürzt.'],
];

return [
    [
        'method' => 'GET',
        'pattern' => '/customers/summary',
        'summary' => 'Anzahl der Kunden',
        'description' => 'Die `dataEndpoint`-Route des Dashboard-Widgets.',
        'permission' => 'customers:read',
        'responses' => [
            ['status' => 200, 'description' => '`{count: <Anzahl>}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `customers:read`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/customers',
        'summary' => 'Schlanke `{id, name}`-Liste für Mitgliedschafts-Auswahlen',
        'description' => 'Die Firmenliste, die der Nutzer-Editor der Basis beim Bearbeiten '
            . 'von Mitgliedschaften lädt. **Admin-only, nicht `customers:read`** — wer '
            . 'Mitgliedschaften vergibt, ist ohnehin Admin, und die Liste soll nicht '
            . 'über den Umweg eines Leserechts an jeden Portalnutzer fallen.',
        'auth' => 'admin',
        'responses' => [
            ['status' => 200, 'description' => '`{customers: [{id, name}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Angemeldet, aber kein Admin.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/customers',
        'summary' => 'Kundenverzeichnis lesen',
        'permission' => 'customers:read',
        'responses' => [
            ['status' => 200, 'description' => '`{customers: [{id, name, email, phone, note, …}]}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `customers:read`.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/customers',
        'summary' => 'Kunden anlegen',
        'description' => 'Die E-Mail-Adresse ist eindeutig, sofern gesetzt — eine bereits '
            . 'vergebene ergibt 409, nicht 422: die Eingabe war formal in Ordnung.',
        'permission' => 'customers:write',
        'params' => $payload,
        'responses' => [
            ['status' => 201, 'description' => '`{id}` des angelegten Kunden.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `customers:write`.'],
            ['status' => 409, 'description' => 'E-Mail bereits vergeben.'],
            ['status' => 422, 'description' => '`name` fehlt oder `email` ist keine gültige Adresse.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/customers/{id:[0-9]+}',
        'summary' => 'Einen Kunden lesen',
        'permission' => 'customers:read',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id des Kunden.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Der Kundendatensatz.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `customers:read`.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
        ],
    ],
    [
        'method' => 'PATCH',
        'pattern' => '/customers/{id:[0-9]+}',
        'summary' => 'Kunden ändern',
        'description' => 'Erwartet den vollständigen Datensatz — nicht gesendete Felder '
            . 'werden geleert, nicht beibehalten. Die Eindeutigkeitsprüfung der E-Mail '
            . 'schließt den eigenen Datensatz aus.',
        'permission' => 'customers:write',
        'params' => array_merge(
            [['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id des Kunden.']],
            $payload,
        ),
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `customers:write`.'],
            ['status' => 404, 'description' => 'Unbekannte Id.'],
            ['status' => 409, 'description' => 'E-Mail bereits an einen anderen Kunden vergeben.'],
            ['status' => 422, 'description' => '`name` fehlt oder `email` ist keine gültige Adresse.'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/customers/{id:[0-9]+}',
        'summary' => 'Kunden löschen',
        'description' => 'Meldet auch dann Erfolg, wenn die Id nicht existierte — löschen '
            . 'ist idempotent.',
        'permission' => 'customers:write',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Id des Kunden.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `customers:write`.'],
        ],
    ],
];
