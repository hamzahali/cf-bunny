<div class="wrap">
  <h1>Add Recorded Video (Direct Upload)</h1>
  <p>Upload or select an MP4 from the Media Library and it will be uploaded directly to Stream.</p>
  <table class="form-table">
    <tr><th>Title <span style="color:red;">*</span></th><td><input type="text" id="sm-vod-title" class="regular-text" placeholder="Video title" required/></td></tr>
    <tr><th>Category</th><td><input type="text" id="sm-vod-category" class="regular-text"/></td></tr>
    <tr><th>Year</th><td><input type="text" id="sm-vod-year" class="regular-text"/></td></tr>
    <tr><th>Batch</th><td><input type="text" id="sm-vod-batch" class="regular-text"/></td></tr>
    <tr><th>MP4 File</th><td>
      <button class="button" id="sm-pick-media">Choose / Upload MP4</button>
      <span id="sm-picked-name" style="margin-left:8px;"></span>
      <input type="hidden" id="sm-picked-id" /><input type="hidden" id="sm-picked-url" />
    </td></tr>
  </table>
  <p><button class="button button-primary" id="sm-upload-recorded-file">Upload to Stream</button> <span id="sm-upload-recorded-out"></span></p>
  <div id="sm-vod-embed" style="display:none;margin-top:14px;"></div>
</div>
