<?php
// Direct access protection.
if (!defined('ABSPATH')) { exit; }

$tabs = [
    'upload'  => __('Upload', 'wp-migrate-safe'),
    'export'  => __('Export', 'wp-migrate-safe'),
    'backups' => __('Backups', 'wp-migrate-safe'),
];
$active = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'upload';
?>
<div class="wrap wpms-admin">
    <h1><?php esc_html_e('Migrate Safe', 'wp-migrate-safe'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $slug => $label): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $slug)); ?>"
               class="nav-tab <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div class="wpms-tab-content">
        <?php
        $tabFile = WPMS_PATH . 'views/tabs/' . $active . '.php';
        if (file_exists($tabFile)) {
            include $tabFile;
        } else {
            echo '<p>' . esc_html__('Unknown tab.', 'wp-migrate-safe') . '</p>';
        }
        ?>
    </div>
</div>
