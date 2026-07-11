<?php

declare(strict_types=1);

namespace Freshen\Tests;

use Freshen\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers Freshen\Key key-string construction. Two layers:
 *
 *  1) Fixture-driven parity oracle — tests/fixtures/key-parity.json holds the frozen
 *     expected strings the reference implementation produces. Any change to the key
 *     format breaks this (regression lock), and the TS port (FRSH-006) must reproduce
 *     the same strings byte-for-byte (PARITY §6). Regenerate the fixture on purpose
 *     via tests/fixtures/gen-key-fixtures.php and review the diff.
 *
 *  2) Independent literal assertions below — pin the human-verifiable cases and the
 *     canonicalisation semantics without relying on the generated file, so a bug that
 *     drifts both generator and format can't hide.
 */
final class KeyTest extends TestCase
{
    /** @return array<string, array{0: array<array-key, mixed>}> */
    public static function parityCases(): array
    {
        $path = __DIR__ . '/fixtures/key-parity.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read parity fixture: {$path}");
        }

        $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries)) {
            throw new \RuntimeException('Parity fixture must decode to an array.');
        }

        $out = [];
        foreach ($entries as $e) {
            if (!is_array($e) || !isset($e['name']) || !is_string($e['name'])) {
                throw new \RuntimeException('Each parity entry needs a string "name".');
            }
            $out[$e['name']] = [$e];
        }
        return $out;
    }

    /** @param array<array-key, mixed> $e */
    #[DataProvider('parityCases')]
    public function testMatchesFrozenParityFixture(array $e): void
    {
        $domain = $e['domain'];
        $facet  = $e['facet'];
        $sv     = $e['schemaVersion'];
        $loc    = $e['locale'];
        $id     = $e['id'];

        if (!is_string($domain) || !is_string($facet)) {
            self::fail('fixture domain/facet must be strings');
        }
        if ($sv !== null && !is_string($sv)) {
            self::fail('fixture schemaVersion must be string|null');
        }
        if ($loc !== null && !is_string($loc)) {
            self::fail('fixture locale must be string|null');
        }
        if (!is_string($id) && !is_int($id) && !is_array($id)) {
            self::fail('fixture id must be string|int|array');
        }

        $key = new Key($domain, $facet, $id, $sv, $loc);

        self::assertSame($e['key'], $key->toString(), 'toString mismatch');
        self::assertSame($e['key'], (string) $key, '__toString mismatch');
        self::assertSame($e['prefix'], $key->prefixString(), 'prefixString mismatch');
        self::assertSame($e['idString'], $key->idString(), 'idString mismatch');
        self::assertSame($e['segments'], $key->segments(), 'segments mismatch');
    }

    // --- Independent literal assertions (not derived from the fixture) ---

    public function testSimpleKeyAndAccessors(): void
    {
        $key = new Key('product', 'top-sellers', 42);

        self::assertSame('product/top-sellers/42', $key->toString());
        self::assertSame('product/top-sellers', $key->prefixString());
        self::assertSame('product', $key->domain());
        self::assertSame('top-sellers', $key->facet());
        self::assertNull($key->schemaVersion());
        self::assertNull($key->locale());
        self::assertSame('42', $key->idString());
        self::assertSame('42', $key->id());
        self::assertSame(['product', 'top-sellers'], $key->prefixSegments());
        self::assertSame(['product', 'top-sellers', '42'], $key->segments());
    }

    public function testSchemaVersionAndLocaleSegmentOrder(): void
    {
        $key = new Key('product', 'detail', 'sku-1', 'v2', 'en_US');

        // Order is domain / facet / schemaVersion / locale / id.
        self::assertSame('product/detail/v2/en_US/sku-1', $key->toString());
        self::assertSame('v2', $key->schemaVersion());
        self::assertSame('en_US', $key->locale());
    }

    public function testEmptyStringSchemaVersionAndLocaleAreTreatedAsUnset(): void
    {
        $key = new Key('product', 'detail', 'sku-1', '', '');

        self::assertNull($key->schemaVersion());
        self::assertNull($key->locale());
        self::assertSame('product/detail/sku-1', $key->toString());
    }

    public function testReservedCharactersAreRawUrlEncodedInTheKeyButRawInAccessors(): void
    {
        $key = new Key('shop', 'items', 'a b/c');

        // Encoded in the composed key string...
        self::assertSame('shop/items/a%20b%2Fc', $key->toString());
        // ...but idString() returns the raw, un-encoded id.
        self::assertSame('a b/c', $key->idString());
    }

    public function testArrayIdCanonicalisationIsOrderIndependent(): void
    {
        $a = new Key('product', 'list', ['b' => 2, 'a' => 1]);
        $b = new Key('product', 'list', ['a' => 1, 'b' => 2]);

        // Different insertion order must yield the identical deterministic key.
        self::assertSame($b->toString(), $a->toString());
        self::assertSame($b->idString(), $a->idString());
        self::assertStringStartsWith('j:', $a->idString(), 'default scheme is "j:"+base64url(JSON)');
    }

    public function testArrayIdCanonicalisationIsRecursive(): void
    {
        $a = new Key('p', 'f', ['z' => ['y' => 1, 'x' => 2], 'a' => 3]);
        $b = new Key('p', 'f', ['a' => 3, 'z' => ['x' => 2, 'y' => 1]]);

        // Nested arrays are sorted too, so both orderings collapse to one key.
        self::assertSame($b->toString(), $a->toString());

        // The encoded payload decodes back to the fully-sorted JSON.
        $b64 = substr($a->idString(), 2); // strip "j:"
        $json = base64_decode(strtr($b64, '-_', '+/'), true);
        self::assertSame('{"a":3,"z":{"x":2,"y":1}}', $json);
    }

    public function testArrayIdIsReturnedCanonicalisedFromId(): void
    {
        $key = new Key('product', 'list', ['b' => 2, 'a' => 1]);

        // id() returns the canonicalised (ksorted) array, not the raw input order.
        self::assertSame(['a' => 1, 'b' => 2], $key->id());
    }

    #[DataProvider('blankSegmentProvider')]
    public function testBlankSegmentsAreRejected(string $domain, string $facet): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Key($domain, $facet, 'id');
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function blankSegmentProvider(): array
    {
        return [
            'empty domain'      => ['', 'facet'],
            'whitespace domain' => ['   ', 'facet'],
            'empty facet'       => ['domain', ''],
            'whitespace facet'  => ['domain', "\t"],
        ];
    }

    public function testIdStringifyHookCanBeOverridden(): void
    {
        $key = new class ('product', 'list', ['b' => 2, 'a' => 1]) extends Key {
            /** @param array<array-key, mixed> $id */
            protected function idStringify(array $id): string
            {
                return 'h:' . hash('sha256', (string) json_encode($id));
            }
        };

        // The subclass scheme replaces the default "j:" one.
        self::assertStringStartsWith('h:', $key->idString());
        // Canonicalisation still runs before the hook: sorted JSON is hashed.
        $expected = 'h:' . hash('sha256', '{"a":1,"b":2}');
        self::assertSame($expected, $key->idString());
    }
}
