<?php
/**
 * Plugin Name: Stream Manager
 * Description: Cloudflare Live → Bunny Stream VOD with a Universal Smart Player (auto-switch). Create live inputs, upload VOD, transfer via webhook, copy/preview universal embed.
 * Version: 1.5.0
 * Author: Streaming Plugin Project
 * Text Domain: stream-manager
 */
if (!defined('ABSPATH')) exit;

define('SM_VERSION','1.5.0');
define('SM_PLUGIN_FILE', __FILE__);
define('SM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SM_LOG_TABLE','stream_transfer_logs');
define('SM_REGISTRY_TABLE','stream_live_inputs');
define('SM_SYNC_LOG_TABLE','stream_sync_log');
define('SM_NOTIFICATIONS_TABLE','stream_notifications');

require_once SM_PLUGIN_DIR.'includes/helpers/misc.php';
require_once SM_PLUGIN_DIR.'includes/admin/settings.php';
require_once SM_PLUGIN_DIR.'includes/cpt/register.php';
require_once SM_PLUGIN_DIR.'includes/helpers/cloudflare.php';
require_once SM_PLUGIN_DIR.'includes/helpers/bunny.php';
require_once SM_PLUGIN_DIR.'includes/db/registry.php';
require_once SM_PLUGIN_DIR.'includes/helpers/notifications.php';
require_once SM_PLUGIN_DIR.'includes/rest/webhook.php';
require_once SM_PLUGIN_DIR.'includes/ajax/actions.php';
require_once SM_PLUGIN_DIR.'includes/cron/handlers.php';

register_activation_hook(__FILE__, function(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    // Transfer Logs Table
    $table = $wpdb->prefix . SM_LOG_TABLE;
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        post_id BIGINT UNSIGNED NULL,
        title VARCHAR(255) NULL,
        cf_uid VARCHAR(64) NULL,
        status VARCHAR(32) NULL,
        message TEXT NULL,
        cf_iframe TEXT NULL,
        bunny_iframe TEXT NULL,
        vod_url TEXT NULL,
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY cf_uid (cf_uid)
    ) {$charset};";
    dbDelta($sql);

    $cols = $wpdb->get_col("DESC {$table}", 0);
    $alter = array();
    if (!in_array('cf_iframe',$cols))    $alter[] = "ADD COLUMN cf_iframe TEXT NULL AFTER message";
    if (!in_array('bunny_iframe',$cols)) $alter[] = "ADD COLUMN bunny_iframe TEXT NULL AFTER cf_iframe";
    if (!in_array('vod_url',$cols))      $alter[] = "ADD COLUMN vod_url TEXT NULL AFTER bunny_iframe";
    if (!empty($alter)) $wpdb->query("ALTER TABLE {$table} ".implode(', ',$alter));

    // Stream Keys Registry Table
    $registry_table = $wpdb->prefix . SM_REGISTRY_TABLE;
    $sql = "CREATE TABLE IF NOT EXISTS {$registry_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        live_input_uid VARCHAR(255) NOT NULL,
        stream_key VARCHAR(255) NOT NULL,
        post_id BIGINT UNSIGNED NULL,
        default_subject VARCHAR(255) NULL,
        default_category VARCHAR(255) NULL,
        default_year VARCHAR(255) NULL,
        default_batch VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        recording_count INT DEFAULT 0,
        total_duration INT DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY live_input_uid (live_input_uid),
        KEY name (name),
        KEY post_id (post_id)
    ) {$charset};";
    dbDelta($sql);

    // Add post_id column if it doesn't exist (for existing installations)
    $registry_cols = $wpdb->get_col("DESC {$registry_table}", 0);
    if (!in_array('post_id', $registry_cols)) {
        $wpdb->query("ALTER TABLE {$registry_table} ADD COLUMN post_id BIGINT UNSIGNED NULL AFTER stream_key, ADD KEY post_id (post_id)");
    }

    // Sync Log Table
    $sync_log_table = $wpdb->prefix . SM_SYNC_LOG_TABLE;
    $sql = "CREATE TABLE IF NOT EXISTS {$sync_log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_type VARCHAR(20) NOT NULL,
        sync_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recordings_found INT DEFAULT 0,
        recordings_imported INT DEFAULT 0,
        status VARCHAR(20) NOT NULL,
        message TEXT NULL,
        PRIMARY KEY (id),
        KEY sync_time (sync_time),
        KEY sync_type (sync_type)
    ) {$charset};";
    dbDelta($sql);

    // Notifications Table
    $notifications_table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;
    $sql = "CREATE TABLE IF NOT EXISTS {$notifications_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        type VARCHAR(20) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NULL,
        post_id BIGINT UNSIGNED NULL,
        cf_uid VARCHAR(64) NULL,
        is_read TINYINT DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY type (type),
        KEY is_read (is_read),
        KEY created_at (created_at)
    ) {$charset};";
    dbDelta($sql);

    // Add indexes for performance on postmeta
    $postmeta_table = $wpdb->prefix . 'postmeta';
    $indexes = $wpdb->get_results("SHOW INDEX FROM {$postmeta_table} WHERE Key_name='idx_sm_video_uid'");
    if (empty($indexes)) {
        $wpdb->query("ALTER TABLE {$postmeta_table} ADD INDEX idx_sm_video_uid (meta_key, meta_value(50))");
    }

    add_option('sm_cf_delete_delay_min', 60);
    add_option('sm_player_type', 'iframe');
    add_option('sm_cf_bypass_secret', 1);
    add_option('sm_sync_enabled', 0);
    add_option('sm_sync_frequency', 'hourly');
    add_option('sm_sync_email_notify', 0);
    add_option('sm_sync_email_address', get_option('admin_email'));
});

register_deactivation_hook(__FILE__, function(){
    foreach (array('sm_transfer_retry_event','sm_cf_delete_event','sm_sync_cron_event') as $h){
        wp_clear_scheduled_hook($h);
    }
});

add_action('admin_menu', function(){
    // Get unread notification count for badge
    global $wpdb;
    $notifications_table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;
    $unread_count = $wpdb->get_var("SELECT COUNT(*) FROM {$notifications_table} WHERE is_read = 0");
    $badge = $unread_count > 0 ? ' <span class="update-plugins count-' . $unread_count . '"><span class="update-count">' . number_format_i18n($unread_count) . '</span></span>' : '';

    add_menu_page(__('Stream Manager','stream-manager'), __('Stream Manager','stream-manager') . $badge, 'manage_options', 'sm_dashboard', 'sm_admin_dashboard_page', 'dashicons-video-alt3', 58);
    add_submenu_page('sm_dashboard', __('All Streams','stream-manager'), __('All Streams','stream-manager'), 'manage_options', 'sm_dashboard', 'sm_admin_dashboard_page');
    add_submenu_page('sm_dashboard', __('Manage Stream Keys','stream-manager'), __('Manage Stream Keys','stream-manager'), 'manage_options', 'sm_registry', 'sm_admin_registry_page');
    add_submenu_page('sm_dashboard', __('Notifications','stream-manager'), __('Notifications','stream-manager') . $badge, 'manage_options', 'sm_notifications', 'sm_admin_notifications_page');
    add_submenu_page('sm_dashboard', __('Sync Recordings','stream-manager'), __('Sync Recordings','stream-manager'), 'manage_options', 'sm_sync', 'sm_admin_sync_page');
    add_submenu_page('sm_dashboard', __('Add Live Video','stream-manager'), __('Add Live Video','stream-manager'), 'manage_options', 'sm_add_live', 'sm_admin_add_live_page');
    add_submenu_page('sm_dashboard', __('Add Recorded Video','stream-manager'), __('Add Recorded Video','stream-manager'), 'manage_options', 'sm_add_recorded', 'sm_admin_add_recorded_page');
    add_submenu_page('sm_dashboard', __('Transfer Logs','stream-manager'), __('Transfer Logs','stream-manager'), 'manage_options', 'sm_logs', 'sm_admin_logs_page');
    add_submenu_page('sm_dashboard', __('Test Delete','stream-manager'), __('Test Delete','stream-manager'), 'manage_options', 'sm_test_delete', 'sm_admin_test_delete_page');
    add_submenu_page('sm_dashboard', __('Settings','stream-manager'), __('Settings','stream-manager'), 'manage_options', 'sm_settings', 'sm_admin_settings_page');
});

function sm_admin_enqueue($hook){
    if (strpos($hook,'sm_') !== false) {
        if (function_exists('wp_enqueue_media')) wp_enqueue_media();
        wp_enqueue_style('sm-admin', SM_PLUGIN_URL.'assets/admin.css', array(), SM_VERSION);
        wp_enqueue_script('sm-admin', SM_PLUGIN_URL.'assets/admin.js', array('jquery'), SM_VERSION, true);
        wp_localize_script('sm-admin','SM_AJAX', array(
            'ajaxurl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('sm_ajax_nonce'),
            'siteurl'=>site_url()
        ));
    }
}
add_action('admin_enqueue_scripts','sm_admin_enqueue');

add_action('template_redirect', function(){
    if (!isset($_GET['stream_embed'])) return;
    $slug = isset($_GET['slug']) ? sanitize_title($_GET['slug']) : '';
    $post = get_page_by_path($slug, OBJECT, 'stream_class');
    if (!$post) { status_header(404); echo '<!doctype html><meta charset="utf-8"><title>Not Found</title><p>Stream not found.</p>'; exit; }
    $api_url = rest_url('stream/v1/item?slug='.$slug);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1" /><title>Universal Player</title>';
    echo '<style>html,body{margin:0;background:#000} .wrap{position:relative;padding-top:56.25%;} .wrap>iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:0}</style>';
    echo '<div id="app"></div><script>const API='.wp_json_encode($api_url).';</script>';
    echo <<<HTML
<script>
(async function(){
  const app=document.getElementById('app');
  try{
    const r=await fetch(API,{cache:'no-store'});
    const j=await r.json();
    if(j && j.urls){
      var src='';
      if(j.status==='live' && j.urls.cfOfficialIframe){
        src=j.urls.cfOfficialIframe;
      }
      else if(j.status==='vod' && j.urls.bunnyOfficialIframe){
        src=j.urls.bunnyOfficialIframe;
      }
      else if(j.urls.cfOfficialIframe){
        src=j.urls.cfOfficialIframe;
      }
      if(src){
        app.innerHTML = '<div class="wrap"><iframe src="'+src+'" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe></div>';
        return;
      }
    }
    app.innerHTML = '<div style="color:#fff;padding:24px;font:16px system-ui">Processing… Please refresh.</div>';
  }catch(e){
    console.error('Video load error:', e);
    app.innerHTML='<div style="color:#fff;padding:24px;font:16px system-ui">Error loading video.</div>';
  }
})();
</script>
HTML;
    exit;
});

function sm_admin_dashboard_page(){ echo sm_view('admin/dashboard'); }
function sm_admin_registry_page(){ echo sm_view('admin/registry'); }
function sm_admin_notifications_page(){ echo sm_view('admin/notifications'); }
function sm_admin_sync_page(){ echo sm_view('admin/sync'); }
function sm_admin_add_live_page(){ echo sm_view('admin/add-live'); }
function sm_admin_add_recorded_page(){ echo sm_view('admin/add-recorded'); }
function sm_admin_logs_page(){ echo sm_view('admin/logs'); }
function sm_admin_test_delete_page(){ echo sm_view('admin/test-delete'); }
function sm_admin_settings_page(){ sm_render_settings_page(); }

// Cron schedule management
function sm_update_sync_schedule() {
    // Clear existing schedule
    $timestamp = wp_next_scheduled('sm_sync_cron_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'sm_sync_cron_event');
    }

    // Check if enabled
    $enabled = get_option('sm_sync_enabled', 0);

    if (!$enabled) {
        return; // Don't schedule if disabled
    }

    // Get frequency
    $frequency = get_option('sm_sync_frequency', 'hourly');

    // Schedule event
    if (!wp_next_scheduled('sm_sync_cron_event')) {
        wp_schedule_event(time(), $frequency, 'sm_sync_cron_event');
    }
}

// Add custom cron schedules
add_filter('cron_schedules', function($schedules) {
    if (!isset($schedules['hourly'])) {
        $schedules['hourly'] = array(
            'interval' => 3600,
            'display' => __('Once Hourly', 'stream-manager')
        );
    }

    $schedules['6hours'] = array(
        'interval' => 21600,
        'display' => __('Every 6 Hours', 'stream-manager')
    );

    $schedules['12hours'] = array(
        'interval' => 43200,
        'display' => __('Every 12 Hours', 'stream-manager')
    );

    return $schedules;
});

// Initialize cron on plugin load
add_action('init', 'sm_update_sync_schedule');
