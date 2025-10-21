<div class="wrap">
  <h1>Test Cloudflare Video Deletion</h1>
  <p>Use this tool to manually test video deletion and see detailed error information from Cloudflare API.</p>

  <div class="card" style="max-width: 800px; margin-top: 20px;">
    <h2>Test Delete Video</h2>
    <form id="sm-test-delete-form" style="margin: 20px 0;">
      <table class="form-table">
        <tr>
          <th scope="row"><label for="cf_uid">Cloudflare Video UID</label></th>
          <td>
            <input type="text" id="cf_uid" name="cf_uid" class="regular-text" placeholder="e.g., 843ddaa1ce7a354ab08b5a9d2d241382" value="">
            <p class="description">Enter the Cloudflare video UID you want to delete.</p>
          </td>
        </tr>
      </table>
      <p class="submit">
        <button type="submit" class="button button-primary" id="sm-test-delete-btn">
          <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span> Test Delete Video
        </button>
        <span id="sm-test-delete-spinner" class="spinner" style="float: none; margin-left: 10px;"></span>
      </p>
    </form>
  </div>

  <div id="sm-test-result" style="margin-top: 30px; display: none;">
    <div class="card" style="max-width: 800px;">
      <h2>Test Result</h2>
      <div id="sm-test-result-content"></div>
    </div>
  </div>

  <div class="card" style="max-width: 800px; margin-top: 20px;">
    <h2>Configuration Check</h2>
    <table class="widefat striped">
      <tbody>
        <tr>
          <th style="width: 200px;">Cloudflare Account ID</th>
          <td><code><?php echo esc_html(get_option('sm_cf_account_id','') ? get_option('sm_cf_account_id','') : 'Not configured'); ?></code></td>
        </tr>
        <tr>
          <th>Cloudflare API Token</th>
          <td>
            <?php
            $token = get_option('sm_cf_api_token','');
            if ($token) {
              echo '<code>' . esc_html(substr($token, 0, 8)) . '••••••••••••••••</code> <span style="color: green;">✓ Configured</span>';
            } else {
              echo '<span style="color: red;">✗ Not configured</span>';
            }
            ?>
          </td>
        </tr>
        <tr>
          <th>Global API Key (for DELETE)</th>
          <td>
            <?php
            $global_key = get_option('sm_cf_global_api_key','');
            if ($global_key) {
              echo '<code>' . esc_html(substr($global_key, 0, 8)) . '••••••••••••••••</code> <span style="color: green;">✓ Configured</span>';
            } else {
              echo '<span style="color: orange;">✗ Not configured</span>';
            }
            ?>
          </td>
        </tr>
        <tr>
          <th>Email (for DELETE)</th>
          <td>
            <?php
            $global_email = get_option('sm_cf_global_email','');
            if ($global_email) {
              echo '<code>' . esc_html($global_email) . '</code> <span style="color: green;">✓ Configured</span>';
            } else {
              echo '<span style="color: orange;">✗ Not configured</span>';
            }
            ?>
          </td>
        </tr>
        <tr>
          <th>Auto-delete Enabled</th>
          <td><?php echo get_option('sm_cf_auto_delete', false) ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: orange;">✗ No</span>'; ?></td>
        </tr>
        <tr>
          <th>Delete Delay</th>
          <td><?php echo absint(get_option('sm_cf_delete_delay_min', 60)); ?> minutes</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<style>
  .sm-result-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
    padding: 15px;
    border-radius: 4px;
    margin: 10px 0;
  }
  .sm-result-error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 15px;
    border-radius: 4px;
    margin: 10px 0;
  }
  .sm-result-info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
    padding: 10px 15px;
    border-radius: 4px;
    margin: 5px 0;
    font-size: 13px;
  }
  .sm-code-block {
    background: #f4f4f4;
    border: 1px solid #ddd;
    padding: 10px;
    border-radius: 4px;
    overflow-x: auto;
    margin: 10px 0;
    font-family: monospace;
    font-size: 12px;
  }
</style>

<script>
jQuery(document).ready(function($){
  $('#sm-test-delete-form').on('submit', function(e){
    e.preventDefault();

    var cfUid = $('#cf_uid').val().trim();
    if (!cfUid) {
      alert('Please enter a Cloudflare Video UID');
      return;
    }

    $('#sm-test-delete-btn').prop('disabled', true);
    $('#sm-test-delete-spinner').addClass('is-active');
    $('#sm-test-result').hide();

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'sm_manual_delete_cf_video',
        nonce: '<?php echo wp_create_nonce('sm_ajax_nonce'); ?>',
        cf_uid: cfUid
      },
      success: function(response){
        $('#sm-test-delete-btn').prop('disabled', false);
        $('#sm-test-delete-spinner').removeClass('is-active');
        $('#sm-test-result').show();

        if (response.success) {
          var html = '<div class="sm-result-success">';
          html += '<h3 style="margin-top: 0;">✓ Success</h3>';
          html += '<p><strong>Message:</strong> ' + response.data.message + '</p>';
          html += '<p><strong>Video UID:</strong> <code>' + response.data.cf_uid + '</code></p>';
          html += '</div>';
          $('#sm-test-result-content').html(html);
        } else {
          var html = '<div class="sm-result-error">';
          html += '<h3 style="margin-top: 0;">✗ Deletion Failed</h3>';
          html += '<p><strong>Error:</strong> ' + response.data.message + '</p>';
          html += '<p><strong>HTTP Code:</strong> ' + response.data.http_code + '</p>';
          html += '<p><strong>Video UID:</strong> <code>' + response.data.cf_uid + '</code></p>';
          html += '</div>';

          if (response.data.diagnosis) {
            html += '<div class="sm-result-info">';
            html += '<h4 style="margin-top: 0;">Diagnosis</h4>';
            html += '<p><strong>Issue:</strong> ' + response.data.diagnosis.issue + '</p>';
            html += '<p><strong>Likely Cause:</strong> ' + response.data.diagnosis.likely_cause + '</p>';
            html += '<p><strong>Recommended Action:</strong> ' + response.data.diagnosis.action + '</p>';
            if (response.data.diagnosis.cf_error_code) {
              html += '<p><strong>Cloudflare Error Code:</strong> ' + response.data.diagnosis.cf_error_code + '</p>';
            }
            if (response.data.diagnosis.cf_error_message) {
              html += '<p><strong>Cloudflare Error Message:</strong> ' + response.data.diagnosis.cf_error_message + '</p>';
            }
            html += '</div>';
          }

          if (response.data.response_body) {
            html += '<div>';
            html += '<h4>Full Cloudflare API Response:</h4>';
            html += '<div class="sm-code-block">' + response.data.response_body + '</div>';
            html += '</div>';
          }

          $('#sm-test-result-content').html(html);
        }
      },
      error: function(xhr, status, error){
        $('#sm-test-delete-btn').prop('disabled', false);
        $('#sm-test-delete-spinner').removeClass('is-active');
        $('#sm-test-result').show();
        $('#sm-test-result-content').html(
          '<div class="sm-result-error"><h3 style="margin-top: 0;">✗ Request Failed</h3><p>AJAX error: ' + error + '</p></div>'
        );
      }
    });
  });
});
</script>
