<?php
declare(strict_types=1);

namespace WpMigrateSafe\Errors;

use WP_Error;

/**
 * Build a WP_Error with a consistent JSON shape from an ErrorCatalog code.
 */
final class ErrorResponse
{
    /**
     * @param array<string, mixed> $context
     */
    public static function fromCode(string $code, int $httpStatus, array $context = [], ?string $overrideMessage = null): WP_Error
    {
        $meta = ErrorCatalog::lookup($code) ?? [
            'category' => 'environment',
            'message' => 'Unknown error.',
            'hint' => '',
            'recoverable' => false,
            'doc_slug' => '',
        ];
        return new WP_Error(
            'wpms_' . strtolower($code),
            $overrideMessage ?? $meta['message'],
            [
                'status' => $httpStatus,
                'code' => $code,
                'category' => $meta['category'],
                'hint' => $meta['hint'],
                'recoverable' => $meta['recoverable'],
                'doc_slug' => $meta['doc_slug'],
                'context' => $context,
            ]
        );
    }
}
