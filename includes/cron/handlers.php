<?php
if (!defined('ABSPATH')) exit;

add_action('sm_transfer_retry_event', function($post_id, $cf_uid, $attempt){
    sm_start_transfer_to_bunny($post_id, $cf_uid, $attempt);
}, 10, 3);

add_action('sm_cf_delete_event', function($cf_uid){
    $acc = get_option('sm_cf_account_id','');
    $tok = get_option('sm_cf_api_token','');
    $global_key = get_option('sm_cf_global_api_key','');
    $global_email = get_option('sm_cf_global_email','');
    $res = sm_cf_delete_video($acc, $tok, $cf_uid, $global_key, $global_email);
    if (is_wp_error($res)) {
        $data = $res->get_error_data();
        $code = isset($data['code']) ? $data['code'] : 'unknown';
        $body = isset($data['body']) ? $data['body'] : '';
        sm_log('ERROR', 0, "CF delete failed {$cf_uid}: {$res->get_error_message()} | Response: {$body}", $cf_uid);
    } elseif ($res === true) {
        sm_log('INFO', 0, "CF deleted {$cf_uid}", $cf_uid);
    } else {
        sm_log('ERROR', 0, "CF delete failed {$cf_uid}: Unknown error", $cf_uid);
    }
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

    $mp4 = sm_cf_enable_and_wait_mp4($acc, $tok, $cf_uid, 900);
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

// Scheduled Sync Cron Handler

add_action('sm_sync_cron_event', 'sm_run_scheduled_sync');

function sm_run_scheduled_sync() {
    // Check if sync is enabled
    $enabled = get_option('sm_sync_enabled', 0);

    if (!$enabled) {
        return; // Sync disabled
    }

    // Get all stream keys
    $stream_keys = sm_get_all_stream_keys();

    if (empty($stream_keys)) {
        sm_log_sync_event('cron', 0, 0, 'success', 'No stream keys to sync');
        return;
    }

    // Get Cloudflare credentials
    $cf_acc = get_option('sm_cf_account_id','');
    $cf_tok = get_option('sm_cf_api_token','');

    if (empty($cf_acc) || empty($cf_tok)) {
        sm_log_sync_event('cron', 0, 0, 'error', 'Cloudflare credentials not configured');
        return;
    }

    $total_found = 0;
    $total_imported = 0;
    $errors = array();

    // Loop through each stream key
    foreach ($stream_keys as $stream_key) {
        // Fetch recordings from Cloudflare
        $recordings = sm_cf_get_live_input_videos($cf_acc, $cf_tok, $stream_key->live_input_uid);

        if (is_wp_error($recordings)) {
            $errors[] = "Failed to fetch from {$stream_key->name}: " . $recordings->get_error_message();
            continue;
        }

        // Check which are new
        foreach ($recordings as $video) {
            $video_uid = isset($video['uid']) ? $video['uid'] : '';

            if (empty($video_uid)) {
                continue;
            }

            $total_found++;

            // Check if already in WordPress
            $existing = get_posts(array(
                'post_type' => 'stream_class',
                'meta_key' => '_sm_cf_video_uid',
                'meta_value' => $video_uid,
                'posts_per_page' => 1,
                'fields' => 'ids'
            ));

            if (!empty($existing)) {
                continue; // Already imported
            }

            // Import this recording
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
            update_post_meta($post_id, '_sm_cf_live_input_uid', $stream_key->live_input_uid);
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
            sm_update_stream_key_stats($stream_key->live_input_uid);

            // Create notification
            sm_create_notification(
                'success',
                'Recording imported (automatic sync)',
                "Recording: {$title} from {$stream_key->name}",
                $post_id,
                $video_uid
            );

            // Log
            sm_log('INFO', $post_id, "Cron sync imported video {$video_uid} from '{$stream_key->name}'", $video_uid);

            // Start transfer
            update_post_meta($post_id, '_sm_transfer_done', current_time('mysql'));
            sm_start_transfer_to_bunny($post_id, $video_uid, 0);

            $total_imported++;
        }
    }

    // Log sync event
    $status = empty($errors) ? 'success' : 'error';
    $message = "Cron sync: found {$total_found}, imported {$total_imported}";
    if (!empty($errors)) {
        $message .= '. Errors: ' . implode('; ', $errors);
    }

    sm_log_sync_event('cron', $total_found, $total_imported, $status, $message);

    // Send email notification if enabled and recordings found
    if ($total_imported > 0) {
        $email_enabled = get_option('sm_sync_email_notify', 0);

        if ($email_enabled) {
            sm_send_sync_email_notification($total_imported, $stream_keys);
        }
    }
}

// Email notification function
function sm_send_sync_email_notification($count, $stream_keys) {
    $email = get_option('sm_sync_email_address', get_option('admin_email'));

    if (empty($email)) {
        return;
    }

    $subject = sprintf('[%s] %d new recording(s) imported - Stream Manager', get_bloginfo('name'), $count);

    $message = "Hello,\n\n";
    $message .= "The Stream Manager automatic sync found and imported {$count} new recording(s) from Cloudflare.\n\n";
    $message .= "View all recordings:\n";
    $message .= admin_url('admin.php?page=sm_dashboard') . "\n\n";
    $message .= "View notifications:\n";
    $message .= admin_url('admin.php?page=sm_notifications') . "\n\n";
    $message .= "---\n";
    $message .= "This is an automated message from Stream Manager plugin.\n";
    $message .= "To disable these notifications, go to Settings > Stream Manager > Sync Settings.\n";

    wp_mail($email, $subject, $message);
}
