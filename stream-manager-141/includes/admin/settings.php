<?php
if (!defined('ABSPATH')) exit;

add_action('admin_init', function(){
    register_setting('sm_settings_group','sm_cf_account_id');
    register_setting('sm_settings_group','sm_cf_api_token');
    register_setting('sm_settings_group','sm_cf_webhook_secret');
    register_setting('sm_settings_group','sm_cf_customer_subdomain');
    register_setting('sm_settings_group','sm_cf_auto_delete');
    register_setting('sm_settings_group','sm_cf_delete_delay_min');
    register_setting('sm_settings_group','sm_cf_bypass_secret');

    register_setting('sm_settings_group','sm_bunny_base_url');
    register_setting('sm_settings_group','sm_bunny_library_id');
    register_setting('sm_settings_group','sm_bunny_api_key');
    register_setting('sm_settings_group','sm_player_type');

    add_settings_section('sm_cf', __('Cloudflare Stream','stream-manager'), function(){
        echo '<p>Cloudflare Stream credentials. Setup Mode bypasses signature verification during webhook testing (disable later).</p>';
    }, 'sm_settings');
    sm_add_field('sm_cf_account_id','Account ID','sm_text_cb','sm_cf','');
    sm_add_field('sm_cf_api_token','API Token (Bearer)','sm_text_cb','sm_cf','');
    sm_add_field('sm_cf_webhook_secret','Webhook Secret','sm_text_cb','sm_cf','');
    sm_add_field('sm_cf_customer_subdomain','Customer Subdomain (e.g. customer-xxxx)','sm_text_cb','sm_cf','');
    sm_add_field('sm_cf_auto_delete','Auto-Delete CF video post-transfer','sm_checkbox_cb','sm_cf','');
    sm_add_field('sm_cf_delete_delay_min','CF Delete Delay (minutes)','sm_text_cb','sm_cf','60');
    sm_add_field('sm_cf_bypass_secret','Bypass Webhook Secret (Setup Mode)','sm_checkbox_cb','sm_cf','');

    add_settings_section('sm_bunny', __('Bunny Stream','stream-manager'), function(){ echo '<p>Bunny Stream Library AccessKey and Library ID.</p>'; }, 'sm_settings');
    sm_add_field('sm_bunny_base_url','Base URL','sm_text_cb','sm_bunny','https://video.bunnycdn.com');
    sm_add_field('sm_bunny_library_id','Library ID','sm_text_cb','sm_bunny','');
    sm_add_field('sm_bunny_api_key','API Key (AccessKey)','sm_text_cb','sm_bunny','');

    add_settings_section('sm_player', __('Player','stream-manager'), function(){}, 'sm_settings');
    sm_add_field('sm_player_type','VOD Player Type','sm_player_cb','sm_player','');
});

function sm_add_field($opt,$label,$cb,$section,$placeholder){
    add_settings_field($opt, $label, $cb, 'sm_settings', $section, array('option'=>$opt,'placeholder'=>$placeholder));
}
function sm_text_cb($args){
    $opt=$args['option']; $val=esc_attr(get_option($opt,'')); $ph=esc_attr(isset($args['placeholder'])?$args['placeholder']:'');
    printf('<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />', esc_attr($opt), $val, $ph);
}
function sm_checkbox_cb($args){
    $opt=$args['option']; $val=get_option($opt,false)?'checked':'';
    printf('<label><input type="checkbox" name="%s" value="1" %s/> Enable</label>', esc_attr($opt), $val);
}
function sm_player_cb($args){
    $opt=$args['option']; $val=get_option($opt,'iframe');
    echo '<select name="'.esc_attr($opt).'">';
    echo '<option value="iframe" '.selected($val,'iframe',false).'>Bunny Iframe</option>';
    echo '<option value="hls" '.selected($val,'hls',false).'>HLS &lt;video&gt;</option>';
    echo '</select>';
}

add_action('admin_notices', function(){
    if (get_option('sm_cf_bypass_secret')) {
        echo '<div class="notice notice-warning"><p>⚠️ Setup Mode is active — webhook signature validation is disabled. Disable after testing.</p></div>';
    }
});

function sm_render_settings_page(){
    sm_require_cap();
    echo '<div class="wrap"><h1>Stream Manager Settings</h1><form method="post" action="options.php">';
    settings_fields('sm_settings_group'); do_settings_sections('sm_settings'); submit_button();
    echo '</form></div>';
}
