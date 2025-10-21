<div class="wrap">
  <h1>All Streams</h1>
  <?php
    $q = new WP_Query(array('post_type'=>'stream_class','posts_per_page'=>50,'orderby'=>'date','order'=>'DESC'));
    if (!$q->have_posts()) { echo '<p>No streams yet.</p>'; }
    else {
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Title</th><th>Status</th><th>CF UID</th><th>Bunny GUID</th><th>Created</th><th>Actions</th><th>Universal Embed</th>';
        echo '</tr></thead><tbody>';
        while($q->have_posts()){ $q->the_post();
            $pid = get_the_ID();
            $status = get_post_meta($pid,'_sm_status',true); if (!$status) $status='-';
            $cfv = get_post_meta($pid,'_sm_cf_video_uid',true); if (!$cfv) $cfv = get_post_meta($pid,'_sm_cf_live_input_uid',true);
            $bg  = get_post_meta($pid,'_sm_bunny_guid',true);
            $slug = get_post_field('post_name',$pid);
            $embed = esc_url(site_url('/?stream_embed=1&slug='.$slug));
            echo '<tr>';
            echo '<td><strong>'.esc_html(get_the_title()).'</strong></td>';
            echo '<td>'.esc_html(strtoupper($status)).'</td>';
            echo '<td>'.esc_html($cfv ? $cfv : '-').'</td>';
            echo '<td>'.esc_html($bg ? $bg : '-').'</td>';
            echo '<td>'.esc_html(get_the_date()).'</td>';
            echo '<td><a class="button" target="_blank" href="'.$embed.'">Preview</a></td>';
            echo '<td><button class="button sm-copy-embed" data-slug="'.esc_attr($slug).'">📋 Copy Embed</button> <button class="button sm-preview-embed" data-slug="'.esc_attr($slug).'">👁️ Preview</button></td>';
            echo '</tr>';
        }
        wp_reset_postdata();
        echo '</tbody></table>';
    }
  ?>
  <p style="margin-top:15px;">
    <a class="button button-primary" href="<?php echo admin_url('admin.php?page=sm_add_live'); ?>">Add Live Video</a>
    <a class="button" href="<?php echo admin_url('admin.php?page=sm_add_recorded'); ?>">Add Recorded Video</a>
    <a class="button" href="<?php echo admin_url('admin.php?page=sm_logs'); ?>">Transfer Logs</a>
  </p>
</div>
