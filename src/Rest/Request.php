<?php
declare(strict_types=1);

namespace WpMigrateSafe\Rest;

use WP_REST_Request;

/**
 * Typed accessor wrapper around WP_REST_Request for cleaner controller code.
 */
final class Request
{
    private WP_REST_Request $wp;

    public function __construct(WP_REST_Request $wp)
    {
        $this->wp = $wp;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->wp->get_param($key);
        return is_scalar($value) ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->wp->get_param($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBodyAsString(): string
    {
        return (string) $this->wp->get_body();
    }

    public function headerValue(string $name): string
    {
        $header = $this->wp->get_header($name);
        return is_string($header) ? $header : '';
    }

    public function rawWp(): WP_REST_Request
    {
        return $this->wp;
    }
}
