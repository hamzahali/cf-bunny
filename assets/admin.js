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
    var title = $('#sm-vod-title').val(); var id = $('#sm-picked-id').val();
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

  // Cloudflare Permission Verification
  function verifyCFPermissions() {
    var accountId = $('input[name="sm_cf_account_id"]').val();
    var token = $('input[name="sm_cf_api_token"]').val();

    if (!accountId || !token) {
      $('#sm-cf-permissions-status').hide();
      return;
    }

    $('#sm-cf-permissions-status').show();
    $('#sm-perm-message').html('<em>Verifying permissions...</em>');
    $('#sm-perm-get .sm-perm-status').text('...');
    $('#sm-perm-patch .sm-perm-status').text('...');
    $('#sm-perm-delete .sm-perm-status').text('...');

    $.post(SM_AJAX.ajaxurl, {
      action: 'sm_verify_cf_permissions',
      nonce: SM_AJAX.nonce,
      account_id: accountId,
      token: token
    }, function(r) {
      if (r.success && r.data && r.data.permissions) {
        var perms = r.data.permissions;
        $('#sm-perm-get .sm-perm-status').html(perms.get ? '<span style="color: #46b450;">&#x2714;</span>' : '<span style="color: #dc3232;">&#x2718;</span>');
        $('#sm-perm-patch .sm-perm-status').html(perms.patch ? '<span style="color: #46b450;">&#x2714;</span>' : '<span style="color: #dc3232;">&#x2718;</span>');
        $('#sm-perm-delete .sm-perm-status').html(perms.delete ? '<span style="color: #46b450;">&#x2714;</span>' : '<span style="color: #dc3232;">&#x2718;</span>');

        var allPassed = perms.get && perms.patch && perms.delete;
        if (allPassed) {
          $('#sm-perm-message').html('<span style="color: #46b450;">All permissions verified successfully!</span>');
        } else {
          var missing = [];
          if (!perms.get) missing.push('GET');
          if (!perms.patch) missing.push('PATCH');
          if (!perms.delete) missing.push('DELETE');
          $('#sm-perm-message').html('<span style="color: #dc3232;">Missing permissions: ' + missing.join(', ') + '</span>');
        }

        if (r.data.errors && Object.keys(r.data.errors).length > 0) {
          var errMsg = '<br/><strong>Errors:</strong><br/>';
          for (var key in r.data.errors) {
            errMsg += key.toUpperCase() + ': ' + r.data.errors[key] + '<br/>';
          }
          $('#sm-perm-message').append(errMsg);
        }
      } else {
        $('#sm-perm-get .sm-perm-status').html('<span style="color: #dc3232;">&#x2718;</span>');
        $('#sm-perm-patch .sm-perm-status').html('<span style="color: #dc3232;">&#x2718;</span>');
        $('#sm-perm-delete .sm-perm-status').html('<span style="color: #dc3232;">&#x2718;</span>');
        var errMsg = r.data && r.data.message ? r.data.message : 'Failed to verify permissions';
        $('#sm-perm-message').html('<span style="color: #dc3232;">' + errMsg + '</span>');
      }
    }).fail(function() {
      $('#sm-perm-message').html('<span style="color: #dc3232;">Request failed. Please try again.</span>');
    });
  }

  // Trigger verification after settings form submission
  $('#sm-settings-form').on('submit', function(e) {
    // Let the form submit normally, but also trigger verification after save
    setTimeout(function() {
      if ($('#setting-error-settings_updated').length > 0) {
        // Settings were saved successfully
        verifyCFPermissions();
      }
    }, 500);
  });

  // Also add a manual verification button
  if ($('input[name="sm_cf_account_id"]').length > 0) {
    var verifyBtn = $('<button type="button" class="button" id="sm-verify-permissions-btn" style="margin-left: 10px;">Verify Permissions</button>');
    $('input[name="sm_cf_api_token"]').parent().append(verifyBtn);

    verifyBtn.on('click', function(e) {
      e.preventDefault();
      verifyCFPermissions();
    });

    // Auto-verify if credentials are already present
    if ($('input[name="sm_cf_account_id"]').val() && $('input[name="sm_cf_api_token"]').val()) {
      verifyCFPermissions();
    }
  }
});
