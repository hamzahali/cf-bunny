<?php
if (!defined('ABSPATH')) exit;

$stream_keys = sm_get_all_stream_keys();
$cf_acc = get_option('sm_cf_account_id','');
$cf_tok = get_option('sm_cf_api_token','');
$webhook_url = rest_url('stream/v1/cf-webhook');
?>
<div class="wrap">
  <h1>🧪 Webhook Configuration Test Lab</h1>
  <p>Detailed testing of webhook configuration API calls to identify exactly what's failing.</p>

  <div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 15px; margin: 15px 0;">
    <p><strong>⚠️ This is a diagnostic tool.</strong> It shows raw API requests/responses to help debug webhook configuration issues.</p>
  </div>

  <hr>

  <h2>Configuration Details</h2>
  <div style="background: #f0f0f1; padding: 15px;">
    <table class="form-table">
      <tr>
        <th>Cloudflare Account ID:</th>
        <td><code><?php echo esc_html($cf_acc ? $cf_acc : 'NOT SET'); ?></code></td>
      </tr>
      <tr>
        <th>API Token:</th>
        <td><code><?php echo esc_html($cf_tok ? substr($cf_tok, 0, 10) . '...' : 'NOT SET'); ?></code></td>
      </tr>
      <tr>
        <th>Expected Webhook URL:</th>
        <td><code><?php echo esc_html($webhook_url); ?></code></td>
      </tr>
      <tr>
        <th>Expected Events:</th>
        <td>
          <code>live_input.connected</code><br>
          <code>live_input.disconnected</code><br>
          <code>live_input.recording.ready</code><br>
          <code>live_input.recording.error</code>
        </td>
      </tr>
    </table>
  </div>

  <?php if (empty($cf_acc) || empty($cf_tok)): ?>
    <div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 15px 0;">
      <p><strong>❌ Cloudflare credentials not configured!</strong></p>
      <p>Configure your Account ID and API Token in <a href="<?php echo admin_url('admin.php?page=sm_settings'); ?>">Settings</a> first.</p>
    </div>
  <?php elseif (empty($stream_keys)): ?>
    <div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 15px; margin: 15px 0;">
      <p><strong>⚠️ No stream keys found.</strong></p>
      <p>Create a stream key in <a href="<?php echo admin_url('admin.php?page=sm_registry'); ?>">Manage Stream Keys</a> first.</p>
    </div>
  <?php else: ?>

    <hr>

    <h2>Select Stream Key to Test</h2>
    <select id="sm-test-stream-key" style="min-width: 300px; padding: 8px;">
      <option value="">-- Select a stream key --</option>
      <?php foreach ($stream_keys as $key): ?>
        <option value="<?php echo esc_attr($key->live_input_uid); ?>"
                data-name="<?php echo esc_attr($key->name); ?>"
                data-id="<?php echo esc_attr($key->id); ?>">
          <?php echo esc_html($key->name); ?> (<?php echo esc_html($key->live_input_uid); ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <div id="sm-test-results" style="display:none; margin-top: 30px;">

      <hr>

      <h2>Test 1: SET Webhook Configuration</h2>
      <p>This will attempt to configure webhooks on the selected stream key in Cloudflare.</p>
      <p>
        <button class="button button-primary" id="sm-test-set-webhook">🔧 Configure Webhook (SET)</button>
      </p>

      <div id="sm-set-webhook-result" style="margin-top: 15px;"></div>

      <hr>

      <h2>Test 2: GET Current Webhook Configuration</h2>
      <p>This will read the current webhook configuration from Cloudflare to verify what's actually set.</p>
      <p>
        <button class="button button-secondary" id="sm-test-get-webhook">🔍 Read Current Config (GET)</button>
      </p>

      <div id="sm-get-webhook-result" style="margin-top: 15px;"></div>

      <hr>

      <h2>Test 3: Manual Verification</h2>
      <div style="background: #e7f3ff; border-left: 4px solid #2271b1; padding: 15px;">
        <p><strong>Verify in Cloudflare Dashboard:</strong></p>
        <ol>
          <li>Log into Cloudflare Dashboard</li>
          <li>Go to <strong>Stream → Live Inputs</strong></li>
          <li>Click on stream key: <strong id="sm-selected-key-name"></strong></li>
          <li>Look for <strong>"Webhooks"</strong> section</li>
          <li>Check if webhook URL and events are listed</li>
        </ol>
        <p><strong>Live Input UID:</strong> <code id="sm-selected-live-input"></code>
          <button class="button button-small" id="sm-copy-uid">📋 Copy</button>
        </p>
      </div>

    </div>

  <?php endif; ?>
</div>

<style>
.sm-api-details {
  background: #f9f9f9;
  border: 1px solid #ddd;
  padding: 15px;
  margin: 15px 0;
  font-family: monospace;
  font-size: 12px;
}

.sm-api-details h4 {
  margin-top: 0;
  color: #2271b1;
}

.sm-success {
  background: #d4edda;
  border-left: 4px solid #28a745;
  padding: 15px;
  margin: 15px 0;
}

.sm-error {
  background: #f8d7da;
  border-left: 4px solid #dc3545;
  padding: 15px;
  margin: 15px 0;
}

.sm-warning {
  background: #fff3cd;
  border-left: 4px solid #ffc107;
  padding: 15px;
  margin: 15px 0;
}

.sm-code-block {
  background: #2d2d2d;
  color: #f8f8f2;
  padding: 15px;
  overflow-x: auto;
  border-radius: 4px;
  margin: 10px 0;
}

.sm-code-block pre {
  margin: 0;
  color: #f8f8f2;
}
</style>

<script>
jQuery(document).ready(function($){

  // Show test section when stream key is selected
  $('#sm-test-stream-key').on('change', function(){
    var uid = $(this).val();
    var name = $(this).find('option:selected').data('name');

    if (uid) {
      $('#sm-test-results').slideDown();
      $('#sm-selected-key-name').text(name);
      $('#sm-selected-live-input').text(uid);

      // Clear previous results
      $('#sm-set-webhook-result').empty();
      $('#sm-get-webhook-result').empty();
    } else {
      $('#sm-test-results').slideUp();
    }
  });

  // Copy UID to clipboard
  $('#sm-copy-uid').on('click', function(){
    var uid = $('#sm-selected-live-input').text();
    navigator.clipboard.writeText(uid).then(function(){
      alert('UID copied to clipboard!');
    });
  });

  // Test SET webhook
  $('#sm-test-set-webhook').on('click', function(){
    var btn = $(this);
    var uid = $('#sm-test-stream-key').val();
    var $result = $('#sm-set-webhook-result');

    if (!uid) {
      alert('Please select a stream key first!');
      return;
    }

    btn.prop('disabled', true).text('Testing SET...');
    $result.html('<div class="sm-api-details">⏳ Sending SET request to Cloudflare...</div>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_test_set_webhook',
      nonce: SM_AJAX.nonce,
      live_input_uid: uid
    }, function(r){
      var html = '';

      if (r.success) {
        var d = r.data;

        html += '<div class="sm-success">';
        html += '<h3 style="margin-top:0;">✅ API Call Successful</h3>';
        html += '<p><strong>HTTP Status:</strong> ' + d.http_code + '</p>';
        html += '<p><strong>Message:</strong> ' + d.message + '</p>';
        html += '</div>';

        html += '<div class="sm-api-details">';
        html += '<h4>📤 Request Details</h4>';
        html += '<p><strong>Method:</strong> PUT</p>';
        html += '<p><strong>URL:</strong> <code>' + d.request_url + '</code></p>';
        html += '<p><strong>Headers:</strong></p>';
        html += '<div class="sm-code-block"><pre>' + d.request_headers + '</pre></div>';
        html += '<p><strong>Body:</strong></p>';
        html += '<div class="sm-code-block"><pre>' + d.request_body + '</pre></div>';
        html += '</div>';

        html += '<div class="sm-api-details">';
        html += '<h4>📥 Response Details</h4>';
        html += '<p><strong>Status Code:</strong> ' + d.http_code + '</p>';
        html += '<p><strong>Body:</strong></p>';
        html += '<div class="sm-code-block"><pre>' + d.response_body + '</pre></div>';
        html += '</div>';

        if (d.webhook_set) {
          html += '<div class="sm-success">';
          html += '<h3>✅ Webhook Configuration Confirmed</h3>';
          html += '<p>The API returned success. Webhook should be configured.</p>';
          html += '<p><strong>Next step:</strong> Click "Read Current Config (GET)" to verify.</p>';
          html += '</div>';
        }

      } else {
        html += '<div class="sm-error">';
        html += '<h3 style="margin-top:0;">❌ API Call Failed</h3>';
        html += '<p><strong>Error:</strong> ' + (r.data && r.data.message || 'Unknown error') + '</p>';
        html += '</div>';

        if (r.data) {
          html += '<div class="sm-api-details">';
          html += '<h4>📤 Request Details</h4>';
          if (r.data.request_url) html += '<p><strong>URL:</strong> <code>' + r.data.request_url + '</code></p>';
          if (r.data.request_body) {
            html += '<p><strong>Body:</strong></p>';
            html += '<div class="sm-code-block"><pre>' + r.data.request_body + '</pre></div>';
          }
          html += '</div>';

          html += '<div class="sm-api-details">';
          html += '<h4>📥 Response Details</h4>';
          if (r.data.http_code) html += '<p><strong>HTTP Code:</strong> ' + r.data.http_code + '</p>';
          if (r.data.response_body) {
            html += '<p><strong>Body:</strong></p>';
            html += '<div class="sm-code-block"><pre>' + r.data.response_body + '</pre></div>';
          }
          html += '</div>';
        }
      }

      $result.html(html);
      btn.prop('disabled', false).text('🔧 Configure Webhook (SET)');

    }).fail(function(xhr, status, error){
      $result.html('<div class="sm-error"><h3>❌ Request Failed</h3><p>' + error + '</p></div>');
      btn.prop('disabled', false).text('🔧 Configure Webhook (SET)');
    });
  });

  // Test GET webhook
  $('#sm-test-get-webhook').on('click', function(){
    var btn = $(this);
    var uid = $('#sm-test-stream-key').val();
    var $result = $('#sm-get-webhook-result');

    if (!uid) {
      alert('Please select a stream key first!');
      return;
    }

    btn.prop('disabled', true).text('Testing GET...');
    $result.html('<div class="sm-api-details">⏳ Reading configuration from Cloudflare...</div>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_test_get_webhook',
      nonce: SM_AJAX.nonce,
      live_input_uid: uid
    }, function(r){
      var html = '';

      if (r.success) {
        var d = r.data;

        html += '<div class="sm-success">';
        html += '<h3 style="margin-top:0;">✅ API Call Successful</h3>';
        html += '<p><strong>HTTP Status:</strong> ' + d.http_code + '</p>';
        html += '</div>';

        html += '<div class="sm-api-details">';
        html += '<h4>📤 Request Details</h4>';
        html += '<p><strong>Method:</strong> GET</p>';
        html += '<p><strong>URL:</strong> <code>' + d.request_url + '</code></p>';
        html += '</div>';

        html += '<div class="sm-api-details">';
        html += '<h4>📥 Full Response</h4>';
        html += '<div class="sm-code-block"><pre>' + d.response_body + '</pre></div>';
        html += '</div>';

        if (d.webhook_configured) {
          html += '<div class="sm-success">';
          html += '<h3>✅ Webhook IS Configured</h3>';
          html += '<p><strong>Webhook URL:</strong> <code>' + d.webhook_url + '</code></p>';
          html += '<p><strong>Events:</strong> ' + d.events.join(', ') + '</p>';

          if (d.url_matches) {
            html += '<p style="color:green;">✓ URL matches expected</p>';
          } else {
            html += '<p style="color:red;">✗ URL does NOT match expected!</p>';
            html += '<p><strong>Expected:</strong> <code>' + d.expected_url + '</code></p>';
            html += '<p><strong>Actual:</strong> <code>' + d.webhook_url + '</code></p>';
          }

          if (d.has_connected_event) {
            html += '<p style="color:green;">✓ Has live_input.connected event</p>';
          } else {
            html += '<p style="color:red;">✗ Missing live_input.connected event!</p>';
          }

          html += '</div>';
        } else {
          html += '<div class="sm-warning">';
          html += '<h3>⚠️ Webhook NOT Configured</h3>';
          html += '<p>The live input exists but has no webhook configuration.</p>';
          html += '<p><strong>Solution:</strong> Click "Configure Webhook (SET)" button above.</p>';
          html += '</div>';
        }

      } else {
        html += '<div class="sm-error">';
        html += '<h3 style="margin-top:0;">❌ API Call Failed</h3>';
        html += '<p><strong>Error:</strong> ' + (r.data && r.data.message || 'Unknown error') + '</p>';
        html += '</div>';

        if (r.data && r.data.response_body) {
          html += '<div class="sm-api-details">';
          html += '<h4>📥 Response</h4>';
          html += '<div class="sm-code-block"><pre>' + r.data.response_body + '</pre></div>';
          html += '</div>';
        }
      }

      $result.html(html);
      btn.prop('disabled', false).text('🔍 Read Current Config (GET)');

    }).fail(function(xhr, status, error){
      $result.html('<div class="sm-error"><h3>❌ Request Failed</h3><p>' + error + '</p></div>');
      btn.prop('disabled', false).text('🔍 Read Current Config (GET)');
    });
  });

});
</script>
