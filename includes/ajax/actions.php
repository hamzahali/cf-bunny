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

// Registry AJAX Handlers

add_action('wp_ajax_sm_create_stream_key', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : '';
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : '';
    $batch = isset($_POST['batch']) ? sanitize_text_field($_POST['batch']) : '';

    if (empty($name)) {
        wp_send_json_error(array('message' => 'Display name is required'));
    }

    // Create live input in Cloudflare
    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');

    if (empty($cf_acc) || empty($cf_tok)) {
        wp_send_json_error(array('message' => 'Cloudflare credentials not configured'));
    }

    $res = sm_cf_create_live_input($cf_acc, $cf_tok, $name, array());

    if (is_wp_error($res)) {
        wp_send_json_error(array('message' => $res->get_error_message()));
    }

    $live_input_uid = isset($res['uid']) ? $res['uid'] : (isset($res['id']) ? $res['id'] : '');
    $stream_key = isset($res['streamKey']) ? $res['streamKey'] : (isset($res['rtmps']['streamKey']) ? $res['rtmps']['streamKey'] : '');

    if (empty($live_input_uid) || empty($stream_key)) {
        wp_send_json_error(array('message' => 'Failed to get live input details from Cloudflare'));
    }

    // Enable recording
    sm_cf_update_live_input($cf_acc, $cf_tok, $live_input_uid);

    // Configure webhook for this live input (critical for live detection!)
    $webhook_result = sm_cf_set_live_input_webhook($cf_acc, $cf_tok, $live_input_uid);
    if (is_wp_error($webhook_result)) {
        error_log("Warning: Failed to configure webhook for live input {$live_input_uid}: " . $webhook_result->get_error_message());
        // Don't fail the whole operation, just log the warning
    }

    // Save to registry (NO post creation - posts created when streaming starts via webhook)
    $registry_id = sm_create_stream_key(array(
        'name' => $name,
        'live_input_uid' => $live_input_uid,
        'stream_key' => $stream_key,
        'default_subject' => $subject,
        'default_category' => $category,
        'default_year' => $year,
        'default_batch' => $batch
    ));

    if (!$registry_id) {
        wp_send_json_error(array('message' => 'Failed to save stream key to registry'));
    }

    $customer = trim(get_option('sm_cf_customer_subdomain',''));
    $cf_iframe = $customer ? ('https://'.$customer.'.cloudflarestream.com/'.$live_input_uid.'/iframe') : '';
    $universal_embed = site_url('/?stream_embed=1&live_input_uid='.$live_input_uid);

    wp_send_json_success(array(
        'registry_id' => $registry_id,
        'live_input_uid' => $live_input_uid,
        'stream_key' => $stream_key,
        'cf_iframe' => $cf_iframe,
        'universal_embed' => $universal_embed,
        'rtmp_url' => 'rtmp://live.cloudflare.com/live'
    ));
});

add_action('wp_ajax_sm_update_stream_key', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $key_id = isset($_POST['key_id']) ? intval($_POST['key_id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : '';
    $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
    $year = isset($_POST['year']) ? sanitize_text_field($_POST['year']) : '';
    $batch = isset($_POST['batch']) ? sanitize_text_field($_POST['batch']) : '';

    if (!$key_id) {
        wp_send_json_error(array('message' => 'Stream key ID is required'));
    }

    if (empty($name)) {
        wp_send_json_error(array('message' => 'Display name is required'));
    }

    $result = sm_update_stream_key($key_id, array(
        'name' => $name,
        'default_subject' => $subject,
        'default_category' => $category,
        'default_year' => $year,
        'default_batch' => $batch
    ));

    if ($result) {
        wp_send_json_success(array('message' => 'Stream key updated successfully'));
    } else {
        wp_send_json_error(array('message' => 'Failed to update stream key'));
    }
});

add_action('wp_ajax_sm_delete_stream_key', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $key_id = isset($_POST['key_id']) ? intval($_POST['key_id']) : 0;

    if (!$key_id) {
        wp_send_json_error(array('message' => 'Stream key ID is required'));
    }

    $result = sm_delete_stream_key($key_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    } elseif ($result) {
        wp_send_json_success(array('message' => 'Stream key deleted successfully'));
    } else {
        wp_send_json_error(array('message' => 'Failed to delete stream key'));
    }
});

add_action('wp_ajax_sm_get_stream_key', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $key_id = isset($_POST['key_id']) ? intval($_POST['key_id']) : 0;

    if (!$key_id) {
        wp_send_json_error(array('message' => 'Stream key ID is required'));
    }

    $stream_key = sm_get_stream_key_by_id($key_id);

    if ($stream_key) {
        wp_send_json_success($stream_key);
    } else {
        wp_send_json_error(array('message' => 'Stream key not found'));
    }
});

add_action('wp_ajax_sm_sync_all_webhooks', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');

    if (empty($cf_acc) || empty($cf_tok)) {
        wp_send_json_error(array('message' => 'Cloudflare credentials not configured'));
    }

    // Get all stream keys
    $stream_keys = sm_get_all_stream_keys();

    if (empty($stream_keys)) {
        wp_send_json_error(array('message' => 'No stream keys found'));
    }

    $success_count = 0;
    $error_count = 0;
    $errors = array();

    foreach ($stream_keys as $key) {
        $result = sm_cf_set_live_input_webhook($cf_acc, $cf_tok, $key->live_input_uid);

        if (is_wp_error($result)) {
            $error_count++;
            $errors[] = $key->name . ': ' . $result->get_error_message();
        } else {
            $success_count++;
        }
    }

    $message = "Synced {$success_count} stream keys successfully.";
    if ($error_count > 0) {
        $message .= " {$error_count} failed.";
    }

    wp_send_json_success(array(
        'message' => $message,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'errors' => $errors
    ));
});

// Notification AJAX Handlers

add_action('wp_ajax_sm_mark_notification_read', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

    if (!$notification_id) {
        wp_send_json_error(array('message' => 'Notification ID is required'));
    }

    $result = sm_mark_notification_read($notification_id);

    if ($result) {
        wp_send_json_success(array('message' => 'Notification marked as read'));
    } else {
        wp_send_json_error(array('message' => 'Failed to mark notification as read'));
    }
});

add_action('wp_ajax_sm_mark_all_notifications_read', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $result = sm_mark_all_notifications_read();

    if ($result) {
        wp_send_json_success(array('message' => 'All notifications marked as read'));
    } else {
        wp_send_json_error(array('message' => 'Failed to mark notifications as read'));
    }
});

add_action('wp_ajax_sm_delete_notification', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

    if (!$notification_id) {
        wp_send_json_error(array('message' => 'Notification ID is required'));
    }

    $result = sm_delete_notification($notification_id);

    if ($result) {
        wp_send_json_success(array('message' => 'Notification deleted'));
    } else {
        wp_send_json_error(array('message' => 'Failed to delete notification'));
    }
});

// Manual Sync AJAX Handlers

add_action('wp_ajax_sm_scan_stream_key', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $stream_key_id = isset($_POST['stream_key_id']) ? intval($_POST['stream_key_id']) : 0;

    if (!$stream_key_id) {
        wp_send_json_error(array('message' => 'Stream key ID is required'));
    }

    $stream_key = sm_get_stream_key_by_id($stream_key_id);

    if (!$stream_key) {
        wp_send_json_error(array('message' => 'Stream key not found'));
    }

    // Get Cloudflare credentials
    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');

    if (empty($cf_acc) || empty($cf_tok)) {
        wp_send_json_error(array('message' => 'Cloudflare credentials not configured'));
    }

    // Fetch recordings from Cloudflare
    $recordings = sm_cf_get_live_input_videos($cf_acc, $cf_tok, $stream_key->live_input_uid);

    if (is_wp_error($recordings)) {
        wp_send_json_error(array('message' => $recordings->get_error_message()));
    }

    // Check which recordings are new (not in WordPress)
    $new_recordings = array();

    foreach ($recordings as $video) {
        $video_uid = isset($video['uid']) ? $video['uid'] : '';

        if (empty($video_uid)) {
            continue;
        }

        // Check if already in WordPress
        $existing = get_posts(array(
            'post_type' => 'stream_class',
            'meta_key' => '_sm_cf_video_uid',
            'meta_value' => $video_uid,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));

        if (empty($existing)) {
            // New recording
            $new_recordings[] = array(
                'video_uid' => $video_uid,
                'stream_key_id' => $stream_key_id,
                'stream_key_name' => $stream_key->name,
                'live_input_uid' => $stream_key->live_input_uid,
                'created' => isset($video['created']) ? $video['created'] : '',
                'duration' => isset($video['duration']) ? $video['duration'] : 0,
                'status' => isset($video['status']['state']) ? $video['status']['state'] : 'unknown'
            );
        }
    }

    wp_send_json_success(array(
        'new_recordings' => $new_recordings,
        'total_checked' => count($recordings)
    ));
});

add_action('wp_ajax_sm_import_recordings', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $recordings_json = isset($_POST['recordings']) ? $_POST['recordings'] : '';

    if (empty($recordings_json)) {
        wp_send_json_error('No recordings provided');
    }

    $recordings = json_decode(stripslashes($recordings_json), true);

    if (!is_array($recordings) || empty($recordings)) {
        wp_send_json_error('Invalid recordings data');
    }

    $imported = 0;
    $errors = array();

    foreach ($recordings as $rec) {
        $video_uid = isset($rec['video_uid']) ? sanitize_text_field($rec['video_uid']) : '';
        $stream_key_id = isset($rec['stream_key_id']) ? intval($rec['stream_key_id']) : 0;
        $live_input_uid = isset($rec['live_input_uid']) ? sanitize_text_field($rec['live_input_uid']) : '';

        if (empty($video_uid) || empty($stream_key_id)) {
            $errors[] = 'Missing required data for a recording';
            continue;
        }

        // Get stream key data
        $stream_key = sm_get_stream_key_by_id($stream_key_id);

        if (!$stream_key) {
            $errors[] = "Stream key not found for video {$video_uid}";
            continue;
        }

        // Check if already exists (double-check)
        $existing = get_posts(array(
            'post_type' => 'stream_class',
            'meta_key' => '_sm_cf_video_uid',
            'meta_value' => $video_uid,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ));

        if ($existing) {
            // Skip if already imported
            continue;
        }

        // Create post
        $title = 'Recording ' . current_time('Y-m-d H:i');

        $post_id = wp_insert_post(array(
            'post_type' => 'stream_class',
            'post_status' => 'publish',
            'post_title' => $title
        ));

        if (!$post_id || is_wp_error($post_id)) {
            $errors[] = "Failed to create post for video {$video_uid}";
            continue;
        }

        // Save metadata
        update_post_meta($post_id, '_sm_cf_video_uid', $video_uid);
        update_post_meta($post_id, '_sm_cf_live_input_uid', $live_input_uid);
        update_post_meta($post_id, '_sm_status', 'processing');

        // Inherit from stream key
        if (!empty($stream_key->default_subject)) {
            update_post_meta($post_id, '_sm_subject', $stream_key->default_subject);
        }
        if (!empty($stream_key->default_category)) {
            update_post_meta($post_id, '_sm_category', $stream_key->default_category);
        }
        if (!empty($stream_key->default_year)) {
            update_post_meta($post_id, '_sm_year', $stream_key->default_year);
        }
        if (!empty($stream_key->default_batch)) {
            update_post_meta($post_id, '_sm_batch', $stream_key->default_batch);
        }

        // Update stream key stats
        sm_update_stream_key_stats($live_input_uid);

        // Create notification
        sm_create_notification(
            'success',
            'Recording imported (manual sync)',
            "Recording: {$title} from {$stream_key->name}",
            $post_id,
            $video_uid
        );

        // Log
        if (function_exists('sm_log')) {
            sm_log('INFO', $post_id, "Manual sync imported video {$video_uid} from '{$stream_key->name}'", $video_uid);
        }

        // Start transfer
        update_post_meta($post_id, '_sm_transfer_done', current_time('mysql'));
        if (function_exists('sm_start_transfer_to_bunny')) {
            sm_start_transfer_to_bunny($post_id, $video_uid, 0);
        }

        $imported++;
    }

    // Log sync event
    sm_log_sync_event('manual', count($recordings), $imported, 'success', "Manual sync imported {$imported} of " . count($recordings) . " recordings");

    if (!empty($errors)) {
        wp_send_json_success(array(
            'imported' => $imported,
            'errors' => $errors
        ));
    } else {
        wp_send_json_success(array(
            'imported' => $imported
        ));
    }
});

// Webhook Diagnostics AJAX Handlers

add_action('wp_ajax_sm_test_webhook_endpoint', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $webhook_url = rest_url('stream/v1/cf-webhook');

    // Test if endpoint is accessible
    $response = wp_remote_post($webhook_url, array(
        'body' => json_encode(array('test' => true)),
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 10
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => 'Endpoint not accessible: ' . $response->get_error_message()
        ));
    }

    $code = wp_remote_retrieve_response_code($response);

    // We expect either 200 (bypass enabled) or 401 (signature validation)
    if ($code == 200 || $code == 401) {
        wp_send_json_success(array(
            'message' => "Endpoint is accessible (HTTP {$code}). Cloudflare can reach your site!",
            'code' => $code
        ));
    } else {
        wp_send_json_error(array(
            'message' => "Endpoint returned unexpected HTTP {$code}. Check logs for details.",
            'code' => $code
        ));
    }
});

add_action('wp_ajax_sm_check_webhook_config', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $live_input_uid = isset($_POST['live_input_uid']) ? sanitize_text_field($_POST['live_input_uid']) : '';

    if (empty($live_input_uid)) {
        wp_send_json_error(array('message' => 'Live input UID is required'));
    }

    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');

    if (empty($cf_acc) || empty($cf_tok)) {
        wp_send_json_error(array('message' => 'Cloudflare credentials not configured'));
    }

    // Get live input details from Cloudflare
    $result = sm_cf_get_live_input_webhook($cf_acc, $cf_tok, $live_input_uid);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    // Check if webhook is configured
    $webhook_configured = false;
    $webhook_url = '';
    $events = array();

    if (isset($result['webhook']) && !empty($result['webhook'])) {
        $webhook_configured = true;
        $webhook_url = isset($result['webhook']['url']) ? $result['webhook']['url'] : '';
        $events = isset($result['webhook']['events']) ? $result['webhook']['events'] : array();
    }

    wp_send_json_success(array(
        'webhook_configured' => $webhook_configured,
        'webhook_url' => $webhook_url,
        'events' => $events,
        'expected_url' => rest_url('stream/v1/cf-webhook'),
        'has_connected_event' => in_array('live_input.connected', $events)
    ));
});

add_action('wp_ajax_sm_send_test_webhook', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $live_input_uid = isset($_POST['live_input_uid']) ? sanitize_text_field($_POST['live_input_uid']) : '';

    if (empty($live_input_uid)) {
        wp_send_json_error(array('message' => 'Live input UID is required'));
    }

    // Simulate a live_input.connected webhook from Cloudflare
    $webhook_url = rest_url('stream/v1/cf-webhook');

    $test_payload = array(
        'event' => 'live_input.connected',
        'uid' => $live_input_uid,
        'liveInput' => $live_input_uid,
        'test' => true,
        'timestamp' => time()
    );

    // Check if bypass is enabled
    $bypass = get_option('sm_cf_bypass_secret', false);

    $headers = array('Content-Type' => 'application/json');

    if (!$bypass) {
        // Generate signature
        $secret = get_option('sm_cf_webhook_secret','');
        if (!empty($secret)) {
            $body = json_encode($test_payload);
            $timestamp = time();
            $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            $headers['Webhook-Signature'] = 'time=' . $timestamp . ',sig1=' . $signature;
        }
    }

    $response = wp_remote_post($webhook_url, array(
        'body' => json_encode($test_payload),
        'headers' => $headers,
        'timeout' => 10
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => 'Failed to send test webhook: ' . $response->get_error_message()
        ));
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code >= 200 && $code < 300) {
        wp_send_json_success(array(
            'message' => "Test webhook sent successfully! Check debug logs and 'All Streams' page.",
            'response_code' => $code,
            'response_body' => $body
        ));
    } else {
        wp_send_json_error(array(
            'message' => "Test webhook failed with HTTP {$code}",
            'response_code' => $code,
            'response_body' => $body
        ));
    }
});

// Webhook Configuration Test AJAX Handlers

add_action('wp_ajax_sm_test_set_webhook', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $live_input_uid = isset($_POST['live_input_uid']) ? sanitize_text_field($_POST['live_input_uid']) : '';

    if (empty($live_input_uid)) {
        wp_send_json_error(array('message' => 'Live input UID is required'));
    }

    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');
    $webhook_url = rest_url('stream/v1/cf-webhook');

    if (empty($cf_acc) || empty($cf_tok)) {
        wp_send_json_error(array('message' => 'Cloudflare credentials not configured'));
    }

    // Build request
    $url = "https://api.cloudflare.com/client/v4/accounts/{$cf_acc}/stream/live_inputs/{$live_input_uid}";

    $body = array(
        'webhook' => array(
            'url' => $webhook_url,
            'events' => array(
                'live_input.connected',
                'live_input.disconnected',
                'live_input.recording.ready',
                'live_input.recording.error'
            )
        )
    );

    $body_json = wp_json_encode($body);

    $headers = array(
        'Authorization' => 'Bearer ' . $cf_tok,
        'Content-Type' => 'application/json'
    );

    // Make request
    $response = wp_remote_request($url, array(
        'method' => 'PUT',
        'headers' => $headers,
        'body' => $body_json,
        'timeout' => 30
    ));

    // Prepare detailed response
    $request_headers_formatted = "Authorization: Bearer " . substr($cf_tok, 0, 10) . "...\nContent-Type: application/json";

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => 'Request failed: ' . $response->get_error_message(),
            'request_url' => $url,
            'request_headers' => $request_headers_formatted,
            'request_body' => json_encode($body, JSON_PRETTY_PRINT)
        ));
    }

    $code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $response_body_formatted = json_encode(json_decode($response_body), JSON_PRETTY_PRINT);

    if ($code >= 200 && $code < 300) {
        $json = json_decode($response_body, true);
        $webhook_set = isset($json['success']) && $json['success'];

        wp_send_json_success(array(
            'message' => 'Webhook configuration request succeeded!',
            'http_code' => $code,
            'request_url' => $url,
            'request_headers' => $request_headers_formatted,
            'request_body' => json_encode($body, JSON_PRETTY_PRINT),
            'response_body' => $response_body_formatted,
            'webhook_set' => $webhook_set
        ));
    } else {
        wp_send_json_error(array(
            'message' => "Request failed with HTTP {$code}",
            'http_code' => $code,
            'request_url' => $url,
            'request_headers' => $request_headers_formatted,
            'request_body' => json_encode($body, JSON_PRETTY_PRINT),
            'response_body' => $response_body_formatted
        ));
    }
});

add_action('wp_ajax_sm_test_get_webhook', function(){
    check_ajax_referer('sm_ajax_nonce','nonce'); sm_require_cap();

    $live_input_uid = isset($_POST['live_input_uid']) ? sanitize_text_field($_POST['live_input_uid']) : '';

    if (empty($live_input_uid)) {
        wp_send_json_error(array('message' => 'Live input UID is required'));
    }

    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');

    if (empty($cf_acc) || empty($cf_tok)) {
        wp_send_json_error(array('message' => 'Cloudflare credentials not configured'));
    }

    // Build request
    $url = "https://api.cloudflare.com/client/v4/accounts/{$cf_acc}/stream/live_inputs/{$live_input_uid}";

    $headers = array(
        'Authorization' => 'Bearer ' . $cf_tok,
        'Content-Type' => 'application/json'
    );

    // Make request
    $response = wp_remote_get($url, array(
        'headers' => $headers,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => 'Request failed: ' . $response->get_error_message(),
            'request_url' => $url
        ));
    }

    $code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $response_body_formatted = json_encode(json_decode($response_body), JSON_PRETTY_PRINT);

    if ($code >= 200 && $code < 300) {
        $json = json_decode($response_body, true);
        $result = isset($json['result']) ? $json['result'] : array();

        // Check webhook configuration
        $webhook_configured = false;
        $webhook_url = '';
        $events = array();

        if (isset($result['webhook']) && !empty($result['webhook'])) {
            $webhook_configured = true;
            $webhook_url = isset($result['webhook']['url']) ? $result['webhook']['url'] : '';
            $events = isset($result['webhook']['events']) ? $result['webhook']['events'] : array();
        }

        $expected_url = rest_url('stream/v1/cf-webhook');

        wp_send_json_success(array(
            'http_code' => $code,
            'request_url' => $url,
            'response_body' => $response_body_formatted,
            'webhook_configured' => $webhook_configured,
            'webhook_url' => $webhook_url,
            'events' => $events,
            'expected_url' => $expected_url,
            'url_matches' => ($webhook_url === $expected_url),
            'has_connected_event' => in_array('live_input.connected', $events)
        ));
    } else {
        wp_send_json_error(array(
            'message' => "Request failed with HTTP {$code}",
            'http_code' => $code,
            'request_url' => $url,
            'response_body' => $response_body_formatted
        ));
    }
});
