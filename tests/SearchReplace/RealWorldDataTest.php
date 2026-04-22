<?php
declare(strict_types=1);

namespace WpMigrateSafe\Tests\SearchReplace;

use PHPUnit\Framework\TestCase;
use WpMigrateSafe\SearchReplace\Replacer;

final class RealWorldDataTest extends TestCase
{
    public function testWooCommerceOrderMetaShape(): void
    {
        // Realistic fragment: `_product_permalink` line item meta + shipping object.
        $data = [
            'line_items' => [
                [
                    'name' => 'Widget',
                    'product_permalink' => 'http://old.com/product/widget',
                    'meta_data' => [
                        (object) [
                            'key' => '_download_url',
                            'value' => 'http://old.com/downloads/file.zip',
                        ],
                    ],
                ],
            ],
            'shipping' => (object) [
                'url' => 'http://old.com/api/shipping',
                'tracking' => null,
                'cost' => 9.99,
            ],
        ];
        $original = serialize($data);

        $r = new Replacer('http://old.com', 'https://new.shop.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertSame('https://new.shop.com/product/widget', $decoded['line_items'][0]['product_permalink']);
        $this->assertSame('https://new.shop.com/downloads/file.zip', $decoded['line_items'][0]['meta_data'][0]->value);
        $this->assertSame('https://new.shop.com/api/shipping', $decoded['shipping']->url);
        $this->assertNull($decoded['shipping']->tracking);
        $this->assertSame(9.99, $decoded['shipping']->cost);
    }

    public function testAcfFieldGroupWithRepeater(): void
    {
        // ACF repeater: array of rows, each with image URL + sub-repeater.
        $data = [
            'fields' => [
                [
                    'name' => 'gallery',
                    'items' => [
                        ['image' => 'http://old.com/wp-content/uploads/a.jpg', 'caption' => 'One'],
                        ['image' => 'http://old.com/wp-content/uploads/b.jpg', 'caption' => 'Two'],
                    ],
                ],
                [
                    'name' => 'links',
                    'items' => [
                        ['href' => 'http://old.com/about', 'label' => 'About'],
                    ],
                ],
            ],
        ];
        $original = serialize($data);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertSame('https://new.com/wp-content/uploads/a.jpg', $decoded['fields'][0]['items'][0]['image']);
        $this->assertSame('https://new.com/wp-content/uploads/b.jpg', $decoded['fields'][0]['items'][1]['image']);
        $this->assertSame('https://new.com/about', $decoded['fields'][1]['items'][0]['href']);
        // Captions untouched.
        $this->assertSame('One', $decoded['fields'][0]['items'][0]['caption']);
    }

    public function testGutenbergBlockJsonAttributes(): void
    {
        // Gutenberg stores block attributes as JSON embedded inside HTML comments in post_content.
        // The full post_content is a plain string to the DB — JSON inside it is string-level replaced.
        $post_content = <<<HTML
        <!-- wp:image {"url":"http://old.com/img.jpg","id":42} -->
        <figure><img src="http://old.com/img.jpg" /></figure>
        <!-- /wp:image -->
        HTML;

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($post_content);

        $this->assertStringNotContainsString('http://old.com', $result->value());
        $this->assertStringContainsString('"url":"https://new.com/img.jpg"', $result->value());
        $this->assertStringContainsString('src="https://new.com/img.jpg"', $result->value());
        $this->assertSame(2, $result->replacements());
    }

    public function testMixedBooleanNullIntegerLeavesUntouched(): void
    {
        $data = [
            'enabled' => true,
            'disabled' => false,
            'missing' => null,
            'count' => 42,
            'ratio' => 1.5,
            'url' => 'http://old.com',
        ];
        $original = serialize($data);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertTrue($decoded['enabled']);
        $this->assertFalse($decoded['disabled']);
        $this->assertNull($decoded['missing']);
        $this->assertSame(42, $decoded['count']);
        $this->assertSame(1.5, $decoded['ratio']);
        $this->assertSame('https://new.com', $decoded['url']);
    }

    public function testUtf8MultibyteContent(): void
    {
        $data = [
            'заголовок' => 'Відвідайте http://old.com сьогодні',
            'デモ' => 'http://old.com/日本語ページ',
            'emoji' => 'check 🔥 at http://old.com 🚀',
        ];
        $original = serialize($data);

        $r = new Replacer('http://old.com', 'https://new.com');
        $result = $r->apply($original);

        $decoded = unserialize($result->value());
        $this->assertSame('Відвідайте https://new.com сьогодні', $decoded['заголовок']);
        $this->assertSame('https://new.com/日本語ページ', $decoded['デモ']);
        $this->assertSame('check 🔥 at https://new.com 🚀', $decoded['emoji']);
    }

    public function testPerformanceOnLargeButSimpleString(): void
    {
        // 5 MB of content with 10,000 occurrences of the search term.
        $chunk = 'lorem ipsum http://old.com dolor sit amet ';
        $value = str_repeat($chunk, 10000); // ~420 KB
        $expectedCount = 10000;

        $r = new Replacer('http://old.com', 'https://new.com');
        $start = microtime(true);
        $result = $r->apply($value);
        $elapsed = microtime(true) - $start;

        $this->assertSame($expectedCount, $result->replacements());
        $this->assertLessThan(2.0, $elapsed, 'Replacement of 10k URLs in 420 KB took longer than 2s.');
    }
}
