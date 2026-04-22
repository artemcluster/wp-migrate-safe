<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\SearchReplace;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\SearchReplace\Replacer;
use WpMigrateSafe\SearchReplace\Exception\UnserializableException;

final class ReplacerTest extends TestCase
{
    public function testPlainStringReplacement(): void
    {
        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply('Visit http://old.com today.');

        $this->assertSame('Visit https://new.com today.', $result->value());
        $this->assertSame(1, $result->replacements());
        $this->assertTrue($result->changed());
    }

    public function testPlainStringMultipleReplacements(): void
    {
        $r = new Replacer('foo', 'bar');
        $result = $r->apply('foo foo foo');

        $this->assertSame('bar bar bar', $result->value());
        $this->assertSame(3, $result->replacements());
    }

    public function testNoMatchReturnsUnchanged(): void
    {
        $r = new Replacer('xyz', 'abc');
        $result = $r->apply('hello world');

        $this->assertSame('hello world', $result->value());
        $this->assertSame(0, $result->replacements());
        $this->assertFalse($result->changed());
    }

    public function testSerializedStringIsSafelyReplaced(): void
    {
        $original = serialize(['url' => 'http://old.com', 'name' => 'widget']);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        // Output must still be a valid serialized string that unserializes to the replaced structure.
        $this->assertNotSame($original, $result->value());
        $decoded = unserialize($result->value());
        $this->assertSame('https://new.com', $decoded['url']);
        $this->assertSame('widget', $decoded['name']);
    }

    public function testSerializedStringWithDifferentLengthReplacementStaysValid(): void
    {
        // "http://old.com" is 14 chars; "https://new.longer-domain.com" is 29 chars.
        // A naïve str_replace would break the s:LENGTH: prefix. Our Replacer must rewrite lengths.
        $original = serialize(['url' => 'http://old.com']);

        $r = new Replacer('http://old.com', 'https://new.longer-domain.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertSame('https://new.longer-domain.com', $decoded['url']);
    }

    public function testDeeplyNestedSerializedArray(): void
    {
        $data = [
            'site' => [
                'urls' => [
                    'home' => 'http://old.com',
                    'api'  => 'http://old.com/api',
                ],
                'meta' => ['unrelated' => 'leave me alone'],
            ],
        ];
        $original = serialize($data);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertSame('https://new.com', $decoded['site']['urls']['home']);
        $this->assertSame('https://new.com/api', $decoded['site']['urls']['api']);
        $this->assertSame('leave me alone', $decoded['site']['meta']['unrelated']);
    }

    public function testSerializedObjectIsReplaced(): void
    {
        $obj = new \stdClass();
        $obj->href = 'http://old.com/page';
        $obj->title = 'hello';
        $original = serialize($obj);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertSame('https://new.com/page', $decoded->href);
        $this->assertSame('hello', $decoded->title);
    }

    public function testJsonDecodeEncodeRoundTrip(): void
    {
        // A JSON value in a text column: wp-admin sometimes stores JSON (blocks content, REST).
        $original = json_encode(['url' => 'http://old.com', 'pages' => ['http://old.com/a', 'http://old.com/b']]);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        // JSON replacement is allowed to be string-level (JSON keeps its own quoting).
        $this->assertStringNotContainsString('http://old.com', $result->value());
        $this->assertStringContainsString('https://new.com', $result->value());
        $this->assertSame(3, $result->replacements());
    }

    public function testMalformedSerializedFallsBackToPlainReplace(): void
    {
        // Starts with "s:" but length is wrong → SerializedDetector reports false → plain replace applies.
        $input = 's:5:"http://old.com really long";';
        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($input);

        $this->assertStringContainsString('https://new.com', $result->value());
    }

    public function testEmptyHaystackReturnsEmpty(): void
    {
        $r = new Replacer('foo', 'bar');
        $result = $r->apply('');

        $this->assertSame('', $result->value());
        $this->assertSame(0, $result->replacements());
    }

    public function testEmptySearchStringReturnsUnchanged(): void
    {
        // Empty search is a user error — we refuse to infinite-loop.
        $this->expectException(\InvalidArgumentException::class);
        new Replacer('', 'anything');
    }

    public function testReplacementCountForSerializedCountsLeafReplacements(): void
    {
        $data = serialize([
            'a' => 'http://old.com',
            'b' => 'http://old.com/x',
            'c' => 'unrelated',
        ]);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($data);

        $this->assertSame(2, $result->replacements());
    }
}
