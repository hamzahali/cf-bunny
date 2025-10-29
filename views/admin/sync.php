<?php
if (!defined('ABSPATH')) exit;

$sync_logs = sm_get_sync_logs(10);
$stream_keys = sm_get_all_stream_keys();
?>
<div class="wrap">
  <h1>Sync Recordings</h1>
  <p>Manually check Cloudflare for new recordings that weren't automatically imported via webhooks.</p>

  <div style="background: #f0f0f1; padding: 20px; border-left: 4px solid #2271b1; margin: 20px 0;">
    <h3 style="margin-top: 0;">How it works</h3>
    <ol style="margin-bottom: 0;">
      <li>Click <strong>Scan for New Recordings</strong> below</li>
      <li>System checks all <?php echo count($stream_keys); ?> stream keys in Cloudflare</li>
      <li>Compares with WordPress database to find new recordings</li>
      <li>Shows preview of what will be imported</li>
      <li>You select which recordings to import (or import all)</li>
      <li>Each recording gets metadata from its stream key</li>
    </ol>
  </div>

  <hr>

  <h2>Manual Sync</h2>

  <?php if (empty($stream_keys)): ?>
    <div style="padding: 40px; text-align: center; background: #fff; border: 1px solid #ddd;">
      <p style="font-size: 16px; color: #666;">No stream keys found.</p>
      <p>Create stream keys in <a href="<?php echo admin_url('admin.php?page=sm_registry'); ?>">Manage Stream Keys</a> first.</p>
    </div>
  <?php else: ?>
    <p>
      <button class="button button-primary button-large" id="sm-scan-recordings">
        <span class="dashicons dashicons-update-alt" style="margin-top: 3px;"></span> Scan for New Recordings
      </button>
      <span id="sm-scan-status" style="margin-left: 15px; font-size: 14px;"></span>
    </p>

    <div id="sm-scan-progress" style="display: none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ddd;">
      <h3>Scanning Cloudflare...</h3>
      <div style="background: #f0f0f1; height: 30px; border-radius: 4px; overflow: hidden; position: relative;">
        <div id="sm-scan-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
        <div id="sm-scan-progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: 600;">0%</div>
      </div>
      <p id="sm-scan-current" style="margin-top: 10px; color: #666;"></p>
    </div>

    <div id="sm-scan-results" style="display: none; margin: 20px 0;"></div>
  <?php endif; ?>

  <hr>

  <h2>Sync History</h2>

  <?php if (empty($sync_logs)): ?>
    <p>No sync history yet.</p>
  <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
      <thead>
        <tr>
          <th style="width: 15%;">Type</th>
          <th style="width: 20%;">Time</th>
          <th style="width: 15%;">Found</th>
          <th style="width: 15%;">Imported</th>
          <th style="width: 10%;">Status</th>
          <th style="width: 25%;">Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sync_logs as $log): ?>
          <tr>
            <td><?php echo ucfirst(esc_html($log->sync_type)); ?></td>
            <td>
              <?php echo human_time_diff(strtotime($log->sync_time), current_time('timestamp')) . ' ago'; ?>
              <br><small style="color: #666;"><?php echo date('M j, Y g:i a', strtotime($log->sync_time)); ?></small>
            </td>
            <td><strong><?php echo intval($log->recordings_found); ?></strong></td>
            <td><strong><?php echo intval($log->recordings_imported); ?></strong></td>
            <td>
              <?php if ($log->status === 'success'): ?>
                <span style="color: #00a32a;">✓ Success</span>
              <?php else: ?>
                <span style="color: #d63638;">✗ Error</span>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html($log->message); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
  var streamKeys = <?php echo json_encode($stream_keys); ?>;
  var scannedKeys = 0;
  var newRecordings = [];

  $('#sm-scan-recordings').on('click', function() {
    var btn = $(this);
    btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt" style="animation: rotation 1s infinite linear; margin-top: 3px;"></span> Scanning...');

    $('#sm-scan-status').text('');
    $('#sm-scan-results').hide().empty();
    $('#sm-scan-progress').show();
    $('#sm-scan-progress-bar').css('width', '0%');
    $('#sm-scan-progress-text').text('0%');

    scannedKeys = 0;
    newRecordings = [];

    scanNextKey();
  });

  function scanNextKey() {
    if (scannedKeys >= streamKeys.length) {
      // All done
      $('#sm-scan-progress').hide();
      showResults();
      $('#sm-scan-recordings').prop('disabled', false).html('<span class="dashicons dashicons-update-alt" style="margin-top: 3px;"></span> Scan for New Recordings');
      return;
    }

    var key = streamKeys[scannedKeys];
    var progress = Math.round((scannedKeys / streamKeys.length) * 100);

    $('#sm-scan-progress-bar').css('width', progress + '%');
    $('#sm-scan-progress-text').text(progress + '%');
    $('#sm-scan-current').text('Checking: ' + key.name + ' (' + (scannedKeys + 1) + ' of ' + streamKeys.length + ')');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_scan_stream_key',
      nonce: SM_AJAX.nonce,
      stream_key_id: key.id
    }, function(response) {
      if (response.success && response.data.new_recordings) {
        newRecordings = newRecordings.concat(response.data.new_recordings);
      }

      scannedKeys++;
      scanNextKey();
    }).fail(function() {
      scannedKeys++;
      scanNextKey();
    });
  }

  function showResults() {
    var $results = $('#sm-scan-results');

    if (newRecordings.length === 0) {
      $results.html('<div style="padding: 40px; text-align: center; background: #fff; border: 1px solid #ddd;"><h3 style="color: #00a32a;">✓ All synced!</h3><p style="font-size: 16px;">No new recordings found. Everything is up to date.</p></div>');
      $results.show();
      $('#sm-scan-status').html('<span style="color: #00a32a;">✓ Scan complete - 0 new recordings</span>');
      return;
    }

    var html = '<div style="background: #fff; border: 1px solid #ddd; padding: 20px;">';
    html += '<h3>✓ Found ' + newRecordings.length + ' new recording(s)</h3>';
    html += '<p>Select which recordings to import:</p>';
    html += '<table class="wp-list-table widefat fixed striped">';
    html += '<thead><tr>';
    html += '<th style="width: 5%;"><input type="checkbox" id="sm-select-all-recordings" checked></th>';
    html += '<th style="width: 25%;">Stream Key</th>';
    html += '<th style="width: 20%;">Video UID</th>';
    html += '<th style="width: 20%;">Created</th>';
    html += '<th style="width: 15%;">Duration</th>';
    html += '<th style="width: 15%;">Status</th>';
    html += '</tr></thead><tbody>';

    newRecordings.forEach(function(rec, index) {
      var duration = rec.duration ? formatDuration(rec.duration) : 'Unknown';
      var created = rec.created ? new Date(rec.created).toLocaleString() : 'Unknown';

      html += '<tr>';
      html += '<td><input type="checkbox" class="sm-recording-checkbox" data-index="' + index + '" checked></td>';
      html += '<td><strong>' + escapeHtml(rec.stream_key_name) + '</strong></td>';
      html += '<td><code>' + escapeHtml(rec.video_uid) + '</code></td>';
      html += '<td>' + created + '</td>';
      html += '<td>' + duration + '</td>';
      html += '<td><span style="color: #00a32a;">Ready</span></td>';
      html += '</tr>';
    });

    html += '</tbody></table>';
    html += '<p style="margin-top: 20px;">';
    html += '<button class="button button-primary button-large" id="sm-import-selected">Import Selected</button> ';
    html += '<button class="button button-large" id="sm-cancel-import">Cancel</button>';
    html += '<span id="sm-import-status" style="margin-left: 15px;"></span>';
    html += '</p>';
    html += '</div>';

    $results.html(html).show();
    $('#sm-scan-status').html('<span style="color: #00a32a;">✓ Scan complete - ' + newRecordings.length + ' new recording(s) found</span>');
  }

  $(document).on('change', '#sm-select-all-recordings', function() {
    $('.sm-recording-checkbox').prop('checked', $(this).is(':checked'));
  });

  $(document).on('click', '#sm-import-selected', function() {
    var selected = [];
    $('.sm-recording-checkbox:checked').each(function() {
      var index = $(this).data('index');
      selected.push(newRecordings[index]);
    });

    if (selected.length === 0) {
      alert('Please select at least one recording to import');
      return;
    }

    if (!confirm('Import ' + selected.length + ' recording(s)?')) {
      return;
    }

    var btn = $(this);
    btn.prop('disabled', true).text('Importing...');
    $('#sm-import-status').html('<span style="color: #666;">Importing ' + selected.length + ' recording(s)...</span>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_import_recordings',
      nonce: SM_AJAX.nonce,
      recordings: JSON.stringify(selected)
    }, function(response) {
      if (response.success) {
        $('#sm-import-status').html('<span style="color: #00a32a;">✓ Successfully imported ' + response.data.imported + ' recording(s)</span>');
        setTimeout(function() {
          location.reload();
        }, 2000);
      } else {
        $('#sm-import-status').html('<span style="color: #d63638;">✗ Error: ' + (response.data || 'Import failed') + '</span>');
        btn.prop('disabled', false).text('Import Selected');
      }
    }).fail(function() {
      $('#sm-import-status').html('<span style="color: #d63638;">✗ Request failed</span>');
      btn.prop('disabled', false).text('Import Selected');
    });
  });

  $(document).on('click', '#sm-cancel-import', function() {
    $('#sm-scan-results').fadeOut();
  });

  function formatDuration(seconds) {
    var hours = Math.floor(seconds / 3600);
    var minutes = Math.floor((seconds % 3600) / 60);
    var secs = Math.floor(seconds % 60);

    if (hours > 0) {
      return hours + 'h ' + minutes + 'm ' + secs + 's';
    } else if (minutes > 0) {
      return minutes + 'm ' + secs + 's';
    } else {
      return secs + 's';
    }
  }

  function escapeHtml(text) {
    var map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
  }
});
</script>

<style>
@keyframes rotation {
  from { transform: rotate(0deg); }
  to { transform: rotate(359deg); }
}
</style>
