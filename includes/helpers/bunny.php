<?php
if (!defined('ABSPATH')) exit;

function sm_bunny_base(){
    $base = trim(get_option('sm_bunny_base_url','https://video.bunnycdn.com'),'/');
    return $base ? $base : 'https://video.bunnycdn.com';
}
function sm_bunny_headers($api_key){ return array('AccessKey'=>$api_key); }

function sm_bunny_create_video($library_id,$api_key,$title,$meta=array()){
    $url = sm_bunny_base()."/library/{$library_id}/videos";
    $res = wp_remote_post($url,array(
        'headers'=>array_merge(sm_bunny_headers($api_key), array('Content-Type'=>'application/json')),
        'body'=>wp_json_encode(array('title'=>$title)),
        'timeout'=>60
    ));
    if (is_wp_error($res)) return $res;
    $json=json_decode(wp_remote_retrieve_body($res),true);
    if (empty($json['guid'])) return new WP_Error('bunny_create_failed','Bunny create failed',array('response'=>$res));
    return $json['guid'];
}

function sm_bunny_upload_file($library_id,$api_key,$guid,$file_path){
    if (!file_exists($file_path)) return new WP_Error('file_missing','File not found');
    $url = sm_bunny_base()."/library/{$library_id}/videos/{$guid}";
    $args = array(
        'method'  => 'PUT',
        'headers' => array_merge(sm_bunny_headers($api_key), array('Content-Type'=>'video/mp4')),
        'timeout' => 300,
        'body'    => file_get_contents($file_path)
    );
    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    return ($code>=200 && $code<300) ? true : new WP_Error('bunny_upload_failed','Upload failed', array('status'=>$code,'response'=>$res));
}

function sm_bunny_get_video($library_id,$api_key,$guid){
    $url = sm_bunny_base()."/library/{$library_id}/videos/{$guid}";
    $res = wp_remote_get($url,array(
        'headers'=>sm_bunny_headers($api_key),
        'timeout'=>30
    ));
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code >= 400) return new WP_Error('bunny_get_failed','Failed to get video info',array('status'=>$code));
    $json = json_decode(wp_remote_retrieve_body($res),true);
    return $json ? $json : new WP_Error('bunny_invalid_json','Invalid JSON response');
}

function sm_bunny_is_video_ready($library_id,$api_key,$guid){
    // If credentials are missing, assume video is ready (fail open)
    if (empty($library_id) || empty($api_key) || empty($guid)) return true;

    $video = sm_bunny_get_video($library_id,$api_key,$guid);
    // If API call fails, assume video is ready to avoid blocking playback
    if (is_wp_error($video)) return true;

    // Video is ready if encodeProgress is 100 (or if the field doesn't exist, assume ready)
    $progress = isset($video['encodeProgress']) ? intval($video['encodeProgress']) : 100;
    return $progress >= 100;
}

function sm_bunny_player_urls_for_guid($library_id,$guid){
    $iframe = "https://iframe.mediadelivery.net/embed/{$library_id}/{$guid}";
    $hls = "https://vz-{$library_id}-{$guid}.video.delivery/videos/{$guid}/playlist.m3u8";
    return array($iframe,$hls);
}
