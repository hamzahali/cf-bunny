<?php
/**
 * Plugin Name: Stream Manager
 * Description: Cloudflare Live → Bunny Stream VOD with a Universal Smart Player (auto-switch). Create live inputs, upload VOD, transfer via webhook, copy/preview universal embed.
 * Version: 1.4.1
 * Author: Streaming Plugin Project
 * Text Domain: stream-manager
 */
if (!defined('ABSPATH')) exit;

define('SM_VERSION','1.4.1');
define('SM_PLUGIN_FILE', __FILE__);
define('SM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SM_LOG_TABLE','stream_transfer_logs');

require_once SM_PLUGIN_DIR.'includes/helpers/misc.php';
require_once SM_PLUGIN_DIR.'includes/admin/settings.php';
require_once SM_PLUGIN_DIR.'includes/cpt/register.php';
require_once SM_PLUGIN_DIR.'includes/helpers/cloudflare.php';
require_once SM_PLUGIN_DIR.'includes/helpers/bunny.php';
require_once SM_PLUGIN_DIR.'includes/rest/webhook.php';
require_once SM_PLUGIN_DIR.'includes/ajax/actions.php';
require_once SM_PLUGIN_DIR.'includes/cron/handlers.php';

register_activation_hook(__FILE__, function(){
    global $wpdb;
    $table = $wpdb->prefix . SM_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();
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
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    $cols = $wpdb->get_col("DESC {$table}", 0);
    $alter = array();
    if (!in_array('cf_iframe',$cols))    $alter[] = "ADD COLUMN cf_iframe TEXT NULL AFTER message";
    if (!in_array('bunny_iframe',$cols)) $alter[] = "ADD COLUMN bunny_iframe TEXT NULL AFTER cf_iframe";
    if (!in_array('vod_url',$cols))      $alter[] = "ADD COLUMN vod_url TEXT NULL AFTER bunny_iframe";
    if (!empty($alter)) $wpdb->query("ALTER TABLE {$table} ".implode(', ',$alter));

    add_option('sm_cf_delete_delay_min', 60);
    add_option('sm_player_type', 'iframe');
    add_option('sm_cf_bypass_secret', 1);
});

register_deactivation_hook(__FILE__, function(){
    foreach (array('sm_transfer_retry_event','sm_cf_delete_event') as $h){
        wp_clear_scheduled_hook($h);
    }
});

add_action('admin_menu', function(){
    add_menu_page(__('Stream Manager','stream-manager'), __('Stream Manager','stream-manager'), 'manage_options', 'sm_dashboard', 'sm_admin_dashboard_page', 'dashicons-video-alt3', 58);
    add_submenu_page('sm_dashboard', __('All Streams','stream-manager'), __('All Streams','stream-manager'), 'manage_options', 'sm_dashboard', 'sm_admin_dashboard_page');
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
      if(j.status==='live' && j.urls.cfOfficialIframe){ src=j.urls.cfOfficialIframe; }
      else if(j.status==='vod' && j.urls.bunnyOfficialIframe){ src=j.urls.bunnyOfficialIframe; }
      if(src){
        app.innerHTML = '<div class="wrap"><iframe src="'+src+'" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe></div>';
        return;
      }
    }
    app.innerHTML = '<div style="color:#fff;padding:24px;font:16px system-ui">Processing… Please refresh.</div>';
  }catch(e){ app.innerHTML='<div style="color:#fff;padding:24px;font:16px system-ui">Error loading video.</div>'; }
})();
</script>
HTML;
    exit;
});

function sm_admin_dashboard_page(){ echo sm_view('admin/dashboard'); }
function sm_admin_add_live_page(){ echo sm_view('admin/add-live'); }
function sm_admin_add_recorded_page(){ echo sm_view('admin/add-recorded'); }
function sm_admin_logs_page(){ echo sm_view('admin/logs'); }
function sm_admin_test_delete_page(){ echo sm_view('admin/test-delete'); }
function sm_admin_settings_page(){ sm_render_settings_page(); }
