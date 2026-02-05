<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$option_key = 'cf_purge_pdf_settings';

$should_delete_for_site = function () use ($option_key): bool {
    $settings = get_option($option_key, []);
    if (!is_array($settings)) return false;
    return !empty($settings['delete_settings_on_uninstall']);
};

if (is_multisite()) {
    $sites = get_sites(['number' => 0]);

    foreach ($sites as $site) {
        switch_to_blog($site->blog_id);

        if ($should_delete_for_site()) {
            delete_option($option_key);
        }

        restore_current_blog();
    }
} else {
    if ($should_delete_for_site()) {
        delete_option($option_key);
    }
}
