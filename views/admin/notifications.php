<?php
if (!defined('ABSPATH')) exit;

$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$notifications = sm_get_notifications(array(
    'limit' => $per_page,
    'offset' => $offset
));

$unread_count = sm_get_unread_count();
global $wpdb;
$total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . SM_NOTIFICATIONS_TABLE);
$total_pages = ceil($total / $per_page);

// Notification type icons and colors
function sm_notification_icon($type) {
    $icons = array(
        'success' => '<span style="color: #00a32a;">✅</span>',
        'error' => '<span style="color: #d63638;">❌</span>',
        'warning' => '<span style="color: #dba617;">⚠️</span>',
        'info' => '<span style="color: #2271b1;">ℹ️</span>',
    );
    return isset($icons[$type]) ? $icons[$type] : $icons['info'];
}
?>
<div class="wrap">
  <h1>Notifications <?php if ($unread_count > 0): ?><span class="update-plugins count-<?php echo $unread_count; ?>"><span class="update-count"><?php echo number_format_i18n($unread_count); ?></span></span><?php endif; ?></h1>

  <p>Activity feed showing automatic imports, transfers, and system events.</p>

  <div style="margin: 20px 0;">
    <button class="button" id="sm-mark-all-read">Mark All as Read</button>
    <button class="button" id="sm-refresh-notifications">Refresh</button>
  </div>

  <?php if (empty($notifications)): ?>
    <div style="padding: 40px; text-align: center; background: #f0f0f1; border: 1px solid #ddd;">
      <p style="font-size: 16px; color: #666;">No notifications yet.</p>
      <p>When recordings are imported automatically, you'll see notifications here.</p>
    </div>
  <?php else: ?>
    <table class="wp-list-table widefat fixed striped">
      <thead>
        <tr>
          <th style="width: 5%;"></th>
          <th style="width: 50%;">Notification</th>
          <th style="width: 15%;">Type</th>
          <th style="width: 20%;">Time</th>
          <th style="width: 10%;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($notifications as $notification): ?>
          <tr class="<?php echo $notification->is_read ? '' : 'notification-unread'; ?>" data-notification-id="<?php echo $notification->id; ?>" style="<?php echo $notification->is_read ? '' : 'background-color: #f0f6fc;'; ?>">
            <td style="text-align: center;">
              <?php echo sm_notification_icon($notification->type); ?>
            </td>
            <td>
              <strong><?php echo esc_html($notification->title); ?></strong>
              <?php if (!empty($notification->message)): ?>
                <br><span style="color: #666;"><?php echo esc_html($notification->message); ?></span>
              <?php endif; ?>
              <?php if ($notification->post_id): ?>
                <br><a href="<?php echo admin_url('post.php?post=' . $notification->post_id . '&action=edit'); ?>">View Recording</a>
              <?php endif; ?>
            </td>
            <td>
              <span class="notification-type-<?php echo esc_attr($notification->type); ?>">
                <?php echo ucfirst($notification->type); ?>
              </span>
            </td>
            <td>
              <?php echo human_time_diff(strtotime($notification->created_at), current_time('timestamp')) . ' ago'; ?>
              <br><small style="color: #666;"><?php echo date('M j, Y g:i a', strtotime($notification->created_at)); ?></small>
            </td>
            <td>
              <?php if (!$notification->is_read): ?>
                <button class="button button-small sm-mark-read" data-id="<?php echo $notification->id; ?>">Mark Read</button>
              <?php endif; ?>
              <button class="button button-small button-link-delete sm-delete-notification" data-id="<?php echo $notification->id; ?>">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
      <div class="tablenav bottom">
        <div class="tablenav-pages">
          <span class="displaying-num"><?php echo number_format_i18n($total); ?> items</span>
          <span class="pagination-links">
            <?php if ($page > 1): ?>
              <a class="prev-page button" href="?page=sm_notifications&paged=<?php echo $page - 1; ?>">‹</a>
            <?php endif; ?>
            <span class="paging-input">
              <label for="current-page-selector" class="screen-reader-text">Current Page</label>
              Page <?php echo number_format_i18n($page); ?> of <?php echo number_format_i18n($total_pages); ?>
            </span>
            <?php if ($page < $total_pages): ?>
              <a class="next-page button" href="?page=sm_notifications&paged=<?php echo $page + 1; ?>">›</a>
            <?php endif; ?>
          </span>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.notification-unread {
  font-weight: 500;
}
</style>

<script>
jQuery(document).ready(function($) {
  // Mark as read
  $('.sm-mark-read').on('click', function() {
    var $btn = $(this);
    var id = $btn.data('id');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_mark_notification_read',
      nonce: SM_AJAX.nonce,
      notification_id: id
    }, function(response) {
      if (response.success) {
        $btn.closest('tr').removeClass('notification-unread').css('background-color', '');
        $btn.remove();
        location.reload();
      }
    });
  });

  // Mark all as read
  $('#sm-mark-all-read').on('click', function() {
    if (!confirm('Mark all notifications as read?')) return;

    var $btn = $(this);
    $btn.prop('disabled', true).text('Marking...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_mark_all_notifications_read',
      nonce: SM_AJAX.nonce
    }, function(response) {
      if (response.success) {
        location.reload();
      } else {
        alert('Error marking notifications as read');
        $btn.prop('disabled', false).text('Mark All as Read');
      }
    });
  });

  // Delete notification
  $('.sm-delete-notification').on('click', function() {
    if (!confirm('Delete this notification?')) return;

    var $btn = $(this);
    var id = $btn.data('id');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_delete_notification',
      nonce: SM_AJAX.nonce,
      notification_id: id
    }, function(response) {
      if (response.success) {
        $btn.closest('tr').fadeOut(function() { $(this).remove(); });
      }
    });
  });

  // Refresh
  $('#sm-refresh-notifications').on('click', function() {
    location.reload();
  });
});
</script>
