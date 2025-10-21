<div class="wrap">
  <h1>All Streams</h1>
  <?php
    // Get filter values from GET parameters
    $filter_category = isset($_GET['filter_category']) ? sanitize_text_field($_GET['filter_category']) : '';
    $filter_year = isset($_GET['filter_year']) ? sanitize_text_field($_GET['filter_year']) : '';
    $filter_batch = isset($_GET['filter_batch']) ? sanitize_text_field($_GET['filter_batch']) : '';

    // Get all unique values for filters
    global $wpdb;
    $categories = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sm_category' AND meta_value != '' ORDER BY meta_value");
    $years = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sm_year' AND meta_value != '' ORDER BY meta_value DESC");
    $batches = $wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sm_batch' AND meta_value != '' ORDER BY meta_value");
  ?>

  <form method="get" style="background:#f9f9f9;padding:15px;margin-bottom:15px;border:1px solid #ddd;">
    <input type="hidden" name="page" value="sm_dashboard" />
    <div style="display:flex;gap:15px;align-items:flex-end;">
      <div>
        <label for="filter_category" style="display:block;margin-bottom:5px;font-weight:600;">Category</label>
        <select name="filter_category" id="filter_category" style="min-width:150px;">
          <option value="">All Categories</option>
          <?php foreach($categories as $cat): ?>
            <option value="<?php echo esc_attr($cat); ?>" <?php selected($filter_category, $cat); ?>><?php echo esc_html($cat); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filter_year" style="display:block;margin-bottom:5px;font-weight:600;">Year</label>
        <select name="filter_year" id="filter_year" style="min-width:150px;">
          <option value="">All Years</option>
          <?php foreach($years as $year): ?>
            <option value="<?php echo esc_attr($year); ?>" <?php selected($filter_year, $year); ?>><?php echo esc_html($year); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="filter_batch" style="display:block;margin-bottom:5px;font-weight:600;">Batch</label>
        <select name="filter_batch" id="filter_batch" style="min-width:150px;">
          <option value="">All Batches</option>
          <?php foreach($batches as $batch): ?>
            <option value="<?php echo esc_attr($batch); ?>" <?php selected($filter_batch, $batch); ?>><?php echo esc_html($batch); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <button type="submit" class="button button-primary">Filter</button>
        <a href="<?php echo admin_url('admin.php?page=sm_dashboard'); ?>" class="button">Reset</a>
      </div>
    </div>
  </form>

  <?php
    // Build query args
    $args = array('post_type'=>'stream_class','posts_per_page'=>50,'orderby'=>'date','order'=>'DESC');

    // Add meta query for filters
    $meta_query = array('relation' => 'AND');
    if ($filter_category) {
      $meta_query[] = array('key' => '_sm_category', 'value' => $filter_category, 'compare' => '=');
    }
    if ($filter_year) {
      $meta_query[] = array('key' => '_sm_year', 'value' => $filter_year, 'compare' => '=');
    }
    if ($filter_batch) {
      $meta_query[] = array('key' => '_sm_batch', 'value' => $filter_batch, 'compare' => '=');
    }
    if (count($meta_query) > 1) {
      $args['meta_query'] = $meta_query;
    }

    $q = new WP_Query($args);
    if (!$q->have_posts()) { echo '<p>No streams yet.</p>'; }
    else {
        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Title</th><th>Status</th><th>Category</th><th>Year</th><th>Batch</th><th>CF UID</th><th>Bunny GUID</th><th>Created</th><th>Universal Embed</th>';
        echo '</tr></thead><tbody>';
        while($q->have_posts()){ $q->the_post();
            $pid = get_the_ID();
            $status = get_post_meta($pid,'_sm_status',true); if (!$status) $status='-';
            $category = get_post_meta($pid,'_sm_category',true);
            $year = get_post_meta($pid,'_sm_year',true);
            $batch = get_post_meta($pid,'_sm_batch',true);
            $cfv = get_post_meta($pid,'_sm_cf_video_uid',true); if (!$cfv) $cfv = get_post_meta($pid,'_sm_cf_live_input_uid',true);
            $bg  = get_post_meta($pid,'_sm_bunny_guid',true);
            $slug = get_post_field('post_name',$pid);
            $embed = esc_url(site_url('/?stream_embed=1&slug='.$slug));
            echo '<tr>';
            echo '<td><strong>'.esc_html(get_the_title()).'</strong></td>';
            echo '<td>'.esc_html(strtoupper($status)).'</td>';
            echo '<td>'.esc_html($category ? $category : '-').'</td>';
            echo '<td>'.esc_html($year ? $year : '-').'</td>';
            echo '<td>'.esc_html($batch ? $batch : '-').'</td>';
            echo '<td>'.esc_html($cfv ? $cfv : '-').'</td>';
            echo '<td>'.esc_html($bg ? $bg : '-').'</td>';
            echo '<td>'.esc_html(get_the_date()).'</td>';
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
