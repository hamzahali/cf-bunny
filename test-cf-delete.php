<?php
/**
 * Standalone Cloudflare Video Deletion Test Script
 *
 * Usage: Access via browser: /wp-content/plugins/stream-manager/test-cf-delete.php?uid=VIDEO_UID
 * Or include in WordPress admin or WP-CLI
 */

// Load WordPress
$wp_load_path = dirname(dirname(dirname(__DIR__))) . '/wp-load.php';
if (file_exists($wp_load_path)) {
    require_once($wp_load_path);
} else {
    die('Error: Could not find WordPress. Make sure this file is in wp-content/plugins/stream-manager/');
}

// Security check
if (!current_user_can('manage_options')) {
    die('Error: You do not have permission to access this page.');
}

// Get video UID from query parameter
$cf_uid = isset($_GET['uid']) ? sanitize_text_field($_GET['uid']) : '';

if (empty($cf_uid)) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Test Cloudflare Video Deletion</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
            .card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 0; }
            h1 { color: #23282d; }
            input[type="text"] { width: 100%; padding: 8px; font-size: 14px; border: 1px solid #ddd; }
            .button { background: #2271b1; color: #fff; border: none; padding: 10px 20px; cursor: pointer; font-size: 14px; border-radius: 3px; }
            .button:hover { background: #135e96; }
            code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <h1>Test Cloudflare Video Deletion</h1>
        <div class="card">
            <h2>Enter Video UID</h2>
            <form method="get">
                <p><input type="text" name="uid" placeholder="Enter Cloudflare Video UID (e.g., 843ddaa1ce7a354ab08b5a9d2d241382)" required></p>
                <p><button type="submit" class="button">Test Delete</button></p>
            </form>
        </div>
        <div class="card">
            <h3>Usage</h3>
            <p>Enter a Cloudflare video UID to test deletion and see detailed error information.</p>
            <p><strong>Direct URL format:</strong></p>
            <p><code><?php echo esc_url(plugins_url('test-cf-delete.php', __FILE__)); ?>?uid=VIDEO_UID</code></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Run the test
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Result - Cloudflare Video Deletion</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; background: #f0f0f1; }
        .card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 0; }
        h1 { color: #23282d; }
        h2 { color: #23282d; font-size: 18px; margin-top: 0; }
        .success { background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 10px 0; }
        .error { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 10px 0; }
        .info { background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 10px 0; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 10px 0; }
        pre { background: #f4f4f4; border: 1px solid #ddd; padding: 15px; overflow-x: auto; border-radius: 4px; }
        code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-family: Consolas, Monaco, monospace; }
        table { width: 100%; border-collapse: collapse; }
        table th, table td { text-align: left; padding: 10px; border-bottom: 1px solid #ddd; }
        table th { background: #f9f9f9; font-weight: 600; }
        .button { display: inline-block; background: #2271b1; color: #fff; text-decoration: none; padding: 10px 20px; margin: 10px 5px 10px 0; border-radius: 3px; }
        .button:hover { background: #135e96; }
    </style>
</head>
<body>
    <h1>Cloudflare Video Deletion Test Result</h1>

    <div class="card">
        <h2>Test Parameters</h2>
        <table>
            <tr>
                <th style="width: 250px;">Video UID</th>
                <td><code><?php echo esc_html($cf_uid); ?></code></td>
            </tr>
            <tr>
                <th>Cloudflare Account ID</th>
                <td><code><?php echo esc_html(get_option('sm_cf_account_id','') ?: 'Not configured'); ?></code></td>
            </tr>
            <tr>
                <th>API Token</th>
                <td>
                    <?php
                    $token = get_option('sm_cf_api_token','');
                    if ($token) {
                        echo '<code>' . esc_html(substr($token, 0, 10)) . '••••••••••••••••</code>';
                    } else {
                        echo '<span style="color: red;">Not configured</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th>Test Time</th>
                <td><?php echo current_time('mysql'); ?></td>
            </tr>
        </table>
    </div>

    <?php
    // Perform the deletion test
    $acc = get_option('sm_cf_account_id','');
    $tok = get_option('sm_cf_api_token','');

    if (empty($acc) || empty($tok)) {
        echo '<div class="card"><div class="error"><strong>Configuration Error:</strong> Cloudflare API credentials are not configured. Please configure them in the plugin settings.</div></div>';
    } else {
        echo '<div class="card"><h2>Deletion Test Result</h2>';

        $result = sm_cf_delete_video($acc, $tok, $cf_uid);

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $http_code = isset($data['code']) ? $data['code'] : 'unknown';
            $response_body = isset($data['body']) ? $data['body'] : '';
            $error_message = $result->get_error_message();

            echo '<div class="error">';
            echo '<h3 style="margin-top: 0;">✗ Deletion Failed</h3>';
            echo '<p><strong>Error Message:</strong> ' . esc_html($error_message) . '</p>';
            echo '<p><strong>HTTP Status Code:</strong> ' . esc_html($http_code) . '</p>';
            echo '</div>';

            // Get diagnosis
            $diagnosis = sm_diagnose_cf_error($http_code, $response_body);

            echo '<div class="info">';
            echo '<h3 style="margin-top: 0;">Diagnosis & Recommendations</h3>';
            echo '<table>';
            echo '<tr><th style="width: 200px;">Issue</th><td>' . esc_html($diagnosis['issue']) . '</td></tr>';
            echo '<tr><th>Likely Cause</th><td>' . esc_html($diagnosis['likely_cause']) . '</td></tr>';
            echo '<tr><th>Recommended Action</th><td>' . esc_html($diagnosis['action']) . '</td></tr>';
            if (isset($diagnosis['cf_error_code'])) {
                echo '<tr><th>Cloudflare Error Code</th><td><code>' . esc_html($diagnosis['cf_error_code']) . '</code></td></tr>';
            }
            if (isset($diagnosis['cf_error_message'])) {
                echo '<tr><th>Cloudflare Error Message</th><td>' . esc_html($diagnosis['cf_error_message']) . '</td></tr>';
            }
            echo '</table>';
            echo '</div>';

            if (!empty($response_body)) {
                echo '<div>';
                echo '<h3>Full API Response</h3>';
                $json = json_decode($response_body, true);
                if ($json) {
                    echo '<pre>' . esc_html(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
                } else {
                    echo '<pre>' . esc_html($response_body) . '</pre>';
                }
                echo '</div>';
            }

            // Log this test
            sm_log('ERROR', 0, "Test deletion failed for {$cf_uid}: {$error_message} | Response: {$response_body}", $cf_uid);

        } elseif ($result === true) {
            echo '<div class="success">';
            echo '<h3 style="margin-top: 0;">✓ Success</h3>';
            echo '<p>Video <code>' . esc_html($cf_uid) . '</code> was successfully deleted from Cloudflare.</p>';
            echo '</div>';

            sm_log('INFO', 0, "Test deletion successful for {$cf_uid}", $cf_uid);
        } else {
            echo '<div class="warning">';
            echo '<h3 style="margin-top: 0;">⚠ Unknown Result</h3>';
            echo '<p>Unexpected result from deletion function.</p>';
            echo '</div>';
        }

        echo '</div>';
    }
    ?>

    <div class="card">
        <h2>Next Steps</h2>
        <a href="<?php echo admin_url('admin.php?page=stream-manager-logs'); ?>" class="button">View Logs</a>
        <a href="<?php echo admin_url('admin.php?page=stream-manager-test-delete'); ?>" class="button">Test Another Video</a>
        <a href="?uid=" class="button">New Test</a>
    </div>

    <div class="card">
        <h3>Common Error Codes</h3>
        <table>
            <tr><th style="width: 100px;">Code</th><th>Meaning</th></tr>
            <tr><td><code>404</code></td><td>Video not found (already deleted or invalid UID)</td></tr>
            <tr><td><code>403</code></td><td>Permission denied (API token lacks Stream:Edit permission)</td></tr>
            <tr><td><code>401</code></td><td>Authentication failed (invalid token or account ID)</td></tr>
            <tr><td><code>429</code></td><td>Rate limited (too many requests)</td></tr>
            <tr><td><code>500-503</code></td><td>Cloudflare server error (temporary issue)</td></tr>
        </table>
    </div>
</body>
</html>
