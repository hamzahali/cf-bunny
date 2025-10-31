<?php
if (!defined('ABSPATH')) exit;

$stream_keys = sm_get_all_stream_keys();
$webhook_url = rest_url('stream/v1/cf-webhook');
$site_url = site_url();
?>
<div class="wrap">
  <h1>🔧 Webhook Diagnostics</h1>
  <p>This page helps diagnose webhook configuration issues for live stream detection.</p>

  <hr>

  <h2>1. Endpoint Accessibility Test</h2>
  <p>First, verify that your webhook endpoint is publicly accessible.</p>

  <div style="background: #f0f0f1; padding: 15px; margin: 15px 0;">
    <p><strong>Your Webhook URL:</strong></p>
    <code style="background: #fff; padding: 10px; display: block; margin: 10px 0;"><?php echo esc_html($webhook_url); ?></code>

    <p><strong>Your Site URL:</strong> <code><?php echo esc_html($site_url); ?></code></p>

    <p>
      <button class="button button-primary" id="sm-test-endpoint">Test Endpoint Accessibility</button>
      <span id="sm-endpoint-result" style="margin-left: 10px;"></span>
    </p>
  </div>

  <hr>

  <h2>2. Stream Keys Webhook Configuration</h2>
  <p>Check if each stream key has webhooks properly configured in Cloudflare.</p>

  <?php if (empty($stream_keys)): ?>
    <div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 15px; margin: 15px 0;">
      <p><strong>⚠️ No stream keys found.</strong></p>
      <p>Create a stream key first in <a href="<?php echo admin_url('admin.php?page=sm_registry'); ?>">Manage Stream Keys</a>.</p>
    </div>
  <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
      <thead>
        <tr>
          <th style="width: 25%;">Stream Key Name</th>
          <th style="width: 25%;">Live Input UID</th>
          <th style="width: 20%;">Webhook Status</th>
          <th style="width: 30%;">Actions</th>
        </tr>
      </thead>
      <tbody id="sm-webhook-diagnostics-list">
        <?php foreach ($stream_keys as $key): ?>
          <tr data-key-id="<?php echo esc_attr($key->id); ?>" data-live-input="<?php echo esc_attr($key->live_input_uid); ?>">
            <td><strong><?php echo esc_html($key->name); ?></strong></td>
            <td><code><?php echo esc_html($key->live_input_uid); ?></code></td>
            <td class="webhook-status">
              <span style="color: #999;">⏳ Checking...</span>
            </td>
            <td>
              <button class="button button-small sm-check-webhook" data-key-id="<?php echo esc_attr($key->id); ?>" data-live-input="<?php echo esc_attr($key->live_input_uid); ?>">
                🔍 Check Config
              </button>
              <button class="button button-small sm-send-test-webhook" data-live-input="<?php echo esc_attr($key->live_input_uid); ?>">
                📡 Send Test Event
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p style="margin-top: 15px;">
      <button class="button button-secondary" id="sm-check-all-webhooks">🔍 Check All Configurations</button>
    </p>
  <?php endif; ?>

  <hr>

  <h2>3. Recent Webhook Activity</h2>
  <p>View recent webhook requests received by WordPress.</p>

  <div style="background: #f0f0f1; padding: 15px;">
    <p><strong>Debug Log Location:</strong> <code>/wp-content/debug.log</code></p>
    <p>Look for lines containing: <code>==== Stream Manager Webhook Received ====</code></p>

    <?php if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG): ?>
      <p style="color: green;">✓ Debug logging is <strong>enabled</strong></p>
    <?php else: ?>
      <div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 10px; margin: 10px 0;">
        <p style="margin: 0;"><strong>⚠️ Debug logging is disabled!</strong></p>
        <p style="margin: 5px 0 0 0;">Add these lines to <code>wp-config.php</code> to enable logging:</p>
        <pre style="background: #fff; padding: 10px; margin: 10px 0;">define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
      </div>
    <?php endif; ?>
  </div>

  <hr>

  <h2>4. Common Issues & Solutions</h2>
  <div style="background: #f0f0f1; padding: 15px;">
    <h3 style="margin-top: 0;">Issue: No webhooks received</h3>
    <ul>
      <li>✓ Ensure webhook URL is publicly accessible (not localhost)</li>
      <li>✓ Check firewall/security plugins aren't blocking Cloudflare IPs</li>
      <li>✓ Verify webhooks are configured in Cloudflare (use "Check Config" buttons above)</li>
      <li>✓ Enable debug logging to see incoming requests</li>
    </ul>

    <h3>Issue: Webhook endpoint returns 401 Unauthorized</h3>
    <ul>
      <li>✓ Check webhook secret matches in Settings</li>
      <li>✓ Try enabling "Bypass webhook secret" temporarily for testing</li>
    </ul>

    <h3>Issue: Live posts not appearing</h3>
    <ul>
      <li>✓ Verify <code>live_input.connected</code> event is enabled</li>
      <li>✓ Check debug logs for "DETECTED LIVE STREAM START EVENT"</li>
      <li>✓ Ensure stream key registry has correct metadata</li>
    </ul>
  </div>
</div>

<script>
jQuery(document).ready(function($){

  // Test endpoint accessibility
  $('#sm-test-endpoint').on('click', function(){
    var btn = $(this);
    var $result = $('#sm-endpoint-result');

    btn.prop('disabled', true).text('Testing...');
    $result.html('<span style="color: #999;">⏳ Testing endpoint...</span>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_test_webhook_endpoint',
      nonce: SM_AJAX.nonce
    }, function(r){
      if (r.success) {
        $result.html('<span style="color: #00a32a;">✓ ' + r.data.message + '</span>');
      } else {
        $result.html('<span style="color: #d63638;">✗ ' + (r.data && r.data.message || 'Test failed') + '</span>');
      }
      btn.prop('disabled', false).text('Test Endpoint Accessibility');
    }).fail(function(){
      $result.html('<span style="color: #d63638;">✗ Request failed</span>');
      btn.prop('disabled', false).text('Test Endpoint Accessibility');
    });
  });

  // Check single webhook configuration
  $('.sm-check-webhook').on('click', function(){
    var btn = $(this);
    var keyId = btn.data('key-id');
    var liveInput = btn.data('live-input');
    var $row = btn.closest('tr');
    var $status = $row.find('.webhook-status');

    btn.prop('disabled', true).text('Checking...');
    $status.html('<span style="color: #999;">⏳ Checking...</span>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_check_webhook_config',
      nonce: SM_AJAX.nonce,
      live_input_uid: liveInput
    }, function(r){
      if (r.success) {
        var data = r.data;
        var html = '';

        if (data.webhook_configured) {
          html += '<span style="color: #00a32a;">✓ Configured</span><br>';
          html += '<small>URL: ' + data.webhook_url + '</small><br>';
          html += '<small>Events: ' + data.events.join(', ') + '</small>';
        } else {
          html += '<span style="color: #d63638;">✗ Not Configured</span><br>';
          html += '<small>No webhook found</small>';
        }

        $status.html(html);
      } else {
        $status.html('<span style="color: #d63638;">✗ ' + (r.data && r.data.message || 'Check failed') + '</span>');
      }
      btn.prop('disabled', false).text('🔍 Check Config');
    }).fail(function(){
      $status.html('<span style="color: #d63638;">✗ Request failed</span>');
      btn.prop('disabled', false).text('🔍 Check Config');
    });
  });

  // Check all webhooks
  $('#sm-check-all-webhooks').on('click', function(){
    $('.sm-check-webhook').click();
  });

  // Send test webhook
  $('.sm-send-test-webhook').on('click', function(){
    var btn = $(this);
    var liveInput = btn.data('live-input');

    if (!confirm('Send a test live_input.connected event to WordPress?\n\nThis simulates what happens when you start streaming.')) {
      return;
    }

    btn.prop('disabled', true).text('Sending...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_send_test_webhook',
      nonce: SM_AJAX.nonce,
      live_input_uid: liveInput
    }, function(r){
      if (r.success) {
        alert('✓ Test webhook sent!\n\n' + r.data.message + '\n\nCheck "All Streams" page to see if a post was created.');
      } else {
        alert('✗ Test failed:\n\n' + (r.data && r.data.message || 'Unknown error'));
      }
      btn.prop('disabled', false).text('📡 Send Test Event');
    }).fail(function(){
      alert('✗ Request failed');
      btn.prop('disabled', false).text('📡 Send Test Event');
    });
  });

  // Auto-check all webhooks on page load
  setTimeout(function(){
    $('#sm-check-all-webhooks').click();
  }, 500);
});
</script>
