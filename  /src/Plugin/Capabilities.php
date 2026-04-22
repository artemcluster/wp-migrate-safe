<?php
declare(strict_types=1);

namespace WpMigrateSafe\Plugin;

final class Capabilities
{
    public static function currentUserCan(): bool
    {
        return function_exists('current_user_can') && current_user_can(WPMS_CAPABILITY);
    }
}
