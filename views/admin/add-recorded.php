<div class="wrap">
  <h1>Add Recorded Video (Direct Upload)</h1>
  <p>Upload video files directly to CDN. Supports: MP4, MOV, AVI, MKV, WebM, and more.</p>

  <table class="form-table">
    <tr>
      <th>Title <span style="color:red;">*</span></th>
      <td><input type="text" id="sm-vod-title" class="regular-text" placeholder="Video title" required/></td>
    </tr>
    <tr>
      <th>Subject</th>
      <td><input type="text" id="sm-vod-subject" class="regular-text"/></td>
    </tr>
    <tr>
      <th>Category</th>
      <td><input type="text" id="sm-vod-category" class="regular-text"/></td>
    </tr>
    <tr>
      <th>Year</th>
      <td><input type="text" id="sm-vod-year" class="regular-text"/></td>
    </tr>
    <tr>
      <th>Batch</th>
      <td><input type="text" id="sm-vod-batch" class="regular-text"/></td>
    </tr>
    <tr>
      <th>Video File</th>
      <td>
        <input type="file" id="sm-video-file" accept=".mp4,.m4v,.mov,.avi,.mkv,.webm,.flv,.wmv,.mpg,.mpeg,.mxf,.ts,.vob,.3gp,.m4p,.amv,.ogg,.wav,.mp3" style="display:none;"/>
        <button class="button" id="sm-select-video-btn">📁 Select Video File</button>
        <div id="sm-file-info" style="margin-top: 10px; display: none;">
          <p style="margin: 5px 0;">
            <strong>Selected:</strong> <span id="sm-file-name"></span><br>
            <strong>Size:</strong> <span id="sm-file-size"></span><br>
            <strong>Type:</strong> <span id="sm-file-type"></span>
          </p>
          <button class="button button-small" id="sm-change-file-btn">Change File</button>
        </div>
      </td>
    </tr>
  </table>

  <div id="sm-upload-section" style="display:none; margin: 20px 0;">
    <button class="button button-primary button-large" id="sm-start-upload-btn">🚀 Upload to CDN</button>
  </div>

  <div id="sm-upload-progress" style="display:none; margin: 20px 0; padding: 20px; background: #f0f0f1; border-left: 4px solid #2271b1;">
    <h3 style="margin-top: 0;" id="sm-progress-title">⏫ Uploading...</h3>

    <div id="sm-progress-bar-container" style="background: #fff; border: 1px solid #ddd; height: 30px; border-radius: 4px; overflow: hidden; margin: 15px 0;">
      <div id="sm-progress-bar" style="background: linear-gradient(90deg, #2271b1, #72aee6); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; font-size: 14px;">
        0%
      </div>
    </div>

    <div id="sm-progress-details">
      <p style="margin: 5px 0;"><strong>Status:</strong> <span id="sm-upload-status">Initializing...</span></p>
      <p style="margin: 5px 0;"><strong>Progress:</strong> <span id="sm-upload-bytes">0 MB</span> of <span id="sm-upload-total">0 MB</span></p>
      <p style="margin: 5px 0;"><strong>Speed:</strong> <span id="sm-upload-speed">0 MB/s</span></p>
      <p style="margin: 5px 0;"><strong>Time Remaining:</strong> <span id="sm-upload-eta">Calculating...</span></p>
    </div>

    <div id="sm-processing-status" style="display:none; margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #ffb900;">
      <h4 style="margin-top: 0;">⏳ Processing Video...</h4>
      <p id="sm-processing-message">Video uploaded successfully! CDN is now processing and encoding your video...</p>
      <p style="margin: 10px 0;"><strong>Status:</strong> <span id="sm-bunny-status" style="font-weight: bold; color: #2271b1;">Checking...</span></p>
      <div id="sm-encoding-progress" style="display:none;">
        <p style="margin: 5px 0;"><strong>Encoding Progress:</strong> <span id="sm-encoding-percent">0%</span></p>
      </div>
    </div>

    <div id="sm-upload-complete" style="display:none; margin-top: 20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745;">
      <h4 style="margin-top: 0; color: #155724;">✅ Upload Complete!</h4>
      <p id="sm-complete-message">Your video has been uploaded and processed successfully.</p>
      <p style="margin-top: 15px;">
        <a href="<?php echo admin_url('admin.php?page=sm_dashboard'); ?>" class="button button-primary">View All Streams</a>
        <button class="button" id="sm-upload-another-btn">Upload Another Video</button>
      </p>
    </div>

    <div id="sm-upload-error" style="display:none; margin-top: 20px; padding: 15px; background: #f8d7da; border-left: 4px solid #dc3545;">
      <h4 style="margin-top: 0; color: #721c24;">❌ Upload Failed</h4>
      <p id="sm-error-message"></p>
      <p style="margin-top: 15px;">
        <button class="button button-primary" id="sm-retry-upload-btn">Retry Upload</button>
        <button class="button" id="sm-cancel-upload-btn">Cancel</button>
      </p>
    </div>
  </div>
</div>

<!-- Load TUS.js for resumable uploads -->
<script src="https://cdn.jsdelivr.net/npm/tus-js-client@latest/dist/tus.min.js"></script>

<style>
#sm-progress-bar-container {
  position: relative;
}

#sm-progress-bar {
  min-width: 50px;
}

.sm-status-badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 3px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.sm-status-queued {
  background: #fff3cd;
  color: #856404;
}

.sm-status-encoding {
  background: #cce5ff;
  color: #004085;
}

.sm-status-ready {
  background: #d4edda;
  color: #155724;
}

.sm-status-error {
  background: #f8d7da;
  color: #721c24;
}
</style>
