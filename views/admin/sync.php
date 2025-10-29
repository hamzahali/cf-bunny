<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
  <h1>Sync Recordings</h1>
  <p>Manual sync tool to check Cloudflare for new recordings that weren't automatically imported via webhook.</p>

  <div style="padding: 40px; text-align: center; background: #f0f0f1; border: 1px solid #ddd; margin: 20px 0;">
    <h2>Manual Sync Feature</h2>
    <p style="font-size: 16px; color: #666;">This feature will be fully implemented in Phase 2.</p>
    <p>For now, recordings are automatically imported via webhooks when they're ready in Cloudflare.</p>
    <p>Check the <strong>Notifications</strong> page to see automatically imported recordings.</p>
  </div>

  <hr>

  <h2>How Automatic Import Works</h2>
  <ol>
    <li>Create a stream key in <a href="<?php echo admin_url('admin.php?page=sm_registry'); ?>">Manage Stream Keys</a></li>
    <li>Stream using that key in OBS</li>
    <li>When recording ends, Cloudflare sends a webhook to WordPress</li>
    <li>WordPress automatically creates a new post with inherited metadata</li>
    <li>Transfer to Bunny starts automatically</li>
    <li>You'll see a notification in <a href="<?php echo admin_url('admin.php?page=sm_notifications'); ?>">Notifications</a></li>
  </ol>
</div>
