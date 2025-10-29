<div class="wrap">
  <h1>Transfer Logs</h1>
  <?php
    global $wpdb;
    $table = $wpdb->prefix . SM_LOG_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200");
    if (!$rows) { echo '<p>No logs yet.</p>'; }
    else {
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Post</th><th>CF UID</th><th>Status</th><th>Message</th><th>CF Iframe</th><th>Bunny Iframe</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $post_link = $r->post_id ? ('<a href="'.admin_url('post.php?post='.$r->post_id.'&action=edit').'" target="_blank">'.esc_html(get_the_title($r->post_id)).'</a>') : '-';
            $cfi = $r->cf_iframe ? '<a href="'.esc_url($r->cf_iframe).'" target="_blank">Open</a>' : '-';
            $bfi = $r->bunny_iframe ? '<a href="'.esc_url($r->bunny_iframe).'" target="_blank">Open</a>' : '-';
            $delete_btn = $r->cf_uid ? '<button class="button button-small sm-delete-video" data-cf-uid="'.esc_attr($r->cf_uid).'"><span class="dashicons dashicons-trash" style="font-size: 13px; width: 13px; height: 13px; margin-top: 3px;"></span> Delete</button>' : '-';
            printf('<tr data-log-id="%d"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $r->id,
                esc_html($r->created_at),
                $post_link,
                esc_html($r->cf_uid),
                esc_html($r->status),
                esc_html($r->message),
                $cfi,
                $bfi,
                $delete_btn
            );
        }
        echo '</tbody></table>';
    }
  ?>
</div>

<style>
  .sm-delete-video {
    font-size: 12px;
    height: 24px;
    line-height: 22px;
    padding: 0 8px;
  }
  .sm-delete-video .dashicons {
    vertical-align: middle;
  }
</style>

<script>
jQuery(document).ready(function($){
  $('.sm-delete-video').on('click', function(e){
    e.preventDefault();
    var btn = $(this);
    var cfUid = btn.data('cf-uid');

    if (!confirm('Are you sure you want to delete video ' + cfUid + ' from Cloudflare?')) {
      return;
    }

    btn.prop('disabled', true).text('Deleting...');

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: {
        action: 'sm_manual_delete_cf_video',
        nonce: '<?php echo wp_create_nonce('sm_ajax_nonce'); ?>',
        cf_uid: cfUid
      },
      success: function(response){
        if (response.success) {
          alert('✓ Video deleted successfully: ' + response.data.message);
          btn.text('Deleted').css('color', 'green');
          setTimeout(function(){ location.reload(); }, 1000);
        } else {
          var errorMsg = '✗ Deletion failed: ' + response.data.message;
          if (response.data.diagnosis) {
            errorMsg += '\n\nDiagnosis: ' + response.data.diagnosis.issue;
            errorMsg += '\nCause: ' + response.data.diagnosis.likely_cause;
            errorMsg += '\nAction: ' + response.data.diagnosis.action;
          }
          alert(errorMsg);
          btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="font-size: 13px; width: 13px; height: 13px; margin-top: 3px;"></span> Delete');
        }
      },
      error: function(xhr, status, error){
        alert('✗ Request failed: ' + error);
        btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="font-size: 13px; width: 13px; height: 13px; margin-top: 3px;"></span> Delete');
      }
    });
  });
});
</script>
