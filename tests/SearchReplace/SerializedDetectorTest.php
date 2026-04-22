<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\SearchReplace;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\SearchReplace\SerializedDetector;

final class SerializedDetectorTest extends TestCase
{
    public function testPlainStringIsNotSerialized(): void
    {
        $this->assertFalse(SerializedDetector::isSerialized('hello world'));
    }

    public function testEmptyStringIsNotSerialized(): void
    {
        $this->assertFalse(SerializedDetector::isSerialized(''));
    }

    public function testWhitespaceIsNotSerialized(): void
    {
        $this->assertFalse(SerializedDetector::isSerialized('   '));
    }

    public function testSerializedStringIsDetected(): void
    {
        $this->assertTrue(SerializedDetector::isSerialized(serialize('http://example.com')));
    }

    public function testSerializedArrayIsDetected(): void
    {
        $this->assertTrue(SerializedDetector::isSerialized(serialize(['a' => 1, 'b' => [2, 3]])));
    }

    public function testSerializedIntegerIsDetected(): void
    {
        $this->assertTrue(SerializedDetector::isSerialized(serialize(42)));
    }

    public function testSerializedNullIsDetected(): void
    {
        $this->assertTrue(SerializedDetector::isSerialized(serialize(null)));
    }

    public function testSerializedFalseIsDetected(): void
    {
        $this->assertTrue(SerializedDetector::isSerialized(serialize(false)));
    }

    public function testSerializedTrueIsDetected(): void
    {
        $this->assertTrue(SerializedDetector::isSerialized(serialize(true)));
    }

    public function testSerializedObjectIsDetected(): void
    {
        $obj = new \stdClass();
        $obj->foo = 'bar';
        $this->assertTrue(SerializedDetector::isSerialized(serialize($obj)));
    }

    public function testMalformedSerializedLooksLikeButFailsToParse(): void
    {
        // Looks serialized but length prefix is wrong.
        $this->assertFalse(SerializedDetector::isSerialized('s:5:"hello world";'));
    }

    public function testJsonIsNotSerialized(): void
    {
        $this->assertFalse(SerializedDetector::isSerialized('{"url":"http://example.com"}'));
    }

    public function testStringStartingWithBIsNotSerialized(): void
    {
        $this->assertFalse(SerializedDetector::isSerialized('banana'));
    }

    public function testShortFastRejection(): void
    {
        // Strings too short to possibly be serialized (< 4 chars) are rejected fast.
        $this->assertFalse(SerializedDetector::isSerialized('x'));
        $this->assertFalse(SerializedDetector::isSerialized('ab'));
        $this->assertFalse(SerializedDetector::isSerialized('abc'));
    }
}
