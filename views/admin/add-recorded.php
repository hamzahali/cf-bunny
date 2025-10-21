<div class="wrap">
  <h1>Add Recorded Video (Direct Upload → Bunny)</h1>
  <p>Upload or select an MP4 from the Media Library and it will be uploaded directly to Bunny Stream.</p>
  <table class="form-table">
    <tr><th>Title</th><td><input type="text" id="sm-vod-title" class="regular-text" placeholder="Video title"/></td></tr>
    <tr><th>MP4 File</th><td>
      <button class="button" id="sm-pick-media">Choose / Upload MP4</button>
      <span id="sm-picked-name" style="margin-left:8px;"></span>
      <input type="hidden" id="sm-picked-id" /><input type="hidden" id="sm-picked-url" />
    </td></tr>
  </table>
  <p><button class="button button-primary" id="sm-upload-recorded-file">Upload to Bunny</button> <span id="sm-upload-recorded-out"></span></p>
  <div id="sm-vod-embed" style="display:none;margin-top:14px;"></div>
</div>
