<?php
declare(strict_types=1);

namespace WpMigrateSafe\SearchReplace;

/**
 * Heuristic detector for PHP-serialized strings.
 *
 * A string is considered serialized if it starts with a recognizable type tag
 * (`a:`, `s:`, `i:`, `d:`, `b:`, `N;`, `O:`, `C:`) AND `unserialize()` successfully
 * parses it. The boolean-false and null cases are handled explicitly because
 * `unserialize()` returns `false` for "b:0;" even though parsing succeeded.
 */
final class SerializedDetector
{
    public static function isSerialized(string $value): bool
    {
        // Shortest valid serialized payload is "N;" (2 chars) or "b:0;" / "b:1;" (4 chars).
        $len = strlen($value);
        if ($len < 2) {
            return false;
        }

        // Fast rejection: check first two bytes for a known tag.
        $tag = substr($value, 0, 2);
        if (!self::isKnownTag($tag, $value)) {
            return false;
        }

        // Explicit handling for boolean false and null (unserialize returns falsy).
        if ($value === 'N;') {
            return true;
        }
        if ($value === 'b:0;') {
            return true;
        }

        // Suppress warnings from malformed inputs (unserialize emits E_NOTICE).
        $previousLevel = error_reporting(0);
        try {
            $result = @unserialize($value, ['allowed_classes' => true]);
        } finally {
            error_reporting($previousLevel);
        }

        return $result !== false;
    }

    private static function isKnownTag(string $tag, string $value): bool
    {
        static $tags = ['a:', 's:', 'i:', 'd:', 'b:', 'O:', 'C:'];
        if (in_array($tag, $tags, true)) {
            return true;
        }
        return $value === 'N;' || substr($value, 0, 2) === 'N;';
    }
}
