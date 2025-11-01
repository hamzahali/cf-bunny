jQuery(function($){
  function embedHTML(slug){
    var src = SM_AJAX.siteurl+'/?stream_embed=1&slug='+slug;
    return '<div style="position: relative; padding-top: 56.25%;"><iframe src="'+src+'" style="border: none; position: absolute; top: 0; left: 0; height: 100%; width: 100%;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe></div>';
  }
  $(document).on('click','.sm-copy-embed',function(e){
    e.preventDefault(); var slug=$(this).data('slug'); var html=embedHTML(slug);
    var t=document.createElement('textarea'); t.value=html; document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t);
    alert('Embed code copied');
  });
  $(document).on('click','.sm-preview-embed',function(e){
    e.preventDefault(); var slug=$(this).data('slug');
    window.open(SM_AJAX.siteurl+'/?stream_embed=1&slug='+slug,'_blank');
  });

  $('#sm-create-live').on('click', function(){
    var title = $('#sm-live-title').val().trim();
    if (!title) {
      alert('Title is required');
      return;
    }
    var $out = $('#sm-create-live-out').text('Creating...');
    $.post(SM_AJAX.ajaxurl, {
      action:'sm_create_live', nonce:SM_AJAX.nonce,
      name: $('#sm-live-title').val(), category: $('#sm-live-category').val(), year: $('#sm-live-year').val(), batch: $('#sm-live-batch').val()
    }, function(r){
      if (!r.success) return $out.text('Error: ' + (r.data && r.data.message || 'unknown'));
      $out.text('Created. Live Input ID: ' + r.data.live_input_id);
      var details = $('#sm-live-details'); details.empty().show();
      function row(label, value, isLink){
        var html = '<tr><th>'+label+'</th><td>'; if (isLink) { html+='<a href="'+value+'" target="_blank">'+value+'</a>'; } else { html+='<code>'+(value||'')+'</code>'; } html+=' <button class="button sm-copy" data-copy="'+(value||'')+'">Copy</button></td></tr>'; return html;
      }
      var table = $('<table class="widefat fixed striped" />'); table.append('<thead><tr><th style="width:240px">Field</th><th>Value</th></tr></thead>');
      var tbody = $('<tbody />');
      tbody.append(row('RTMP URL', 'rtmp://live.cloudflare.com/live', false));
      if (r.data.stream_key) tbody.append(row('Stream Key', r.data.stream_key, false));
      if (r.data.cf_iframe) tbody.append(row('Cloudflare Live Iframe', r.data.cf_iframe, true));
      var uni = SM_AJAX.siteurl+'/?stream_embed=1&slug='+r.data.slug;
      tbody.append(row('Universal Embed URL', uni, true));
      table.append(tbody); details.append(table);
      var embedBox = $('<div style="margin-top:12px;"><h2>Universal Embed Code</h2><textarea style="width:100%;height:120px;">'+ embedHTML(r.data.slug) +'</textarea><p><button class="button sm-copy-embed" data-slug="'+r.data.slug+'">📋 Copy Embed</button> <a class="button" target="_blank" href="'+uni+'">👁️ Preview</a></p></div>');
      details.append(embedBox);
    });
  });

  $(document).on('click', '.sm-copy', function(){
    var txt = $(this).data('copy') || '';
    var t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t);
    t.select(); document.execCommand('copy'); document.body.removeChild(t);
    $(this).text('Copied'); var self=this; setTimeout(function(){ $(self).text('Copy'); }, 1200);
  });

  var frame = null;
  $('#sm-pick-media').on('click', function(e){
    e.preventDefault();
    if (frame) { frame.open(); return; }
    frame = wp.media({ title: 'Select or Upload MP4', button: { text: 'Use this video' }, library: { type: 'video' }, multiple: false });
    frame.on('select', function(){
      var att = frame.state().get('selection').first().toJSON();
      $('#sm-picked-id').val(att.id); $('#sm-picked-url').val(att.url); $('#sm-picked-name').text(att.filename + ' (' + att.mime + ')');
    });
    frame.open();
  });

  $('#sm-upload-recorded-file').on('click', function(){
    var $out = $('#sm-upload-recorded-out').text('Uploading...');
    var title = $('#sm-vod-title').val().trim(); var id = $('#sm-picked-id').val();
    if (!title) {
      $out.text('Title is required');
      return;
    }
    if (!id) { $out.text('Please choose/upload an MP4 first.'); return; }
    $.post(SM_AJAX.ajaxurl, {
      action:'sm_upload_recorded_file',
      nonce:SM_AJAX.nonce,
      title:title,
      attachment_id:id,
      category: $('#sm-vod-category').val(),
      year: $('#sm-vod-year').val(),
      batch: $('#sm-vod-batch').val()
    }, function(r){
      if (!r.success) return $out.text('Error: ' + (r.data && r.data.message || 'unknown'));
      $out.text('VOD created and uploaded to Bunny.');
      var uni = SM_AJAX.siteurl+'/?stream_embed=1&slug='+r.data.slug;
      var box = $('#sm-vod-embed'); box.empty().show().append('<h2>Universal Embed Code</h2><textarea style="width:100%;height:120px;">'+embedHTML(r.data.slug)+'</textarea><p><button class="button sm-copy-embed" data-slug="'+r.data.slug+'">📋 Copy Embed</button> <a class="button" target="_blank" href="'+uni+'">👁️ Preview</a></p>');
    });
  });

  // Delete stream from both Cloudflare and Bunny
  $(document).on('click', '.sm-delete-stream', function(e){
    e.preventDefault();
    var btn = $(this);
    var postId = btn.data('post-id');
    var cfUid = btn.data('cf-uid');
    var bunnyGuid = btn.data('bunny-guid');
    var row = btn.closest('tr');

    if (!confirm('Are you sure you want to delete this stream from both Cloudflare and Bunny? This action cannot be undone.')) {
      return;
    }

    btn.prop('disabled', true).text('Deleting...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_delete_stream',
      nonce: SM_AJAX.nonce,
      post_id: postId,
      cf_uid: cfUid,
      bunny_guid: bunnyGuid
    }, function(r){
      if (r.success) {
        alert('✓ ' + r.data.message);
        row.fadeOut(400, function(){ $(this).remove(); });
      } else {
        var msg = '✗ ' + r.data.message;
        if (r.data.partial_success && r.data.partial_success.length > 0) {
          msg += '\n\nPartially successful: ' + r.data.partial_success.join(', ');
        }
        alert(msg);
        btn.prop('disabled', false).text('Delete');
      }
    }).fail(function(){
      alert('✗ Request failed');
      btn.prop('disabled', false).text('Delete');
    });
  });

  // Retry transfer from Cloudflare to Bunny
  $(document).on('click', '.sm-retry-transfer', function(e){
    e.preventDefault();
    var btn = $(this);
    var postId = btn.data('post-id');
    var cfUid = btn.data('cf-uid');

    if (!confirm('Retry transfer from Cloudflare to Bunny Stream?')) {
      return;
    }

    btn.prop('disabled', true).text('Retrying...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_retry_transfer',
      nonce: SM_AJAX.nonce,
      post_id: postId,
      cf_uid: cfUid
    }, function(r){
      if (r.success) {
        alert('✓ ' + r.data.message);
        setTimeout(function(){ location.reload(); }, 1500);
      } else {
        alert('✗ ' + r.data.message);
        btn.prop('disabled', false).text('Retry');
      }
    }).fail(function(){
      alert('✗ ' + r.data.message);
      btn.prop('disabled', false).text('Retry');
    });
  });

  // Create Stream Key
  $('#sm-create-stream-key').on('click', function(){
    var name = $('#sm-reg-name').val().trim();
    if (!name) {
      alert('Display name is required');
      return;
    }

    var btn = $(this);
    var $out = $('#sm-create-key-output');

    btn.prop('disabled', true).text('Creating...');
    $out.html('<span style="color: #666;">Creating live input in Cloudflare...</span>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_create_stream_key',
      nonce: SM_AJAX.nonce,
      name: name,
      subject: $('#sm-reg-subject').val(),
      category: $('#sm-reg-category').val(),
      year: $('#sm-reg-year').val(),
      batch: $('#sm-reg-batch').val()
    }, function(r){
      if (!r.success) {
        $out.html('<span style="color: #d63638;">✗ Error: ' + (r.data && r.data.message || 'Unknown error') + '</span>');
        btn.prop('disabled', false).text('Create Stream Key');
        return;
      }

      $out.html('<span style="color: #00a32a;">✓ Stream key created successfully!</span>');

      // Display details
      var details = $('#sm-stream-key-details');
      var html = '<h3>✓ Stream Key Created: ' + name + '</h3>';
      html += '<p><strong>RTMP Setup for OBS:</strong></p>';
      html += '<table class="widefat" style="margin-bottom: 15px;"><tbody>';
      html += '<tr><th style="width: 200px;">Server URL:</th><td><code>' + r.data.rtmp_url + '</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'' + r.data.rtmp_url + '\')">Copy</button></td></tr>';
      html += '<tr><th>Stream Key:</th><td><code>' + r.data.stream_key + '</code> <button class="button button-small" onclick="navigator.clipboard.writeText(\'' + r.data.stream_key + '\')">Copy</button></td></tr>';
      html += '</tbody></table>';

      if (r.data.cf_iframe) {
        html += '<p><strong>Cloudflare Live Preview:</strong></p>';
        html += '<div style="max-width: 640px; margin-bottom: 15px;"><div style="position: relative; padding-top: 56.25%;"><iframe src="' + r.data.cf_iframe + '" style="border: none; position: absolute; top: 0; left: 0; height: 100%; width: 100%;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe></div></div>';
      }

      html += '<p><em>This stream key has been added to your registry and can be reused for multiple recordings.</em></p>';
      html += '<p><button class="button" onclick="location.reload()">Done</button></p>';

      details.html(html).slideDown();

      // Clear form
      $('#sm-reg-name, #sm-reg-subject, #sm-reg-category, #sm-reg-year, #sm-reg-batch').val('');
      btn.prop('disabled', false).text('Create Stream Key');

      // Scroll to details
      $('html, body').animate({ scrollTop: details.offset().top - 50 }, 500);
    }).fail(function(){
      $out.html('<span style="color: #d63638;">✗ Request failed. Please try again.</span>');
      btn.prop('disabled', false).text('Create Stream Key');
    });
  });

  // Save Stream Key Edits
  $('#sm-save-stream-key').on('click', function(){
    var keyId = $('#sm-edit-key-id').val();
    var name = $('#sm-edit-name').val().trim();

    if (!name) {
      alert('Display name is required');
      return;
    }

    var btn = $(this);
    var $out = $('#sm-edit-output');

    btn.prop('disabled', true).text('Saving...');
    $out.text('Saving...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_update_stream_key',
      nonce: SM_AJAX.nonce,
      key_id: keyId,
      name: name,
      subject: $('#sm-edit-subject').val(),
      category: $('#sm-edit-category').val(),
      year: $('#sm-edit-year').val(),
      batch: $('#sm-edit-batch').val()
    }, function(r){
      if (r.success) {
        $out.html('<span style="color: #00a32a;">✓ Saved!</span>');
        setTimeout(function(){
          $('#sm-edit-key-modal').fadeOut();
          location.reload();
        }, 1000);
      } else {
        $out.html('<span style="color: #d63638;">✗ ' + (r.data && r.data.message || 'Error saving') + '</span>');
        btn.prop('disabled', false).text('Save Changes');
      }
    }).fail(function(){
      $out.html('<span style="color: #d63638;">✗ Request failed</span>');
      btn.prop('disabled', false).text('Save Changes');
    });
  });

  // Sync All Webhooks
  $('#sm-sync-all-webhooks').on('click', function(){
    var btn = $(this);
    var $status = $('#sm-sync-webhooks-status');

    if (!confirm('Sync webhook configuration for all stream keys?\n\nThis will configure each stream key to send live stream events to WordPress.')) {
      return;
    }

    btn.prop('disabled', true).text('Syncing...');
    $status.html('<span style="color: #007cba;">⏳ Syncing webhooks...</span>');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_sync_all_webhooks',
      nonce: SM_AJAX.nonce
    }, function(r){
      if (r.success) {
        var msg = '<span style="color: #00a32a;">✓ ' + r.data.message + '</span>';
        if (r.data.errors && r.data.errors.length > 0) {
          msg += '<br><span style="color: #d63638;">Errors: ' + r.data.errors.join(', ') + '</span>';
        }
        $status.html(msg);
        btn.prop('disabled', false).text('🔄 Sync All Webhooks');
      } else {
        $status.html('<span style="color: #d63638;">✗ ' + (r.data && r.data.message || 'Failed to sync') + '</span>');
        btn.prop('disabled', false).text('🔄 Sync All Webhooks');
      }
    }).fail(function(){
      $status.html('<span style="color: #d63638;">✗ Request failed</span>');
      btn.prop('disabled', false).text('🔄 Sync All Webhooks');
    });
  });
});

  // ==========================================
  // Direct Upload to Bunny Stream (TUS Protocol)
  // ==========================================

  var selectedFile = null;
  var tusUpload = null;
  var statusCheckInterval = null;
  var uploadStartTime = null;

  // Select video file button
  $('#sm-select-video-btn, #sm-change-file-btn').on('click', function(){
    $('#sm-video-file').click();
  });

  // File selected
  $('#sm-video-file').on('change', function(e){
    var file = e.target.files[0];
    if (!file) return;

    selectedFile = file;

    // Format file size
    var sizeText = formatFileSize(file.size);

    // Show file info
    $('#sm-file-name').text(file.name);
    $('#sm-file-size').text(sizeText);
    $('#sm-file-type').text(file.type || 'Unknown');
    $('#sm-file-info').slideDown();
    $('#sm-upload-section').slideDown();

    // Reset progress
    resetUploadUI();
  });

  // Start upload button
  $('#sm-start-upload-btn').on('click', function(){
    var title = $('#sm-vod-title').val().trim();

    if (!title) {
      alert('Please enter a video title');
      $('#sm-vod-title').focus();
      return;
    }

    if (!selectedFile) {
      alert('Please select a video file');
      return;
    }

    startBunnyUpload();
  });

  // Upload another button
  $('#sm-upload-another-btn').on('click', function(){
    location.reload();
  });

  // Retry upload button
  $('#sm-retry-upload-btn').on('click', function(){
    resetUploadUI();
    startBunnyUpload();
  });

  // Cancel upload button
  $('#sm-cancel-upload-btn').on('click', function(){
    if (tusUpload) {
      tusUpload.abort();
    }
    if (statusCheckInterval) {
      clearInterval(statusCheckInterval);
    }
    location.reload();
  });

  // Start upload process
  function startBunnyUpload(){
    uploadStartTime = Date.now();

    // Hide form, show progress
    $('table.form-table').hide();
    $('#sm-upload-section').hide();
    $('#sm-upload-progress').slideDown();
    $('#sm-progress-details').show();
    $('#sm-upload-status').text('Creating video in Bunny...');

    // Step 1: Create video in Bunny to get upload URL
    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_create_bunny_video_for_upload',
      nonce: SM_AJAX.nonce,
      title: $('#sm-vod-title').val()
    }, function(r){
      if (r.success) {
        var data = r.data;
        // Step 2: Upload file using TUS
        uploadFileToBunny(data.guid, data.upload_url, data.library_id);
      } else {
        showUploadError(r.data && r.data.message || 'Failed to create video in Bunny');
      }
    }).fail(function(){
      showUploadError('Request failed. Please check your connection.');
    });
  }

  // Upload file to Bunny using TUS protocol
  function uploadFileToBunny(videoGuid, uploadUrl, libraryId){
    $('#sm-upload-status').text('Uploading to Bunny Stream...');

    var upload = new tus.Upload(selectedFile, {
      endpoint: uploadUrl,
      retryDelays: [0, 3000, 5000, 10000, 20000],
      metadata: {
        filetype: selectedFile.type,
        title: $('#sm-vod-title').val()
      },
      onError: function(error) {
        console.error('Upload error:', error);
        showUploadError('Upload failed: ' + error.message);
      },
      onProgress: function(bytesUploaded, bytesTotal) {
        var percentage = ((bytesUploaded / bytesTotal) * 100).toFixed(1);
        updateProgressBar(percentage);

        var uploadedMB = (bytesUploaded / (1024 * 1024)).toFixed(2);
        var totalMB = (bytesTotal / (1024 * 1024)).toFixed(2);
        $('#sm-upload-bytes').text(uploadedMB + ' MB');
        $('#sm-upload-total').text(totalMB + ' MB');

        // Calculate speed and ETA
        var elapsedTime = (Date.now() - uploadStartTime) / 1000; // seconds
        var speed = bytesUploaded / elapsedTime; // bytes per second
        var speedMB = (speed / (1024 * 1024)).toFixed(2);
        $('#sm-upload-speed').text(speedMB + ' MB/s');

        var remainingBytes = bytesTotal - bytesUploaded;
        var eta = remainingBytes / speed; // seconds
        $('#sm-upload-eta').text(formatTime(eta));
      },
      onSuccess: function() {
        console.log('Upload complete!');
        $('#sm-upload-status').text('Upload complete!');
        updateProgressBar(100);

        // Hide progress details, show processing status
        $('#sm-progress-details').fadeOut();
        $('#sm-progress-title').text('✅ Upload Complete');
        $('#sm-processing-status').fadeIn();

        // Start monitoring video status
        monitorBunnyVideoStatus(videoGuid, libraryId);
      }
    });

    // Start the upload
    upload.start();
    tusUpload = upload;
  }

  // Monitor video processing status in Bunny
  function monitorBunnyVideoStatus(videoGuid, libraryId){
    var checkCount = 0;
    var maxChecks = 120; // 10 minutes (5 second intervals)

    statusCheckInterval = setInterval(function(){
      checkCount++;

      if (checkCount > maxChecks) {
        clearInterval(statusCheckInterval);
        showUploadError('Processing timeout. Video may still be encoding. Check "All Streams" page.');
        return;
      }

      $.post(SM_AJAX.ajaxurl, {
        action: 'sm_check_bunny_video_status',
        nonce: SM_AJAX.nonce,
        video_guid: videoGuid,
        library_id: libraryId
      }, function(r){
        if (r.success) {
          var status = r.data.status;
          var statusText = r.data.status_text;

          $('#sm-bunny-status').html('<span class="sm-status-badge sm-status-' + status + '">' + statusText + '</span>');

          // Update encoding progress if available
          if (r.data.encoding_progress) {
            $('#sm-encoding-progress').show();
            $('#sm-encoding-percent').text(r.data.encoding_progress + '%');
          }

          // Check if ready
          if (status === 'ready') {
            clearInterval(statusCheckInterval);
            onVideoReady(videoGuid, libraryId, r.data);
          } else if (status === 'error') {
            clearInterval(statusCheckInterval);
            showUploadError('Video processing failed: ' + (r.data.error_message || 'Unknown error'));
          }
        }
      });
    }, 5000); // Check every 5 seconds
  }

  // Video is ready - create WordPress post
  function onVideoReady(videoGuid, libraryId, videoData){
    $('#sm-processing-status').fadeOut();
    $('#sm-bunny-status').html('<span class="sm-status-badge sm-status-ready">Ready</span>');

    // Create WordPress post
    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_save_bunny_uploaded_video',
      nonce: SM_AJAX.nonce,
      video_guid: videoGuid,
      library_id: libraryId,
      title: $('#sm-vod-title').val(),
      subject: $('#sm-vod-subject').val(),
      category: $('#sm-vod-category').val(),
      year: $('#sm-vod-year').val(),
      batch: $('#sm-vod-batch').val(),
      iframe_url: videoData.iframe_url,
      hls_url: videoData.hls_url
    }, function(r){
      if (r.success) {
        $('#sm-complete-message').text('Video uploaded, processed, and added to WordPress successfully!');
        $('#sm-upload-complete').fadeIn();
      } else {
        showUploadError('Video uploaded but failed to create WordPress post: ' + (r.data && r.data.message || 'Unknown error'));
      }
    }).fail(function(){
      showUploadError('Video uploaded but failed to save to WordPress');
    });
  }

  // Update progress bar
  function updateProgressBar(percentage){
    $('#sm-progress-bar').css('width', percentage + '%').text(Math.floor(percentage) + '%');
  }

  // Show error
  function showUploadError(message){
    $('#sm-upload-progress').find('> *').not('#sm-upload-error').hide();
    $('#sm-error-message').text(message);
    $('#sm-upload-error').fadeIn();
  }

  // Reset UI
  function resetUploadUI(){
    $('#sm-upload-error').hide();
    $('#sm-upload-complete').hide();
    $('#sm-processing-status').hide();
    $('#sm-progress-details').show();
    updateProgressBar(0);
    $('#sm-upload-status').text('Initializing...');
    $('#sm-upload-bytes').text('0 MB');
    $('#sm-upload-speed').text('0 MB/s');
    $('#sm-upload-eta').text('Calculating...');
  }

  // Format file size
  function formatFileSize(bytes){
    if (bytes === 0) return '0 Bytes';
    var k = 1024;
    var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
  }

  // Format time (seconds to human readable)
  function formatTime(seconds){
    if (!isFinite(seconds) || seconds < 0) return 'Calculating...';
    if (seconds < 60) return Math.round(seconds) + ' seconds';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes';
    return Math.floor(seconds / 3600) + ' hours ' + Math.floor((seconds % 3600) / 60) + ' minutes';
  }
});
