<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\SearchReplace;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\SearchReplace\SerializedWalker;

final class SerializedWalkerTest extends TestCase
{
    public function testWalkPlainString(): void
    {
        $result = SerializedWalker::walk('hello', fn(string $s) => strtoupper($s));
        $this->assertSame('HELLO', $result);
    }

    public function testWalkArrayReplacesInAllLeaves(): void
    {
        $input = ['a' => 'one', 'b' => 'two', 'c' => ['x' => 'nested']];
        $result = SerializedWalker::walk($input, fn(string $s) => str_replace('o', '0', $s));
        $this->assertSame([
            'a' => '0ne',
            'b' => 'tw0',
            'c' => ['x' => 'nested'],
        ], $result);
    }

    public function testIntegerAndBoolAreLeftAlone(): void
    {
        // Use integer keys so the callback does not transform keys (only values are checked).
        $input = [0 => 42, 1 => true, 2 => false, 3 => null];
        $result = SerializedWalker::walk($input, fn(string $s) => 'TOUCHED');
        $this->assertSame($input, $result);
    }

    public function testArrayKeysAreTransformedToo(): void
    {
        $input = ['http://old.com/key' => 'value'];
        $result = SerializedWalker::walk(
            $input,
            fn(string $s) => str_replace('http://old.com', 'https://new.com', $s)
        );
        $this->assertArrayHasKey('https://new.com/key', $result);
        $this->assertSame('value', $result['https://new.com/key']);
    }

    public function testDeeplyNestedArray(): void
    {
        $input = ['a' => ['b' => ['c' => ['d' => ['e' => 'deep value']]]]];
        $result = SerializedWalker::walk($input, fn(string $s) => str_replace(' ', '_', $s));
        $this->assertSame('deep_value', $result['a']['b']['c']['d']['e']);
    }

    public function testStdClassObjectPropertiesAreTransformed(): void
    {
        $obj = new \stdClass();
        $obj->url = 'http://old.com';
        $obj->name = 'widget';

        $result = SerializedWalker::walk(
            $obj,
            fn(string $s) => str_replace('http://old.com', 'https://new.com', $s)
        );

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('https://new.com', $result->url);
        $this->assertSame('widget', $result->name);
    }

    public function testSerializeRoundTripCompatibleAfterReplace(): void
    {
        // Simulate the real flow: unserialize → walk → serialize → unserialize.
        $original = serialize(['url' => 'http://old.com', 'n' => 5, 'nested' => ['href' => 'http://old.com/page']]);

        $decoded = unserialize($original);
        $walked = SerializedWalker::walk(
            $decoded,
            fn(string $s) => str_replace('http://old.com', 'https://new.com', $s)
        );
        $reserialized = serialize($walked);

        // The reserialized string must itself be valid and produce the expected result.
        $finalDecoded = unserialize($reserialized);
        $this->assertSame('https://new.com', $finalDecoded['url']);
        $this->assertSame(5, $finalDecoded['n']);
        $this->assertSame('https://new.com/page', $finalDecoded['nested']['href']);
    }

    public function testCallbackCanReturnSameString(): void
    {
        // No-op callback: structure must be byte-identical after a serialize round-trip.
        $input = ['x' => 'y', 'z' => [1, 2, 'three']];
        $result = SerializedWalker::walk($input, fn(string $s) => $s);
        $this->assertSame(serialize($input), serialize($result));
    }
}
