<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_sm_create_live', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : ('Live '.current_time('mysql'));
    $meta = array(
        'category' => isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '',
        'year' => isset($_POST['year']) ? sanitize_text_field($_POST['year']) : '',
        'batch' => isset($_POST['batch']) ? sanitize_text_field($_POST['batch']) : ''
    );
    $cf_acc = get_option('sm_cf_account_id',''); $cf_tok = get_option('sm_cf_api_token','');
    $res = sm_cf_create_live_input($cf_acc, $cf_tok, $name, $meta);
    if (is_wp_error($res)) wp_send_json_error(array('message'=>$res->get_error_message()));

    $live_input_id = isset($res['uid']) ? $res['uid'] : (isset($res['id']) ? $res['id'] : '');
    $streamKey = isset($res['streamKey']) ? $res['streamKey'] : (isset($res['rtmps']['streamKey']) ? $res['rtmps']['streamKey'] : '');
    sm_cf_update_live_input($cf_acc,$cf_tok,$live_input_id);

    $post_id = wp_insert_post(array('post_type'=>'stream_class','post_status'=>'publish','post_title'=>$name));
    update_post_meta($post_id, '_sm_status', 'live');
    update_post_meta($post_id, '_sm_cf_live_input_uid', $live_input_id);
    update_post_meta($post_id, '_sm_cf_stream_key', $streamKey);
    update_post_meta($post_id, '_sm_category', $meta['category']);
    update_post_meta($post_id, '_sm_year', $meta['year']);
    update_post_meta($post_id, '_sm_batch', $meta['batch']);

    $slug = get_post_field('post_name', $post_id);
    $customer = trim(get_option('sm_cf_customer_subdomain',''));
    $cf_iframe = $customer ? ('https://'.$customer.'.cloudflarestream.com/'.$live_input_id.'/iframe') : '';

    wp_send_json_success(array(
        'post_id'=>$post_id,
        'live_input_id'=>$live_input_id,
        'slug'=> $slug,
        'stream_key'=> $streamKey,
        'cf_iframe'=> $cf_iframe
    ));
});

add_action('wp_ajax_sm_upload_recorded_file', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $att_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
    if (!$att_id) wp_send_json_error(array('message'=>'Please choose/upload an MP4 first via Media Library.'));

    $path = get_attached_file($att_id);
    if (!$path || !file_exists($path)) wp_send_json_error(array('message'=>'Local file not found on server.'));
    $mime = get_post_mime_type($att_id);
    if (strpos($mime, 'video') !== 0) wp_send_json_error(array('message'=>'Selected file is not a video'));

    $lib = get_option('sm_bunny_library_id',''); $key = get_option('sm_bunny_api_key','');
    $guid = sm_bunny_create_video($lib, $key, $title ? $title : get_the_title($att_id));
    if (is_wp_error($guid)) wp_send_json_error(array('message'=>$guid->get_error_message()));

    $upload = sm_bunny_upload_file($lib, $key, $guid, $path);
    if (is_wp_error($upload)) wp_send_json_error(array('message'=>$upload->get_error_message()));

    list($iframe,$hls) = sm_bunny_player_urls_for_guid($lib, $guid);

    $post_id = wp_insert_post(array('post_type'=>'stream_class','post_status'=>'publish','post_title'=> ($title ? $title : ('Recorded '.current_time('mysql')))));
    update_post_meta($post_id, '_sm_status', 'vod');
    update_post_meta($post_id, '_sm_bunny_guid', $guid);
    update_post_meta($post_id, '_sm_bunny_iframe', $iframe);
    update_post_meta($post_id, '_sm_bunny_hls', $hls);
    update_post_meta($post_id, '_sm_category', isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '');
    update_post_meta($post_id, '_sm_year', isset($_POST['year']) ? sanitize_text_field($_POST['year']) : '');
    update_post_meta($post_id, '_sm_batch', isset($_POST['batch']) ? sanitize_text_field($_POST['batch']) : '');

    sm_log('INFO', $post_id, 'Direct VOD uploaded to Bunny', '', '', '', $iframe);
    wp_send_json_success(array('ok'=>true,'iframe'=>$iframe,'hls'=>$hls,'post_id'=>$post_id,'slug'=>get_post_field('post_name',$post_id)));
});

add_action('wp_ajax_sm_manual_delete_cf_video', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();
    $cf_uid = isset($_POST['cf_uid']) ? sanitize_text_field($_POST['cf_uid']) : '';
    if (empty($cf_uid)) wp_send_json_error(array('message'=>'Video UID is required'));

    $acc = get_option('sm_cf_account_id','');
    $tok = get_option('sm_cf_api_token','');

    if (empty($acc) || empty($tok)) {
        wp_send_json_error(array('message'=>'Cloudflare API credentials not configured'));
    }

    $res = sm_cf_delete_video($acc, $tok, $cf_uid);

    if (is_wp_error($res)) {
        $data = $res->get_error_data();
        $code = isset($data['code']) ? $data['code'] : 'unknown';
        $body = isset($data['body']) ? $data['body'] : '';
        $error_msg = $res->get_error_message();

        sm_log('ERROR', 0, "Manual CF delete failed {$cf_uid}: {$error_msg} | Response: {$body}", $cf_uid);

        $diagnosis = sm_diagnose_cf_error($code, $body);

        wp_send_json_error(array(
            'message' => $error_msg,
            'http_code' => $code,
            'response_body' => $body,
            'diagnosis' => $diagnosis,
            'cf_uid' => $cf_uid
        ));
    } elseif ($res === true) {
        sm_log('INFO', 0, "Manual CF delete successful {$cf_uid}", $cf_uid);
        wp_send_json_success(array(
            'message' => "Video {$cf_uid} deleted successfully from Cloudflare",
            'cf_uid' => $cf_uid
        ));
    } else {
        sm_log('ERROR', 0, "Manual CF delete failed {$cf_uid}: Unknown error", $cf_uid);
        wp_send_json_error(array('message'=>'Unknown error occurred'));
    }
});

add_action('wp_ajax_sm_verify_cf_permissions', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $account_id = isset($_POST['account_id']) ? sanitize_text_field($_POST['account_id']) : '';
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

    if (empty($account_id) || empty($token)) {
        wp_send_json_error(array('message'=>'Account ID and API Token are required'));
    }

    $result = sm_cf_verify_permissions($account_id, $token);

    if (!empty($result['errors']) && count($result['errors']) === 3) {
        wp_send_json_error(array(
            'message'=>'Failed to verify permissions',
            'errors'=>$result['errors']
        ));
    }

    wp_send_json_success($result);
});
