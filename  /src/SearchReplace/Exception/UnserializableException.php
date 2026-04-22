<?php
declare(strict_types=1);

namespace WpMigrateSafe\SearchReplace\Exception;

use RuntimeException;

/**
 * Raised when a string was detected as serialized but parsing failed.
 *
 * This usually indicates data corruption or a malformed serialized payload
 * (e.g. a value that matches `s:N:"..."` syntax but whose byte length doesn't match).
 */
class UnserializableException extends RuntimeException
{
}
