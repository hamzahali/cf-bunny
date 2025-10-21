<?php
if (!defined('ABSPATH')) exit;

function sm_log($status, $post_id, $message, $cf_uid = '', $vod_url = '', $cf_iframe = '', $bunny_iframe = ''){
    global $wpdb;
    $table = $wpdb->prefix . SM_LOG_TABLE;
    $wpdb->insert($table, array(
        'post_id' => $post_id ? intval($post_id) : null,
        'title'   => $post_id ? get_the_title($post_id) : null,
        'cf_uid'  => $cf_uid,
        'status'  => $status,
        'message' => $message,
        'cf_iframe' => $cf_iframe,
        'bunny_iframe' => $bunny_iframe,
        'vod_url' => $vod_url
    ));
}

function sm_view($slug, $vars = array()){
    $file = SM_PLUGIN_DIR . "views/{$slug}.php";
    if (!file_exists($file)) return "<div class='wrap'><h1>View missing: {$slug}</h1></div>";
    ob_start(); extract($vars); include $file; return ob_get_clean();
}
function sm_require_cap(){ if (!current_user_can('manage_options')) wp_die(__('You do not have permission.','stream-manager')); }
