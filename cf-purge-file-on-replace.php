<?php
/**
 * Plugin Name: Cloudflare Purge File on Replace
 * Description: Purges the exact file URL from Cloudflare when a file attachment is replaced/updated (useful for "Replace Media" workflows).
 * Version: 1.0.0
 * Author: BlueFractals
 * License: GPL2+
 */

if (!defined('ABSPATH')) { exit; }

class CF_Purge_File_On_Replace {
    const OPTION_KEY = 'cf_purge_file_settings';
    const EMAIL_THROTTLE_TRANSIENT = 'cf_purge_file_last_email_ts';

    private static array $purged_this_request = [];

    public static function init(): void {
        // Hooks to catch media replacement flows
        add_action('updated_post_meta', [__CLASS__, 'on_updated_post_meta'], 10, 4);
        add_filter('wp_update_attachment_metadata', [__CLASS__, 'on_attachment_metadata_updated'], 10, 2);

        // Admin settings
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_filter(
            'plugin_action_links_' . plugin_basename(__FILE__),
            [__CLASS__, 'add_settings_link']
        );

        add_action('admin_post_cf_manual_purge', [__CLASS__, 'handle_manual_purge']);
    }

    public static function get_settings(): array {
        $defaults = [
            'zone_id' => '',
            'api_token' => '',
            'notify_email' => get_option('admin_email'),
            'enable_success_email' => 1,
            'enable_failure_email' => 1,
            'email_throttle_minutes' => 15,
            'delete_settings_on_uninstall' => 0,
        ];
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) $saved = [];
        return array_merge($defaults, $saved);
    }

    public static function on_updated_post_meta($meta_id, $post_id, $meta_key, $meta_value): void {
        if ($meta_key !== '_wp_attached_file') return;
        if (get_post_type($post_id) !== 'attachment') return;
  
        $mime = get_post_mime_type($post_id);
        if (!$mime) return;

        $url = wp_get_attachment_url($post_id);
        if (!$url) return;

        self::purge_cloudflare_urls([$url], $post_id, 'updated_post_meta:_wp_attached_file');
    }

    public static function on_attachment_metadata_updated($data, $post_id) {
        $mime = get_post_mime_type($post_id);
        if (!$mime) return $data;

        $url = wp_get_attachment_url($post_id);
        if (!$url) return $data;

        self::purge_cloudflare_urls([$url], $post_id, 'wp_update_attachment_metadata');
        return $data;
    }

    private static function purge_cloudflare_urls(array $urls, int $attachment_id, string $trigger): void {
        $key = $attachment_id . '|' . implode('|', $urls);
        if (isset(self::$purged_this_request[$key])) return;
        self::$purged_this_request[$key] = true;

        $urls = array_values(array_unique(array_filter($urls)));
        if (empty($urls)) return;

        $settings = self::get_settings();
        $zone_id  = trim((string) $settings['zone_id']);
        $token    = trim((string) $settings['api_token']);

        if ($zone_id === '' || $token === '') {
            self::notify_admin(
                self::format_message($attachment_id, $trigger, $urls, 'Missing Cloudflare Zone ID or API Token in plugin settings.')
            );
            return;
        }

        $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($zone_id) . '/purge_cache';

        $resp = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode(['files' => $urls]),
        ]);

        if (is_wp_error($resp)) {
            self::notify_admin(
                self::format_message($attachment_id, $trigger, $urls, 'WP_Error: ' . $resp->get_error_message())
            );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);

        if ($code < 200 || $code >= 300 || empty($json['success'])) {
            $detail = "HTTP Status: {$code}\nResponse Body:\n{$body}";
            self::notify_admin(self::format_message($attachment_id, $trigger, $urls, $detail));
        } else {
            self::notify_success($attachment_id, $trigger, $urls);
        }
    }

    private static function format_message(int $attachment_id, string $trigger, array $urls, string $error_detail): string {
        $site = get_bloginfo('name');
        $attachment_edit = admin_url('post.php?post=' . $attachment_id . '&action=edit');
        $attachment_url = wp_get_attachment_url($attachment_id);

        return
            "Site: {$site}\n" .
            "Trigger: {$trigger}\n" .
            "Attachment ID: {$attachment_id}\n" .
            "Attachment edit link: {$attachment_edit}\n" .
            "Attachment URL: " . ($attachment_url ?: '(unknown)') . "\n" .
            "Purged URLs:\n- " . implode("\n- ", $urls) . "\n\n" .
            "Error/Detail:\n{$error_detail}\n";
    }

    private static function notify_admin(string $message): void {
        $settings = self::get_settings();
        if (empty($settings['enable_failure_email'])) return;

        // Throttle emails to avoid spam if something loops
        $minutes = max(1, (int) $settings['email_throttle_minutes']);
        $last_ts = (int) get_transient(self::EMAIL_THROTTLE_TRANSIENT);
        $now = time();

        if ($last_ts > 0 && ($now - $last_ts) < ($minutes * 60)) {
            return;
        }

        set_transient(self::EMAIL_THROTTLE_TRANSIENT, $now, $minutes * 60);

        $to = sanitize_email((string) $settings['notify_email']);
        if (!$to) $to = get_option('admin_email');

        $subject = '[' . get_bloginfo('name') . '] Cloudflare file purge failed';

        // wp_mail returns bool, but we won't error-loop on failures
        wp_mail($to, $subject, $message);
    }

    private static function notify_success(int $attachment_id, string $trigger, array $urls): void {
        $settings = self::get_settings();
        if (empty($settings['enable_success_email'])) return;
    
        $to = sanitize_email((string) $settings['notify_email']);
        if (!$to) $to = get_option('admin_email');
    
        $site_name = get_bloginfo('name');
        $timestamp = wp_date('Y-m-d H:i:s'); // site timezone
    
        $attachment_edit = admin_url('post.php?post=' . $attachment_id . '&action=edit');
    
        $subject = '[' . $site_name . '] Cloudflare purge completed';
    
        $message =
            "Site: {$site_name}\n" .
            "Time: {$timestamp}\n" .
            "Trigger: {$trigger}\n" .
            "Attachment ID: {$attachment_id}\n" .
            "Attachment edit link: {$attachment_edit}\n" .
            "Purged URLs:\n- " . implode("\n- ", $urls) . "\n";
    
        wp_mail($to, $subject, $message);
    }

    public static function handle_manual_purge(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    
        if (
            empty($_POST['cf_manual_purge_nonce']) ||
            !wp_verify_nonce($_POST['cf_manual_purge_nonce'], 'cf_manual_purge_nonce')
        ) {
            wp_die('Invalid nonce');
        }
    
        $url = isset($_POST['cf_manual_purge_url'])
            ? esc_url_raw(trim($_POST['cf_manual_purge_url']))
            : '';
    
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            wp_redirect(add_query_arg('cf_manual_purge', 'invalid', wp_get_referer()));
            exit;
        }
    
        // Reuse existing purge logic
        self::purge_cloudflare_urls([$url], 0, 'manual_purge');
    
        wp_redirect(add_query_arg('cf_manual_purge', 'done', wp_get_referer()));
        exit;
    }

    // --------------------------------------------------------------------
    // "Settings" link directly under the plugin name on the Plugins page
    // --------------------------------------------------------------------
    
    public static function add_settings_link(array $links): array {
        $url = admin_url('options-general.php?page=cf-purge-file-on-replace');
        array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
        return $links;
    }

    // --------------------------
    // Admin settings page
    // --------------------------

    public static function add_settings_page(): void {
        add_options_page(
            'Cloudflare File Purge',
            'Cloudflare File Purge',
            'manage_options',
            'cf-purge-file-on-replace',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [__CLASS__, 'sanitize_settings']);

        add_settings_section(
            'cf_purge_file_main',
            'Cloudflare settings',
            function () {
                echo '<p>Set your Cloudflare Zone ID + API Token (Zone → Cache Purge → Purge, Zone → Read).</p>';
            },
            'cf-purge-file-on-replace'
        );

        add_settings_field('zone_id', 'Zone ID', [__CLASS__, 'field_zone_id'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
        add_settings_field('api_token', 'API Token', [__CLASS__, 'field_api_token'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
        add_settings_field('notify_email', 'Notification email', [__CLASS__, 'field_notify_email'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
        add_settings_field('enable_success_email', 'Email on successful purge', [__CLASS__, 'field_enable_success_email'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
        add_settings_field('enable_failure_email', 'Enable failure emails', [__CLASS__, 'field_enable_failure_email'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
        add_settings_field('email_throttle_minutes', 'Email throttle (minutes)', [__CLASS__, 'field_throttle'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
        add_settings_field('delete_settings_on_uninstall', 'Delete settings on uninstall', [__CLASS__, 'field_delete_settings_on_uninstall'], 'cf-purge-file-on-replace', 'cf_purge_file_main');
    }

    public static function sanitize_settings($input): array {
        $out = self::get_settings();

        if (is_array($input)) {
            $out['zone_id'] = sanitize_text_field($input['zone_id'] ?? '');
            $out['api_token'] = sanitize_text_field($input['api_token'] ?? '');
            $out['notify_email'] = sanitize_email($input['notify_email'] ?? get_option('admin_email'));
            $out['enable_success_email'] = !empty($input['enable_success_email']) ? 1 : 0;
            $out['enable_failure_email'] = !empty($input['enable_failure_email']) ? 1 : 0;
            $out['email_throttle_minutes'] = max(1, (int) ($input['email_throttle_minutes'] ?? 15));
            $out['delete_settings_on_uninstall'] = !empty($input['delete_settings_on_uninstall']) ? 1 : 0;
        }

        return $out;
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;

        echo '<div class="wrap">';
        echo '<h1>Cloudflare File Purge</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        do_settings_sections('cf-purge-file-on-replace');
        submit_button('Save Settings');
        echo '</form>';

        echo '<hr />';
        echo '<h2>Manual Purge</h2>';
        echo '<p>Manually purge a specific file URL from Cloudflare cache.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="cf_manual_purge" />';
        wp_nonce_field('cf_manual_purge_nonce', 'cf_manual_purge_nonce');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="cf_manual_purge_url">File URL</label></th>';
        echo '<td><input type="url" id="cf_manual_purge_url" name="cf_manual_purge_url" class="regular-text" placeholder="https://example.com/wp-content/uploads/file.pdf" required /></td>';
        echo '</tr>';
        echo '</table>';
        submit_button('Manual Purge');
        echo '</form>';

        if (isset($_GET['cf_manual_purge'])) {
            if ($_GET['cf_manual_purge'] === 'done') {
                echo '<div class="notice notice-success"><p>Cloudflare purge request sent successfully.</p></div>';
            } elseif ($_GET['cf_manual_purge'] === 'invalid') {
                echo '<div class="notice notice-error"><p>Invalid URL supplied for manual purge.</p></div>';
            }
        }

        echo '<hr />';
        echo '<p><strong>Notes:</strong></p>';
        echo '<ul style="list-style: disc; padding-left: 20px;">';
        echo '<li>This plugin purges the specific file URL from Cloudflare when a file attachment is replaced/updated.</li>';
        echo '<li>You can deactivate it anytime from <em>Plugins</em>.</li>';
        echo '</ul>';

        echo '</div>';
    }

    public static function field_zone_id(): void {
        $s = self::get_settings();
        printf(
            '<input type="text" name="%s[zone_id]" value="%s" class="regular-text" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($s['zone_id'])
        );
    }

    public static function field_api_token(): void {
        $s = self::get_settings();
        printf(
            '<input type="password" name="%s[api_token]" value="%s" class="regular-text" autocomplete="new-password" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($s['api_token'])
        );
    }

    public static function field_notify_email(): void {
        $s = self::get_settings();
        printf(
            '<input type="email" name="%s[notify_email]" value="%s" class="regular-text" />',
            esc_attr(self::OPTION_KEY),
            esc_attr($s['notify_email'])
        );
    }

    public static function field_enable_success_email(): void {
        $s = self::get_settings();
        printf(
            '<label><input type="checkbox" name="%s[enable_success_email]" value="1" %s /> Send email after each successful purge</label>',
            esc_attr(self::OPTION_KEY),
            checked(1, (int) $s['enable_success_email'], false)
        );
    }

    public static function field_enable_failure_email(): void {
        $s = self::get_settings();
        printf(
            '<label><input type="checkbox" name="%s[enable_failure_email]" value="1" %s /> Send email on purge failure</label>',
            esc_attr(self::OPTION_KEY),
            checked(1, (int) $s['enable_failure_email'], false)
        );
    }

    public static function field_throttle(): void {
        $s = self::get_settings();
        printf(
            '<input type="number" min="1" name="%s[email_throttle_minutes]" value="%d" class="small-text" /> <span class="description">Prevents repeated emails if something loops.</span>',
            esc_attr(self::OPTION_KEY),
            (int) $s['email_throttle_minutes']
        );
    }

    public static function field_delete_settings_on_uninstall(): void {
        $s = self::get_settings();
        printf(
            '<label><input type="checkbox" name="%s[delete_settings_on_uninstall]" value="1" %s /> Delete plugin settings (including API token) when the plugin is deleted</label>
             <p class="description">This runs only when you click <strong>Delete</strong> in the Plugins screen (not when deactivating).</p>',
            esc_attr(self::OPTION_KEY),
            checked(1, (int) $s['delete_settings_on_uninstall'], false)
        );
    }
}

CF_Purge_File_On_Replace::init();
