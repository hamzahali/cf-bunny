<?php
if (!defined('ABSPATH')) exit;

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

    if (empty($event) || empty($video_uid)) { if (function_exists('sm_log')) sm_log('INFO', 0, 'Webhook received but not a video.ready payload'); return new WP_REST_Response(array('ok'=>true,'ignored'=>true),200); }

    $post_id = 0;
    if ($live_input) {
        $q = get_posts(array('post_type'=>'stream_class','meta_key'=>'_sm_cf_live_input_uid','meta_value'=>$live_input,'posts_per_page'=>1,'fields'=>'ids'));
        if ($q) $post_id = $q[0];
    }
    if (!$post_id) {
        $q = get_posts(array('post_type'=>'stream_class','meta_key'=>'_sm_cf_video_uid','meta_value'=>$video_uid,'posts_per_page'=>1,'fields'=>'ids'));
        if ($q) $post_id = $q[0];
    }

    $incoming_title = '';
    if (isset($data['meta']['name'])) $incoming_title = sanitize_text_field($data['meta']['name']);
    elseif (isset($data['payload']['video']['meta']['name'])) $incoming_title = sanitize_text_field($data['payload']['video']['meta']['name']);

    if (!$post_id){
        $title = $incoming_title ? $incoming_title : ('Stream '.$video_uid);
        $post_id = wp_insert_post(array('post_type'=>'stream_class','post_status'=>'publish','post_title'=>$title));
        if ($live_input) update_post_meta($post_id,'_sm_cf_live_input_uid',$live_input);
    }
    if ($incoming_title) wp_update_post(array('ID'=>$post_id,'post_title'=>$incoming_title));

    update_post_meta($post_id, '_sm_cf_video_uid', $video_uid);
    update_post_meta($post_id, '_sm_status', 'processing');
    if (function_exists('sm_log')) sm_log('INFO',$post_id,"Webhook {$event} for {$video_uid}",$video_uid);

    $already = get_post_meta($post_id, '_sm_transfer_done', true);
    if (empty($already)) {
        update_post_meta($post_id, '_sm_transfer_done', current_time('mysql'));
        if (function_exists('sm_start_transfer_to_bunny')) sm_start_transfer_to_bunny($post_id, $video_uid, 0);
    }

    return new WP_REST_Response(array('ok'=>true),200);
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

            $bunny_iframe = '';
            if (!empty($lib) && !empty($bunny_guid)) {
                // Check if video is ready before returning iframe URL
                if (function_exists('sm_bunny_is_video_ready') && sm_bunny_is_video_ready($lib, $key, $bunny_guid)) {
                    $bunny_iframe = 'https://iframe.mediadelivery.net/embed/'.$lib.'/'.$bunny_guid;
                    $status = 'vod';
                } else {
                    // Video is still encoding, keep status as processing
                    $status = 'processing';
                }
            }

            return new WP_REST_Response(array('status'=>$status,'urls'=>array('cfOfficialIframe'=>$cf_iframe,'bunnyOfficialIframe'=>$bunny_iframe)),200);
        }
    ));
});
