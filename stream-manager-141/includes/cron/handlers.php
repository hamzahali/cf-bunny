<?php
if (!defined('ABSPATH')) exit;

add_action('sm_transfer_retry_event', function($post_id, $cf_uid, $attempt){
    sm_start_transfer_to_bunny($post_id, $cf_uid, $attempt);
}, 10, 3);

add_action('sm_cf_delete_event', function($cf_uid){
    $acc = get_option('sm_cf_account_id','');
    $tok = get_option('sm_cf_api_token','');
    $ok  = sm_cf_delete_video($acc, $tok, $cf_uid);
    sm_log($ok?'INFO':'ERROR', 0, $ok ? "CF deleted {$cf_uid}" : "CF delete failed {$cf_uid}", $cf_uid);
}, 10, 1);

function sm_schedule_transfer_retry($post_id, $cf_uid, $attempt){
    $delays = array(2,5,10);
    if ($attempt >= count($delays)) { sm_log('ERROR',$post_id,'Transfer failed after retries',$cf_uid); return; }
    $when = time() + ($delays[$attempt] * 60);
    wp_schedule_single_event($when, 'sm_transfer_retry_event', array($post_id, $cf_uid, $attempt+1));
    sm_log('INFO',$post_id,"Retry #{$attempt} scheduled in {$delays[$attempt]} min",$cf_uid);
}

function sm_start_transfer_to_bunny($post_id, $cf_uid, $attempt){
    $acc = get_option('sm_cf_account_id','');
    $tok = get_option('sm_cf_api_token','');
    $lib = get_option('sm_bunny_library_id','');
    $key = get_option('sm_bunny_api_key','');

    $mp4 = sm_cf_enable_and_wait_mp4($acc, $tok, $cf_uid, 300);
    if (is_wp_error($mp4)) { sm_log('ERROR',$post_id,'MP4 not ready: '.$mp4->get_error_message(),$cf_uid); sm_schedule_transfer_retry($post_id,$cf_uid,$attempt); return; }

    $title = get_the_title($post_id);
    $guid = sm_bunny_create_video($lib, $key, $title);
    if (is_wp_error($guid)) { sm_log('ERROR',$post_id,'Bunny create failed: '.$guid->get_error_message(),$cf_uid); sm_schedule_transfer_retry($post_id,$cf_uid,$attempt); return; }

    $fetch = wp_remote_post(sm_bunny_base()."/library/{$lib}/videos/{$guid}/fetch", array(
        'headers' => array('AccessKey'=>$key,'Content-Type'=>'application/json'),
        'body'    => wp_json_encode(array('url'=>$mp4)),
        'timeout' => 60
    ));
    if (is_wp_error($fetch) || wp_remote_retrieve_response_code($fetch) >= 300) {
        sm_log('ERROR',$post_id,'Bunny fetch failed',$cf_uid); sm_schedule_transfer_retry($post_id,$cf_uid,$attempt); return;
    }

    list($iframe,$hls) = sm_bunny_player_urls_for_guid($lib, $guid);
    update_post_meta($post_id, '_sm_bunny_guid', $guid);
    update_post_meta($post_id, '_sm_bunny_iframe', $iframe);
    update_post_meta($post_id, '_sm_bunny_hls', $hls);
    update_post_meta($post_id, '_sm_status', 'vod');

    $sub = trim(get_option('sm_cf_customer_subdomain',''));
    $cf_iframe = $sub ? ('https://'.$sub.'.cloudflarestream.com/'.$cf_uid.'/iframe') : '';
    sm_log('INFO',$post_id,'Bunny fetch accepted (post switched to VOD)',$cf_uid,$iframe,$cf_iframe,$iframe);

    if (get_option('sm_cf_auto_delete', false)) {
        $delay_min = absint(get_option('sm_cf_delete_delay_min', 60)); if (!$delay_min) $delay_min = 60;
        wp_schedule_single_event(time() + $delay_min * 60, 'sm_cf_delete_event', array($cf_uid));
        sm_log('INFO',$post_id,"Scheduled CF delete in {$delay_min} min",$cf_uid);
    }
}
