<?php
if (!defined('ABSPATH')) exit;

$stream_keys = sm_get_all_stream_keys();
?>
<div class="wrap">
  <h1>Manage Stream Keys</h1>
  <p>Create and manage RTMP stream keys for live streaming. Each stream key can be used for multiple recordings, and new recordings will automatically inherit the default metadata.</p>

  <hr>

  <h2>Create New Stream Key</h2>
  <table class="form-table">
    <tr>
      <th><label for="sm-reg-name">Display Name <span style="color:red;">*</span></label></th>
      <td><input type="text" id="sm-reg-name" class="regular-text" placeholder="e.g., Physics Class" required/></td>
    </tr>
    <tr>
      <th><label for="sm-reg-subject">Default Subject</label></th>
      <td><input type="text" id="sm-reg-subject" class="regular-text" placeholder="e.g., Physics"/></td>
    </tr>
    <tr>
      <th><label for="sm-reg-category">Default Category</label></th>
      <td><input type="text" id="sm-reg-category" class="regular-text" placeholder="e.g., Science"/></td>
    </tr>
    <tr>
      <th><label for="sm-reg-year">Default Year</label></th>
      <td><input type="text" id="sm-reg-year" class="regular-text" placeholder="e.g., 2025"/></td>
    </tr>
    <tr>
      <th><label for="sm-reg-batch">Default Batch</label></th>
      <td><input type="text" id="sm-reg-batch" class="regular-text" placeholder="e.g., Fall 2025"/></td>
    </tr>
  </table>

  <p>
    <button class="button button-primary" id="sm-create-stream-key">Create Stream Key</button>
    <span id="sm-create-key-output"></span>
  </p>

  <div id="sm-stream-key-details" style="display:none; margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;"></div>

  <hr style="margin: 40px 0;">

  <h2>Existing Stream Keys (<?php echo count($stream_keys); ?>)</h2>

  <?php if (empty($stream_keys)): ?>
    <p>No stream keys created yet. Create your first stream key above.</p>
  <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
      <thead>
        <tr>
          <th style="width: 18%;">Name</th>
          <th style="width: 12%;">Subject</th>
          <th style="width: 12%;">Category</th>
          <th style="width: 8%;">Year</th>
          <th style="width: 10%;">Recordings</th>
          <th style="width: 20%;">Live Stream Links</th>
          <th style="width: 20%;">Actions</th>
        </tr>
      </thead>
      <tbody id="sm-stream-keys-list">
        <?php
        $customer = trim(get_option('sm_cf_customer_subdomain',''));
        foreach ($stream_keys as $key):
          $cf_iframe = $customer ? ('https://'.$customer.'.cloudflarestream.com/'.$key->live_input_uid.'/iframe') : '';
          $universal_embed = '';
          $post_slug = '';

          if ($key->post_id) {
            $post = get_post($key->post_id);
            if ($post) {
              $post_slug = $post->post_name;
              $universal_embed = site_url('/?stream_embed=1&slug='.$post_slug);
            }
          }
        ?>
          <tr data-key-id="<?php echo esc_attr($key->id); ?>">
            <td>
              <strong><?php echo esc_html($key->name); ?></strong>
            </td>
            <td><?php echo esc_html($key->default_subject); ?></td>
            <td><?php echo esc_html($key->default_category); ?></td>
            <td><?php echo esc_html($key->default_year); ?></td>
            <td>
              <strong><?php echo number_format($key->recording_count); ?></strong>
              <?php if ($key->recording_count > 0): ?>
                <br><a href="<?php echo admin_url('admin.php?page=sm_dashboard&stream_key_id=' . $key->id); ?>">View Recordings</a>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($cf_iframe): ?>
                <a href="<?php echo esc_url($cf_iframe); ?>" target="_blank" class="button button-small">CF Live</a>
              <?php endif; ?>
              <?php if ($universal_embed): ?>
                <a href="<?php echo esc_url($universal_embed); ?>" target="_blank" class="button button-small">Universal</a>
              <?php endif; ?>
              <button class="button button-small sm-copy-live-links" data-cf="<?php echo esc_attr($cf_iframe); ?>" data-universal="<?php echo esc_attr($universal_embed); ?>">Copy Links</button>
            </td>
            <td>
              <button class="button button-small sm-view-key-details" data-key-id="<?php echo esc_attr($key->id); ?>">View Details</button>
              <button class="button button-small sm-edit-stream-key" data-key-id="<?php echo esc_attr($key->id); ?>">Edit</button>
              <button class="button button-small button-link-delete sm-delete-stream-key" data-key-id="<?php echo esc_attr($key->id); ?>" data-name="<?php echo esc_attr($key->name); ?>" data-count="<?php echo esc_attr($key->recording_count); ?>">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top: 15px; padding: 15px; background: #fff8e5; border-left: 4px solid #ffb900;">
      <h3 style="margin-top: 0;">⚠️ Webhook Configuration</h3>
      <p>For live stream detection to work, each stream key needs webhook events configured. New stream keys are automatically configured, but existing ones may need to be synced.</p>
      <p>
        <button class="button button-secondary" id="sm-sync-all-webhooks">
          🔄 Sync All Webhooks
        </button>
        <span id="sm-sync-webhooks-status" style="margin-left: 10px;"></span>
      </p>
      <p style="margin: 0; font-size: 12px; color: #666;">
        This will configure all stream keys to send live_input.connected events when streaming starts.
      </p>
    </div>
  <?php endif; ?>
</div>

<!-- Edit Stream Key Modal -->
<div id="sm-edit-key-modal" style="display:none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.4);">
  <div style="background-color: #fff; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 600px; max-width: 90%;">
    <h2>Edit Stream Key</h2>
    <input type="hidden" id="sm-edit-key-id">
    <table class="form-table">
      <tr>
        <th><label for="sm-edit-name">Display Name</label></th>
        <td><input type="text" id="sm-edit-name" class="regular-text"/></td>
      </tr>
      <tr>
        <th><label for="sm-edit-subject">Default Subject</label></th>
        <td><input type="text" id="sm-edit-subject" class="regular-text"/></td>
      </tr>
      <tr>
        <th><label for="sm-edit-category">Default Category</label></th>
        <td><input type="text" id="sm-edit-category" class="regular-text"/></td>
      </tr>
      <tr>
        <th><label for="sm-edit-year">Default Year</label></th>
        <td><input type="text" id="sm-edit-year" class="regular-text"/></td>
      </tr>
      <tr>
        <th><label for="sm-edit-batch">Default Batch</label></th>
        <td><input type="text" id="sm-edit-batch" class="regular-text"/></td>
      </tr>
    </table>
    <p>
      <button class="button button-primary" id="sm-save-stream-key">Save Changes</button>
      <button class="button" id="sm-cancel-edit">Cancel</button>
      <span id="sm-edit-output"></span>
    </p>
  </div>
</div>

<script>
jQuery(document).ready(function($) {
  // Store stream keys data
  var streamKeysData = <?php echo json_encode($stream_keys); ?>;

  // View Details
  $('.sm-view-key-details').on('click', function() {
    var keyId = $(this).data('key-id');
    var key = streamKeysData.find(k => k.id == keyId);
    if (!key) return;

    var html = '<h3>Stream Key Details: ' + key.name + '</h3>';
    html += '<p><strong>Live Input UID:</strong> <code>' + key.live_input_uid + '</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'' + key.live_input_uid + '\')">Copy</button></p>';
    html += '<p><strong>Stream Key:</strong> <code>' + key.stream_key + '</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'' + key.stream_key + '\')">Copy</button></p>';
    html += '<hr>';
    html += '<h4>RTMP Setup for OBS:</h4>';
    html += '<p><strong>Server URL:</strong> <code>rtmp://live.cloudflare.com/live</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'rtmp://live.cloudflare.com/live\')">Copy</button></p>';
    html += '<p><strong>Stream Key:</strong> <code>' + key.stream_key + '</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'' + key.stream_key + '\')">Copy</button></p>';
    html += '<hr>';
    html += '<h4>Default Metadata:</h4>';
    html += '<ul>';
    html += '<li><strong>Subject:</strong> ' + (key.default_subject || '<em>Not set</em>') + '</li>';
    html += '<li><strong>Category:</strong> ' + (key.default_category || '<em>Not set</em>') + '</li>';
    html += '<li><strong>Year:</strong> ' + (key.default_year || '<em>Not set</em>') + '</li>';
    html += '<li><strong>Batch:</strong> ' + (key.default_batch || '<em>Not set</em>') + '</li>';
    html += '</ul>';
    html += '<p><em>New recordings will automatically inherit this metadata.</em></p>';
    html += '<button class="button" onclick="jQuery(\'#sm-stream-key-details\').slideUp()">Close</button>';

    $('#sm-stream-key-details').html(html).slideDown();
    $('html, body').animate({ scrollTop: $('#sm-stream-key-details').offset().top - 50 }, 500);
  });

  // Edit
  $('.sm-edit-stream-key').on('click', function() {
    var keyId = $(this).data('key-id');
    var key = streamKeysData.find(k => k.id == keyId);
    if (!key) return;

    $('#sm-edit-key-id').val(key.id);
    $('#sm-edit-name').val(key.name);
    $('#sm-edit-subject').val(key.default_subject);
    $('#sm-edit-category').val(key.default_category);
    $('#sm-edit-year').val(key.default_year);
    $('#sm-edit-batch').val(key.default_batch);
    $('#sm-edit-key-modal').fadeIn();
  });

  $('#sm-cancel-edit').on('click', function() {
    $('#sm-edit-key-modal').fadeOut();
  });

  // Delete
  $('.sm-delete-stream-key').on('click', function() {
    var keyId = $(this).data('key-id');
    var name = $(this).data('name');
    var count = parseInt($(this).data('count'));

    if (count > 0) {
      alert('Cannot delete: ' + count + ' recordings exist for this stream key.\n\nYou must delete all recordings first before deleting the stream key.');
      return;
    }

    if (!confirm('Are you sure you want to delete the stream key "' + name + '"?\n\nThis action cannot be undone.')) {
      return;
    }

    var $btn = $(this);
    $btn.prop('disabled', true).text('Deleting...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_delete_stream_key',
      nonce: SM_AJAX.nonce,
      key_id: keyId
    }, function(response) {
      if (response.success) {
        alert('Stream key deleted successfully!');
        location.reload();
      } else {
        alert('Error: ' + (response.data || 'Failed to delete stream key'));
        $btn.prop('disabled', false).text('Delete');
      }
    });
  });

  // Copy Live Links
  $('.sm-copy-live-links').on('click', function() {
    var cfLink = $(this).data('cf');
    var universalLink = $(this).data('universal');

    var text = 'Cloudflare Live Iframe:\n' + cfLink + '\n\nUniversal Embed:\n' + universalLink;

    var $temp = $('<textarea>');
    $('body').append($temp);
    $temp.val(text).select();
    document.execCommand('copy');
    $temp.remove();

    alert('Live stream links copied to clipboard!');
  });
});
</script>
