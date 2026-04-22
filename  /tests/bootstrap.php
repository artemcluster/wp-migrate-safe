<?php
/**
 * PHPUnit bootstrap for the pure-unit test suite (no WordPress).
 *
 * Loads Composer autoload and defines minimal WordPress function/constant stubs
 * so unit-testable classes can run without a real WP installation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress function stubs (global namespace)
// ---------------------------------------------------------------------------

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string
    {
        return rtrim($string, "/\\") . '/';
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0755, true);
    }
}
