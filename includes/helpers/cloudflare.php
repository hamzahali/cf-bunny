<?php
if (!defined('ABSPATH')) exit;

function sm_cf_headers($token){ return array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'); }

function sm_cf_global_headers($api_key,$email){ return array('X-Auth-Key'=>$api_key,'X-Auth-Email'=>$email,'Content-Type'=>'application/json'); }

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

function sm_cf_delete_video($account_id,$token,$video_uid,$global_api_key='',$global_email=''){
    $url="https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/{$video_uid}";

    // Use Global API Key + Email if provided, otherwise fall back to Bearer token
    if (!empty($global_api_key) && !empty($global_email)) {
        $headers = sm_cf_global_headers($global_api_key, $global_email);
    } else {
        $headers = sm_cf_headers($token);
    }

    $res=wp_remote_request($url,array('method'=>'DELETE','headers'=>$headers,'timeout'=>20));
    if (is_wp_error($res)) return $res;
    $code=wp_remote_retrieve_response_code($res);
    $body=wp_remote_retrieve_body($res);
    if ($code>=200&&$code<300) return true;
    return new WP_Error('cf_delete_failed',"Cloudflare delete failed with HTTP {$code}",array('code'=>$code,'body'=>$body,'video_uid'=>$video_uid));
}

function sm_diagnose_cf_error($http_code, $response_body){
    $json = json_decode($response_body, true);
    $cf_error_code = '';
    $cf_error_msg = '';

    if (isset($json['errors']) && is_array($json['errors']) && !empty($json['errors'])) {
        $cf_error_code = isset($json['errors'][0]['code']) ? $json['errors'][0]['code'] : '';
        $cf_error_msg = isset($json['errors'][0]['message']) ? $json['errors'][0]['message'] : '';
    }

    $diagnosis = array();

    switch ($http_code) {
        case 404:
        case '404':
            $diagnosis['issue'] = 'Video Not Found';
            $diagnosis['likely_cause'] = 'The video has already been deleted from Cloudflare, or the video UID is incorrect.';
            $diagnosis['action'] = 'This is normal if the video was already deleted manually or by another process. No action needed.';
            break;
        case 403:
        case '403':
            $diagnosis['issue'] = 'Permission Denied';
            $diagnosis['likely_cause'] = 'Your API token does not have permission to delete videos.';
            $diagnosis['action'] = 'Check your Cloudflare API token permissions. It needs "Stream:Edit" permission.';
            break;
        case 401:
        case '401':
            $diagnosis['issue'] = 'Authentication Failed';
            $diagnosis['likely_cause'] = 'Invalid or expired API token, or incorrect account ID.';
            $diagnosis['action'] = 'Verify your Cloudflare API token and Account ID in settings.';
            break;
        case 429:
        case '429':
            $diagnosis['issue'] = 'Rate Limited';
            $diagnosis['likely_cause'] = 'Too many API requests in a short time.';
            $diagnosis['action'] = 'Wait a few minutes and try again.';
            break;
        case 500:
        case '500':
        case 502:
        case '502':
        case 503:
        case '503':
            $diagnosis['issue'] = 'Cloudflare Server Error';
            $diagnosis['likely_cause'] = 'Temporary issue with Cloudflare Stream API.';
            $diagnosis['action'] = 'Wait a few minutes and try again. If it persists, check Cloudflare status page.';
            break;
        default:
            $diagnosis['issue'] = 'Unknown Error';
            $diagnosis['likely_cause'] = "HTTP {$http_code} response from Cloudflare.";
            $diagnosis['action'] = 'Check the response body for details.';
    }

    if ($cf_error_code) $diagnosis['cf_error_code'] = $cf_error_code;
    if ($cf_error_msg) $diagnosis['cf_error_message'] = $cf_error_msg;

    return $diagnosis;
}
