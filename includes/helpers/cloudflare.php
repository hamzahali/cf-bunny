<?php
if (!defined('ABSPATH')) exit;

function sm_cf_headers($token){ return array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'); }

function sm_cf_create_live_input($account_id,$token,$name,$meta=array()){
    $url="https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/live_inputs";
    $body=array('name'=>$name,'meta'=>array('name'=>$name)+$meta);
    $res=wp_remote_post($url,array('headers'=>sm_cf_headers($token),'body'=>wp_json_encode($body),'timeout'=>30));
    if (is_wp_error($res)) return $res;
    $code=wp_remote_retrieve_response_code($res); $json=json_decode(wp_remote_retrieve_body($res),true);
    if ($code<200||$code>=300||empty($json['success'])) return new WP_Error('cf_create_failed','Cloudflare create failed',array('response'=>$res));
    return $json['result'];
}

function sm_cf_update_live_input($account_id,$token,$live_input_id,$args=array()){
    $url="https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/live_inputs/{$live_input_id}";
    $body=array('recording'=>array('mode'=>'automatic'))+$args;
    $res=wp_remote_request($url,array('method'=>'PUT','headers'=>sm_cf_headers($token),'body'=>wp_json_encode($body),'timeout'=>30));
    if (is_wp_error($res)) return $res;
    $code=wp_remote_retrieve_response_code($res);
    if ($code<200||$code>=300) return new WP_Error('cf_update_failed','Cloudflare update failed',array('response'=>$res));
    return json_decode(wp_remote_retrieve_body($res),true);
}

function sm_cf_enable_and_wait_mp4($account_id,$token,$video_uid,$timeout_sec=300){
    $base="https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/{$video_uid}";
    $enable=wp_remote_post($base.'/downloads',array('headers'=>array('Authorization'=>'Bearer '.$token),'timeout'=>20));
    if (is_wp_error($enable)) return $enable;
    $start=time(); $mp4='';
    while (time()-$start < $timeout_sec){
        $res=wp_remote_get($base.'/downloads',array('headers'=>array('Authorization'=>'Bearer '.$token),'timeout'=>20));
        if (is_wp_error($res)) return $res;
        $json=json_decode(wp_remote_retrieve_body($res),true);
        if (!empty($json['result']['default']) && isset($json['result']['default']['status']) && $json['result']['default']['status']==='ready'){
            $mp4 = isset($json['result']['default']['url']) ? $json['result']['default']['url'] : '';
            if (!empty($mp4)) break;
        }
        sleep(5);
    }
    return !empty($mp4) ? $mp4 : new WP_Error('mp4_timeout','MP4 not ready');
}

function sm_cf_delete_video($account_id,$token,$video_uid){
    $url="https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/videos/{$video_uid}";
    $res=wp_remote_request($url,array('method'=>'DELETE','headers'=>sm_cf_headers($token),'timeout'=>20));
    if (is_wp_error($res)) return $res;
    $code=wp_remote_retrieve_response_code($res);
    $body=wp_remote_retrieve_body($res);
    if ($code>=200&&$code<300) return true;
    return new WP_Error('cf_delete_failed',"Cloudflare delete failed with HTTP {$code}",array('code'=>$code,'body'=>$body,'video_uid'=>$video_uid));
}
