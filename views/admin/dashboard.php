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
        $lib = get_option('sm_bunny_library_id','');
        $key = get_option('sm_bunny_api_key','');

        echo '<table class="widefat fixed striped"><thead><tr>';
        echo '<th>Title</th><th>Status</th><th>Category</th><th>Year</th><th>Batch</th><th>CF UID</th><th>GUID</th><th>Duration</th><th>Size</th><th>Created</th><th>Universal Embed</th><th>Retry Transfer</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        while($q->have_posts()){ $q->the_post();
            $pid = get_the_ID();
            $status_raw = get_post_meta($pid,'_sm_status',true);
            $category = get_post_meta($pid,'_sm_category',true);
            $year = get_post_meta($pid,'_sm_year',true);
            $batch = get_post_meta($pid,'_sm_batch',true);
            $cfv = get_post_meta($pid,'_sm_cf_video_uid',true);
            $cf_live_input = get_post_meta($pid,'_sm_cf_live_input_uid',true);
            $bg  = get_post_meta($pid,'_sm_bunny_guid',true);
            $transfer_done = get_post_meta($pid,'_sm_transfer_done',true);
            $slug = get_post_field('post_name',$pid);
            $embed = esc_url(site_url('/?stream_embed=1&slug='.$slug));

            // Determine display status
            $display_status = '-';
            $status_color = '';
            $show_retry = false;

            if ($bg && !$cfv && !$cf_live_input) {
                // Direct recorded upload (has bunny guid but no CF uid)
                $display_status = 'VOD';
                $status_color = 'color:green;';
            } elseif ($cfv && $bg) {
                // Has both CF video and Bunny guid - transfer successful
                $display_status = 'RECORDED LIVE';
                $status_color = 'color:green;';
            } elseif ($cfv && !$bg && $transfer_done) {
                // Has CF video but no bunny guid, transfer was attempted
                // Check if transfer is genuinely stuck (more than 15 minutes)
                $transfer_time = strtotime($transfer_done);
                $time_elapsed = time() - $transfer_time;

                if ($time_elapsed > 900) { // 15 minutes
                    $display_status = 'TRANSFER STUCK';
                    $status_color = 'color:red;font-weight:bold;';
                    $show_retry = true;
                } else {
                    $display_status = 'PROCESSING';
                    $status_color = 'color:orange;';
                }
            } elseif ($cfv && !$bg && !$transfer_done) {
                // Has CF video but no transfer attempted yet - webhook may have failed
                $display_status = 'TRANSFER NOT STARTED';
                $status_color = 'color:red;';
                $show_retry = true;
            } elseif ($cf_live_input && !$bg && !$cfv) {
                // Live input created but video not recorded yet (actively live)
                $display_status = 'LIVE';
                $status_color = 'color:blue;';
            } elseif ($status_raw === 'processing') {
                $display_status = 'PROCESSING';
                $status_color = 'color:orange;';
            } elseif ($status_raw) {
                $display_status = strtoupper($status_raw);
            }

            // Get video metadata from Bunny if available
            $duration_text = '-';
            $size_text = '-';
            if ($bg && $lib && $key) {
                $video_info = sm_bunny_get_video($lib, $key, $bg);
                if (!is_wp_error($video_info)) {
                    // Duration in seconds
                    if (isset($video_info['length']) && $video_info['length'] > 0) {
                        $seconds = intval($video_info['length']);
                        $hours = floor($seconds / 3600);
                        $minutes = floor(($seconds % 3600) / 60);
                        $secs = $seconds % 60;
                        if ($hours > 0) {
                            $duration_text = sprintf('%dh %dm %ds', $hours, $minutes, $secs);
                        } else {
                            $duration_text = sprintf('%dm %ds', $minutes, $secs);
                        }
                    }
                    // Size in bytes
                    if (isset($video_info['storageSize']) && $video_info['storageSize'] > 0) {
                        $bytes = intval($video_info['storageSize']);
                        if ($bytes >= 1073741824) {
                            $size_text = number_format($bytes / 1073741824, 2) . ' GB';
                        } else {
                            $size_text = number_format($bytes / 1048576, 2) . ' MB';
                        }
                    }
                }
            }

            echo '<tr data-post-id="'.esc_attr($pid).'">';
            echo '<td><strong>'.esc_html(get_the_title()).'</strong></td>';
            echo '<td style="'.esc_attr($status_color).'"><strong>'.esc_html($display_status).'</strong></td>';
            echo '<td>'.esc_html($category ? $category : '-').'</td>';
            echo '<td>'.esc_html($year ? $year : '-').'</td>';
            echo '<td>'.esc_html($batch ? $batch : '-').'</td>';
            echo '<td>'.esc_html($cfv ? $cfv : ($cf_live_input ? $cf_live_input : '-')).'</td>';
            echo '<td>'.esc_html($bg ? $bg : '-').'</td>';
            echo '<td>'.esc_html($duration_text).'</td>';
            echo '<td>'.esc_html($size_text).'</td>';
            echo '<td>'.esc_html(get_the_date()).'</td>';
            echo '<td><button class="button sm-copy-embed" data-slug="'.esc_attr($slug).'">📋 Copy Embed</button> <button class="button sm-preview-embed" data-slug="'.esc_attr($slug).'">👁️ Preview</button></td>';
            echo '<td>';
            if ($cfv && $show_retry) {
                echo '<button class="button button-primary sm-retry-transfer" data-post-id="'.esc_attr($pid).'" data-cf-uid="'.esc_attr($cfv).'">🔄 Retry Transfer</button>';
            } else {
                echo '-';
            }
            echo '</td>';
            echo '<td>';
            if ($cfv || $bg) {
                echo '<button class="button button-small sm-delete-stream" data-post-id="'.esc_attr($pid).'" data-cf-uid="'.esc_attr($cfv).'" data-bunny-guid="'.esc_attr($bg).'" style="color:red;">Delete</button>';
            }
            echo '</td>';
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
