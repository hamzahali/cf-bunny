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
