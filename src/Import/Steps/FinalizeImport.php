<?php
declare(strict_types=1);

namespace WpMigrateSafe\Import\Steps;

use WpMigrateSafe\Import\ImportContext;
use WpMigrateSafe\Import\ImportStep;
use WpMigrateSafe\Job\StepResult;

/**
 * Last import step: flush caches, re-authenticate the current WP-admin user
 * against the freshly imported users table, regenerate the REST nonce, clean
 * up the extraction tmp dir. Returns a fresh nonce so the JS client can
 * replace its stale one before any further request hits auth.
 */
final class FinalizeImport implements ImportStep
{
    public function name(): string { return 'finalize'; }

    public function run(ImportContext $context, array $cursor, int $maxSeconds): StepResult
    {
        $reauth = $this->reauthenticateCurrentUser();

        if (function_exists('wp_cache_flush')) wp_cache_flush();
        if (function_exists('flush_rewrite_rules')) flush_rewrite_rules(false);

        // Clean up extraction directory.
        $this->rmTree($context->extractDir());

        return StepResult::complete(100, 'Import complete.', $reauth);
    }

    /**
     * After import, the original admin's session tokens were overwritten by
     * DROP+recreate of the users/usermeta tables. Their browser cookies point
     * at a session that no longer exists. We:
     *   1. Find if a user with the same login (or ID=1) exists in the imported DB.
     *   2. Issue a new auth cookie for them (served via HTTP headers on this response).
     *   3. Return a fresh REST nonce so JS can update window.WPMS.nonce.
     *
     * If we can't find a matching user, we return no reauth data — JS will surface
     * a "please log in again" message.
     *
     * @return array<string, mixed> meta to merge into job output
     */
    private function reauthenticateCurrentUser(): array
    {
        if (!function_exists('wp_get_current_user') || !function_exists('wp_set_auth_cookie')) {
            return [];
        }

        $current = wp_get_current_user();
        if (!$current || !$current->exists()) {
            return ['reauth_required' => true];
        }

        $login = (string) $current->user_login;
        $user = $login !== '' ? get_user_by('login', $login) : null;

        if (!$user || !$user->exists()) {
            // Fall back to user ID 1 (typical WP admin).
            $user = get_user_by('id', 1);
        }

        if (!$user || !$user->exists()) {
            return ['reauth_required' => true];
        }

        wp_clear_auth_cookie();
        wp_set_auth_cookie($user->ID, true, is_ssl());
        wp_set_current_user($user->ID);

        return [
            'reauth_user_id' => (int) $user->ID,
            'reauth_user_login' => (string) $user->user_login,
            'new_nonce' => wp_create_nonce('wp_rest'),
        ];
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
