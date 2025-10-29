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
    $global_key = get_option('sm_cf_global_api_key','');
    $global_email = get_option('sm_cf_global_email','');

    if (empty($acc)) {
        wp_send_json_error(array('message'=>'Cloudflare Account ID not configured'));
    }

    // Require either Bearer token OR (Global API Key + Email)
    if (empty($tok) && (empty($global_key) || empty($global_email))) {
        wp_send_json_error(array('message'=>'Cloudflare API credentials not configured. Please configure either API Token or Global API Key + Email.'));
    }

    $res = sm_cf_delete_video($acc, $tok, $cf_uid, $global_key, $global_email);

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

add_action('wp_ajax_sm_delete_stream', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $cf_uid = isset($_POST['cf_uid']) ? sanitize_text_field($_POST['cf_uid']) : '';
    $bunny_guid = isset($_POST['bunny_guid']) ? sanitize_text_field($_POST['bunny_guid']) : '';

    if (!$post_id) wp_send_json_error(array('message'=>'Post ID is required'));

    $errors = array();
    $success_msgs = array();

    // Delete from Cloudflare if CF UID exists
    if (!empty($cf_uid)) {
        $acc = get_option('sm_cf_account_id','');
        $tok = get_option('sm_cf_api_token','');
        $global_key = get_option('sm_cf_global_api_key','');
        $global_email = get_option('sm_cf_global_email','');

        $res = sm_cf_delete_video($acc, $tok, $cf_uid, $global_key, $global_email);
        if (is_wp_error($res)) {
            $errors[] = 'Cloudflare: ' . $res->get_error_message();
            sm_log('ERROR', $post_id, "Delete from CF failed: {$res->get_error_message()}", $cf_uid);
        } else {
            $success_msgs[] = 'Deleted from Cloudflare';
            sm_log('INFO', $post_id, "Deleted from Cloudflare", $cf_uid);
        }
    }

    // Delete from Bunny if GUID exists
    if (!empty($bunny_guid)) {
        $lib = get_option('sm_bunny_library_id','');
        $key = get_option('sm_bunny_api_key','');

        if (!empty($lib) && !empty($key)) {
            $url = sm_bunny_base()."/library/{$lib}/videos/{$bunny_guid}";
            $res = wp_remote_request($url, array(
                'method' => 'DELETE',
                'headers' => sm_bunny_headers($key),
                'timeout' => 30
            ));

            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 300) {
                $errors[] = 'Bunny: Failed to delete video';
                sm_log('ERROR', $post_id, "Delete from Bunny failed", '', '', '', $bunny_guid);
            } else {
                $success_msgs[] = 'Deleted from Bunny';
                sm_log('INFO', $post_id, "Deleted from Bunny", '', '', '', $bunny_guid);
            }
        }
    }

    // Delete the WordPress post
    wp_delete_post($post_id, true);
    $success_msgs[] = 'Post deleted';

    if (!empty($errors)) {
        wp_send_json_error(array('message'=>implode('; ', $errors), 'partial_success'=>$success_msgs));
    } else {
        wp_send_json_success(array('message'=>implode('; ', $success_msgs)));
    }
});

add_action('wp_ajax_sm_retry_transfer', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $cf_uid = isset($_POST['cf_uid']) ? sanitize_text_field($_POST['cf_uid']) : '';

    if (!$post_id || !$cf_uid) wp_send_json_error(array('message'=>'Post ID and CF UID are required'));

    // Check if video exists in Cloudflare
    $acc = get_option('sm_cf_account_id','');
    $tok = get_option('sm_cf_api_token','');

    if (empty($acc) || empty($tok)) {
        wp_send_json_error(array('message'=>'Cloudflare credentials not configured'));
    }

    $video_check = sm_cf_check_video_exists($acc, $tok, $cf_uid);

    if (is_wp_error($video_check)) {
        $error_msg = $video_check->get_error_message();
        sm_log('ERROR', $post_id, "Retry failed: {$error_msg}", $cf_uid);
        wp_send_json_error(array('message'=>"Cannot retry transfer: {$error_msg}"));
    }

    // Reset transfer status
    delete_post_meta($post_id, '_sm_transfer_done');
    update_post_meta($post_id, '_sm_status', 'processing');

    sm_log('INFO', $post_id, "Manual retry transfer initiated (video exists in CF)", $cf_uid);

    // Start transfer with attempt 0
    if (function_exists('sm_start_transfer_to_bunny')) {
        sm_start_transfer_to_bunny($post_id, $cf_uid, 0);
        wp_send_json_success(array('message'=>'Transfer retry initiated. Check Transfer Logs for progress.'));
    } else {
        wp_send_json_error(array('message'=>'Transfer function not available'));
    }
});
