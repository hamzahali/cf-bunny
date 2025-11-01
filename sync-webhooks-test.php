<?php
/**
 * Temporary script to sync webhooks for all existing stream keys
 * Upload this file to your WordPress root directory and access via browser
 * DELETE THIS FILE after running!
 */

// Load WordPress
require_once('wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Access denied. You must be an administrator.');
}

echo '<html><head><title>Webhook Sync</title></head><body>';
echo '<h1>Stream Key Webhook Configuration</h1>';

// Get Cloudflare credentials
$cf_acc = get_option('sm_cf_account_id', '');
$cf_tok = get_option('sm_cf_api_token', '');

if (empty($cf_acc) || empty($cf_tok)) {
    echo '<p style="color:red;">ERROR: Cloudflare credentials not configured in WordPress settings.</p>';
    echo '</body></html>';
    exit;
}

// Get all stream keys
global $wpdb;
$table = $wpdb->prefix . 'sm_stream_registry';
$stream_keys = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

if (empty($stream_keys)) {
    echo '<p style="color:orange;">No stream keys found in registry.</p>';
    echo '</body></html>';
    exit;
}

echo '<p>Found <strong>' . count($stream_keys) . '</strong> stream keys. Configuring webhooks...</p>';
echo '<hr>';

$webhook_url = rest_url('stream/v1/cf-webhook');
echo '<p><strong>Webhook URL:</strong> ' . esc_html($webhook_url) . '</p>';
echo '<hr>';

$success = 0;
$errors = 0;

foreach ($stream_keys as $key) {
    echo '<div style="margin: 10px 0; padding: 10px; background: #f0f0f0; border-left: 3px solid #0073aa;">';
    echo '<strong>' . esc_html($key->name) . '</strong> (' . esc_html($key->live_input_uid) . ')';

    // Configure webhook
    $url = "https://api.cloudflare.com/client/v4/accounts/{$cf_acc}/stream/live_inputs/{$key->live_input_uid}";

    $body = json_encode(array(
        'webhook' => array(
            'url' => $webhook_url,
            'events' => array(
                'live_input.connected',
                'live_input.disconnected',
                'live_input.recording.ready',
                'live_input.recording.error'
            )
        )
    ));

    $response = wp_remote_request($url, array(
        'method' => 'PUT',
        'headers' => array(
            'Authorization' => 'Bearer ' . $cf_tok,
            'Content-Type' => 'application/json'
        ),
        'body' => $body,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        echo '<br><span style="color:red;">✗ ERROR: ' . esc_html($response->get_error_message()) . '</span>';
        $errors++;
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            echo '<br><span style="color:green;">✓ Webhook configured successfully!</span>';
            $success++;
        } else {
            echo '<br><span style="color:red;">✗ HTTP Error ' . $code . '</span>';
            echo '<br><pre>' . esc_html(wp_remote_retrieve_body($response)) . '</pre>';
            $errors++;
        }
    }

    echo '</div>';
}

echo '<hr>';
echo '<h2>Summary</h2>';
echo '<p><span style="color:green;">✓ Success: ' . $success . '</span></p>';
echo '<p><span style="color:red;">✗ Errors: ' . $errors . '</span></p>';

if ($success > 0) {
    echo '<hr>';
    echo '<div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; color: #155724;">';
    echo '<h3 style="margin-top:0;">✓ Webhooks Configured!</h3>';
    echo '<p><strong>Next Steps:</strong></p>';
    echo '<ol>';
    echo '<li>Start streaming in OBS with one of your stream keys</li>';
    echo '<li>Go to "All Streams" page in WordPress</li>';
    echo '<li>You should see a new post appear immediately with status "LIVE"</li>';
    echo '<li>When you stop streaming, the post will update to "RECORDED LIVE"</li>';
    echo '</ol>';
    echo '<p><strong>IMPORTANT:</strong> Delete this file (sync-webhooks-test.php) from your server for security!</p>';
    echo '</div>';
}

echo '</body></html>';
