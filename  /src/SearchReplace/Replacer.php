<?php
declare(strict_types=1);

namespace WpMigrateSafe\SearchReplace;

use InvalidArgumentException;

/**
 * Serialize-aware string search-replace.
 *
 * Handles:
 *   - PHP-serialized values (unserialize → recursively replace → reserialize with correct lengths)
 *   - JSON values (re-encoding isn't required because JSON strings use quotes, not byte counts;
 *     plain string replace is safe for JSON as long as we don't cross field boundaries — which
 *     URLs never do in practice)
 *   - Plain strings (standard str_replace)
 *
 * The public `apply()` method accepts a single string value (e.g. a DB column value) and
 * returns a Result with the replaced value and count.
 */
final class Replacer
{
    private string $search;
    private string $replace;

    public function __construct(string $search, string $replace)
    {
        if ($search === '') {
            throw new InvalidArgumentException('Search string cannot be empty.');
        }
        $this->search = $search;
        $this->replace = $replace;
    }

    public function apply(string $value): Result
    {
        if ($value === '') {
            return new Result('', 0);
        }

        // Fast path: if the search string is not present anywhere (including JSON-escaped form), skip everything.
        $jsonEscapedSearch = str_replace('/', '\/', $this->search);
        if (strpos($value, $this->search) === false && strpos($value, $jsonEscapedSearch) === false) {
            return new Result($value, 0);
        }

        if (SerializedDetector::isSerialized($value)) {
            return $this->applyToSerialized($value);
        }

        // Detect JSON: decode → walk → re-encode. This correctly handles JSON-escaped slashes.
        if ($this->looksLikeJson($value)) {
            $jsonResult = $this->applyToJson($value);
            if ($jsonResult !== null) {
                return $jsonResult;
            }
        }

        // Plain replacement for everything else (HTML, plain text, etc.).
        return $this->applyPlain($value);
    }

    private function applyPlain(string $value): Result
    {
        $count = 0;
        $replaced = str_replace($this->search, $this->replace, $value, $count);

        // Also replace the JSON-escaped form (e.g. http:\/\/old.com → https:\/\/new.com).
        $jsonEscapedSearch = str_replace('/', '\/', $this->search);
        if ($jsonEscapedSearch !== $this->search && strpos($replaced, $jsonEscapedSearch) !== false) {
            $jsonEscapedReplace = str_replace('/', '\/', $this->replace);
            $extraCount = 0;
            $replaced = str_replace($jsonEscapedSearch, $jsonEscapedReplace, $replaced, $extraCount);
            $count += $extraCount;
        }

        return new Result($replaced, $count);
    }

    private function looksLikeJson(string $value): bool
    {
        $first = $value[0] ?? '';
        return $first === '{' || $first === '[';
    }

    private function applyToJson(string $value): ?Result
    {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || $decoded === null) {
            return null;
        }

        $counter = 0;
        $transform = function (string $leaf) use (&$counter): string {
            $count = 0;
            $new = str_replace($this->search, $this->replace, $leaf, $count);
            $counter += $count;
            return $new;
        };

        $walked = SerializedWalker::walk($decoded, $transform);
        $reencoded = json_encode($walked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($reencoded === false) {
            return null;
        }

        return new Result($reencoded, $counter);
    }

    private function applyToSerialized(string $value): Result
    {
        $previousLevel = error_reporting(0);
        try {
            $decoded = @unserialize($value, ['allowed_classes' => true]);
        } finally {
            error_reporting($previousLevel);
        }

        // If unserialize happened to return false for a valid b:0; payload, we handled that
        // in SerializedDetector. Any other false here means something went wrong after detection;
        // fall back to plain replacement to avoid blocking migration.
        if ($decoded === false && $value !== 'b:0;') {
            return $this->applyPlain($value);
        }

        $counter = 0;
        $transform = function (string $leaf) use (&$counter): string {
            $count = 0;
            $new = str_replace($this->search, $this->replace, $leaf, $count);
            $counter += $count;
            return $new;
        };

        $walked = SerializedWalker::walk($decoded, $transform);

        // Re-serialize with corrected byte-length prefixes baked in by PHP's serialize().
        $reserialized = serialize($walked);

        return new Result($reserialized, $counter);
    }
}
