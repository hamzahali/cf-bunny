<?php
if (!defined('ABSPATH')) exit;

function sm_handle_stream_connected($data, $live_input) {
    // This handles video.live_input.connected event - when someone starts streaming

    if (empty($live_input)) {
        // Try to extract from different payload structures
        $live_input = isset($data['uid']) ? $data['uid'] : '';
        if (empty($live_input) && isset($data['liveInput']['uid'])) {
            $live_input = $data['liveInput']['uid'];
        }
    }

    if (empty($live_input)) {
        if (function_exists('sm_log')) {
            sm_log('INFO', 0, 'Stream connected webhook received but no live input UID found');
        }
        return new WP_REST_Response(array('ok'=>true,'ignored'=>true,'reason'=>'no_live_input_uid'),200);
    }

    // Look up stream key in registry
    $stream_key_data = null;
    if (function_exists('sm_get_stream_key_by_uid')) {
        $stream_key_data = sm_get_stream_key_by_uid($live_input);
    }

    // Generate title
    $title = 'Recording ' . current_time('Y-m-d H:i');
    if ($stream_key_data) {
        $title = $stream_key_data->name . ' - ' . current_time('Y-m-d H:i');
    }

    // Create new post for this live stream
    $post_id = wp_insert_post(array(
        'post_type' => 'stream_class',
        'post_status' => 'publish',
        'post_title' => $title
    ));

    if (!$post_id) {
        if (function_exists('sm_log')) {
            sm_log('ERROR', 0, "Failed to create post for live stream {$live_input}");
        }
        return new WP_REST_Response(array('ok'=>false,'error'=>'failed_to_create_post'),500);
    }

    // Mark as live
    update_post_meta($post_id, '_sm_status', 'live');
    update_post_meta($post_id, '_sm_cf_live_input_uid', $live_input);
    update_post_meta($post_id, '_sm_live_session_start', current_time('mysql'));

    // Inherit metadata from stream key registry
    if ($stream_key_data) {
        if (!empty($stream_key_data->default_subject)) {
            update_post_meta($post_id, '_sm_subject', $stream_key_data->default_subject);
        }
        if (!empty($stream_key_data->default_category)) {
            update_post_meta($post_id, '_sm_category', $stream_key_data->default_category);
        }
        if (!empty($stream_key_data->default_year)) {
            update_post_meta($post_id, '_sm_year', $stream_key_data->default_year);
        }
        if (!empty($stream_key_data->default_batch)) {
            update_post_meta($post_id, '_sm_batch', $stream_key_data->default_batch);
        }

        if (function_exists('sm_log')) {
            sm_log('INFO', $post_id, "Live stream started for '{$stream_key_data->name}'", '', $live_input);
        }
    } else {
        if (function_exists('sm_log')) {
            sm_log('INFO', $post_id, "Live stream started (no registry match)", '', $live_input);
        }
    }

    // Create notification
    if (function_exists('sm_create_notification')) {
        $notification_title = 'Live stream started';
        $notification_message = 'Stream: ' . $title;
        sm_create_notification('info', $notification_title, $notification_message, $post_id, '');
    }

    return new WP_REST_Response(array('ok'=>true,'post_id'=>$post_id,'status'=>'live'),200);
}

function sm_verify_cf_webhook_signature($secret, $raw_body){
    $sigHeader = isset($_SERVER['HTTP_WEBHOOK_SIGNATURE']) ? $_SERVER['HTTP_WEBHOOK_SIGNATURE'] : '';
    if (empty($secret) || empty($sigHeader)) return false;
    $parts = explode(',', $sigHeader); $map = array();
    foreach ($parts as $p){ $kv = explode('=', trim($p), 2); if (count($kv)===2) $map[$kv[0]]=$kv[1]; }
    $time = isset($map['time']) ? $map['time'] : ''; $sig1 = isset($map['sig1']) ? $map['sig1'] : '';
    if (empty($time) || empty($sig1)) return false;
    if (abs(time() - intval($time)) > 300) return false;
    $expected = hash_hmac('sha256', $time . '.' . $raw_body, $secret);
    if (function_exists('hash_equals')) return hash_equals($expected, $sig1);
    return $expected === $sig1;
}

function sm_cf_webhook_handler(WP_REST_Request $req){
    $raw = $req->get_body();
    $headers = $req->get_headers();
    if (defined('WP_DEBUG') && WP_DEBUG) { error_log("==== Stream Manager Webhook Received ===="); error_log(print_r($headers, true)); error_log($raw); }

    $bypass = get_option('sm_cf_bypass_secret', false);
    if (!$bypass) {
        $secret = get_option('sm_cf_webhook_secret','');
        if (empty($secret) || !sm_verify_cf_webhook_signature($secret, $raw)) { return new WP_REST_Response(array('ok'=>false,'error'=>'unauthorized'),401); }
    }

    $data = json_decode($raw, true);
    if (!$data) return new WP_REST_Response(array('ok'=>false,'error'=>'bad_json'),400);

    $event = isset($data['event']) ? $data['event'] : (isset($data['payload']['event']) ? $data['payload']['event'] : '');
    $video_uid = isset($data['video']['uid']) ? $data['video']['uid'] : (isset($data['payload']['video']['uid']) ? $data['payload']['video']['uid'] : '');
    $live_input = isset($data['liveInput']) ? $data['liveInput'] : (isset($data['payload']['video']['liveInput']) ? $data['payload']['video']['liveInput'] : '');
    if (!$event && !$video_uid && isset($data['uid']) && !empty($data['readyToStream'])) { $event='video.ready'; $video_uid=$data['uid']; $live_input = isset($data['liveInput']) ? $data['liveInput'] : ''; }

    // Handle live stream connected event (stream starts)
    if ($event === 'live_input.connected' || $event === 'video.live_input.connected') {
        return sm_handle_stream_connected($data, $live_input);
    }

    if (empty($event) || empty($video_uid)) { if (function_exists('sm_log')) sm_log('INFO', 0, 'Webhook received but not a video.ready payload'); return new WP_REST_Response(array('ok'=>true,'ignored'=>true),200); }

    // Check if this video_uid already exists (uniqueness check)
    $existing = get_posts(array(
        'post_type' => 'stream_class',
        'meta_key' => '_sm_cf_video_uid',
        'meta_value' => $video_uid,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ));

    if ($existing) {
        // Video already processed, skip
        if (function_exists('sm_log')) {
            sm_log('INFO', $existing[0], "Webhook {$event} for {$video_uid} - already processed, skipping", $video_uid);
        }
        return new WP_REST_Response(array('ok'=>true,'already_processed'=>true),200);
    }

    // Look for an existing 'live' post to update (from when streaming started)
    $post_id = null;
    if ($live_input) {
        $live_posts = get_posts(array(
            'post_type' => 'stream_class',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_sm_cf_live_input_uid',
                    'value' => $live_input,
                    'compare' => '='
                ),
                array(
                    'key' => '_sm_status',
                    'value' => 'live',
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        if (!empty($live_posts)) {
            $post_id = $live_posts[0]->ID;
            if (function_exists('sm_log')) {
                sm_log('INFO', $post_id, "Found existing live post to update with recording {$video_uid}", $video_uid);
            }
        }
    }

    // Look up stream key in registry
    $stream_key_data = null;
    if ($live_input && function_exists('sm_get_stream_key_by_uid')) {
        $stream_key_data = sm_get_stream_key_by_uid($live_input);
    }

    // If no existing live post, create a new one (fallback for missed connected webhook)
    if (!$post_id) {
        // Generate auto title: "Recording YYYY-MM-DD HH:MM"
        $auto_title = 'Recording ' . current_time('Y-m-d H:i');

        // Get incoming title from webhook (optional, usually not set)
        $incoming_title = '';
        if (isset($data['meta']['name'])) $incoming_title = sanitize_text_field($data['meta']['name']);
        elseif (isset($data['payload']['video']['meta']['name'])) $incoming_title = sanitize_text_field($data['payload']['video']['meta']['name']);

        $title = $incoming_title ? $incoming_title : $auto_title;

        // Create new post
        $post_id = wp_insert_post(array(
            'post_type' => 'stream_class',
            'post_status' => 'publish',
            'post_title' => $title
        ));

        if (!$post_id) {
            if (function_exists('sm_log')) sm_log('ERROR', 0, "Failed to create post for video {$video_uid}", $video_uid);
            return new WP_REST_Response(array('ok'=>false,'error'=>'failed_to_create_post'),500);
        }

        // Inherit metadata from stream key registry
        if ($stream_key_data) {
            if (!empty($stream_key_data->default_subject)) {
                update_post_meta($post_id, '_sm_subject', $stream_key_data->default_subject);
            }
            if (!empty($stream_key_data->default_category)) {
                update_post_meta($post_id, '_sm_category', $stream_key_data->default_category);
            }
            if (!empty($stream_key_data->default_year)) {
                update_post_meta($post_id, '_sm_year', $stream_key_data->default_year);
            }
            if (!empty($stream_key_data->default_batch)) {
                update_post_meta($post_id, '_sm_batch', $stream_key_data->default_batch);
            }
        }

        if ($live_input) {
            update_post_meta($post_id, '_sm_cf_live_input_uid', $live_input);
        }
    }

    // Save core recording metadata (whether new or updating existing)
    update_post_meta($post_id, '_sm_cf_video_uid', $video_uid);
    update_post_meta($post_id, '_sm_status', 'processing');

    // Update stream key stats
    if ($stream_key_data && function_exists('sm_update_stream_key_stats')) {
        sm_update_stream_key_stats($live_input);
    }

    if (function_exists('sm_log')) {
        $action = $live_posts ? 'updated existing' : 'created new';
        $from_msg = $stream_key_data ? " from '{$stream_key_data->name}'" : '';
        sm_log('INFO', $post_id, "Recording ready: {$action} post{$from_msg}", $video_uid);
    }

    // Create notification
    if (function_exists('sm_create_notification')) {
        $notification_title = 'New recording imported';
        $notification_message = 'Recording: ' . $title;
        if ($stream_key_data) {
            $notification_message .= ' from ' . $stream_key_data->name;
        }
        sm_create_notification('success', $notification_title, $notification_message, $post_id, $video_uid);
    }

    // Start transfer to Bunny
    update_post_meta($post_id, '_sm_transfer_done', current_time('mysql'));
    if (function_exists('sm_start_transfer_to_bunny')) {
        sm_start_transfer_to_bunny($post_id, $video_uid, 0);
    }

    return new WP_REST_Response(array('ok'=>true,'post_id'=>$post_id,'auto_imported'=>true),200);
}

add_action('rest_api_init', function(){
    register_rest_route('stream/v1','/cf-webhook',array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>'sm_cf_webhook_handler'));
    register_rest_route('stream/v1','/item',array(
        'methods'=>'GET','permission_callback'=>'__return_true',
        'callback'=>function(WP_REST_Request $req){
            $slug = sanitize_title($req->get_param('slug'));
            $post = get_page_by_path($slug, OBJECT, 'stream_class');
            if (!$post) return new WP_REST_Response(array('error'=>'not_found'),404);
            $status = get_post_meta($post->ID,'_sm_status',true); if (empty($status)) $status='processing';
            $cf_live = get_post_meta($post->ID,'_sm_cf_live_input_uid',true);
            $cf_vid  = get_post_meta($post->ID,'_sm_cf_video_uid',true);
            $bunny_guid = get_post_meta($post->ID,'_sm_bunny_guid',true);
            $lib = get_option('sm_bunny_library_id','');
            $key = get_option('sm_bunny_api_key','');
            $sub = trim(get_option('sm_cf_customer_subdomain',''));

            $cf_iframe = '';
            if (!empty($sub) && !empty($cf_live)){ $cf_iframe = 'https://'.$sub.'.cloudflarestream.com/'.$cf_live.'/iframe'; }
            elseif (!empty($sub) && !empty($cf_vid)){ $cf_iframe = 'https://'.$sub.'.cloudflarestream.com/'.$cf_vid.'/iframe'; }

            $bunny_iframe = (!empty($lib) && !empty($bunny_guid)) ? ('https://iframe.mediadelivery.net/embed/'.$lib.'/'.$bunny_guid) : '';

            if (!empty($bunny_guid)) $status = 'vod';

            return new WP_REST_Response(array('status'=>$status,'urls'=>array('cfOfficialIframe'=>$cf_iframe,'bunnyOfficialIframe'=>$bunny_iframe)),200);
        }
    ));
});
