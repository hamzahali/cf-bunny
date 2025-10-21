<div class="wrap">
  <h1>Transfer Logs</h1>
  <?php
    global $wpdb;
    $table = $wpdb->prefix . SM_LOG_TABLE;
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 200");
    if (!$rows) { echo '<p>No logs yet.</p>'; }
    else {
        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Post</th><th>CF UID</th><th>Status</th><th>Message</th><th>CF Iframe</th><th>Bunny Iframe</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $post_link = $r->post_id ? ('<a href="'.admin_url('post.php?post='.$r->post_id.'&action=edit').'" target="_blank">'.esc_html(get_the_title($r->post_id)).'</a>') : '-';
            $cfi = $r->cf_iframe ? '<a href="'.esc_url($r->cf_iframe).'" target="_blank">Open</a>' : '-';
            $bfi = $r->bunny_iframe ? '<a href="'.esc_url($r->bunny_iframe).'" target="_blank">Open</a>' : '-';
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($r->created_at),
                $post_link,
                esc_html($r->cf_uid),
                esc_html($r->status),
                esc_html($r->message),
                $cfi,
                $bfi
            );
        }
        echo '</tbody></table>';
    }
  ?>
</div>
