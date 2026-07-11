<?php

declare(strict_types=1);

/**
 * Generates tests/fixtures/key-parity.json from the reference Freshen\Key
 * implementation. The emitted strings are the frozen cross-language parity
 * contract (PARITY §6): the PHP KeyTest asserts Key still reproduces them, and
 * the TS port (FRSH-006) must produce byte-identical output for the same inputs.
 *
 * Regenerate intentionally (and review the diff) only when the key format changes
 * on purpose — a surprise diff here means a behavioural/BC change.
 *
 * Run in Docker: see the header of scripts/php-test.sh for the container pattern.
 *   php tests/fixtures/gen-key-fixtures.php > tests/fixtures/key-parity.json
 */

require __DIR__ . '/../../vendor/autoload.php';

use Freshen\Key;

// Case inputs only. Expected strings are derived from the reference Key below,
// so the fixture is the frozen output of the reference implementation.
$cases = [
    ['name' => 'simple-string-id',    'domain' => 'product', 'facet' => 'top-sellers', 'id' => 'sku-1',              'schemaVersion' => null, 'locale' => null],
    ['name' => 'int-id',              'domain' => 'product', 'facet' => 'top-sellers', 'id' => 42,                   'schemaVersion' => null, 'locale' => null],
    ['name' => 'schema-and-locale',   'domain' => 'product', 'facet' => 'detail',      'id' => 'sku-1',              'schemaVersion' => 'v2', 'locale' => 'en_US'],
    ['name' => 'schema-only',         'domain' => 'product', 'facet' => 'detail',      'id' => 'sku-1',              'schemaVersion' => 'v3', 'locale' => null],
    ['name' => 'locale-only',         'domain' => 'product', 'facet' => 'detail',      'id' => 'sku-1',              'schemaVersion' => null, 'locale' => 'fr_FR'],
    ['name' => 'urlencode-space-slash','domain' => 'shop',   'facet' => 'items',       'id' => 'a b/c',              'schemaVersion' => null, 'locale' => null],
    ['name' => 'urlencode-unicode',   'domain' => 'shop',    'facet' => 'items',       'id' => 'café',               'schemaVersion' => null, 'locale' => null],
    ['name' => 'array-id',            'domain' => 'product', 'facet' => 'list',        'id' => ['b' => 2, 'a' => 1], 'schemaVersion' => null, 'locale' => null],
    ['name' => 'array-id-nested',     'domain' => 'product', 'facet' => 'list',        'id' => ['z' => ['y' => 1, 'x' => 2], 'a' => 3], 'schemaVersion' => 'v1', 'locale' => 'en'],
    ['name' => 'array-id-scalars',    'domain' => 'search',  'facet' => 'results',     'id' => ['q' => 'shoes', 'page' => 2, 'sort' => 'price'], 'schemaVersion' => null, 'locale' => null],
];

$out = [];
foreach ($cases as $c) {
    $key = new Key($c['domain'], $c['facet'], $c['id'], $c['schemaVersion'], $c['locale']);
    $out[] = [
        'name'          => $c['name'],
        'domain'        => $c['domain'],
        'facet'         => $c['facet'],
        'id'            => $c['id'],
        'schemaVersion' => $c['schemaVersion'],
        'locale'        => $c['locale'],
        'key'           => $key->toString(),
        'prefix'        => $key->prefixString(),
        'idString'      => $key->idString(),
        'segments'      => $key->segments(),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
