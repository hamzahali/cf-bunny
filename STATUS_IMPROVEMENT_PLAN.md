# Live Stream Status Improvement Plan

## API Response Fields We'll Use

From `GET /stream/live_inputs/{live_input_uid}`:

```json
{
  "result": {
    "uid": "abcd1234",
    "status": {
      "state": "live-inprogress",  // or "ready", "connected", etc.
      "connected": true
    },
    "recording": {
      "available": true,
      "filename": "yourfile.mp4",
      "status": "ready"           // or "processing", "error"
    }
  }
}
```

## New Status Logic

### For Videos with `cf_live_input` UID:

| Condition | Status Display | Color | Retry Button | Description |
|-----------|----------------|-------|--------------|-------------|
| `state === "live-inprogress"` AND `connected === true` | **🔴 LIVE NOW** | Blue Bold | No | Currently streaming |
| `state === "live-inprogress"` AND `connected === false` | **⏸️ LIVE PAUSED** | Orange | No | Stream disconnected temporarily |
| `recording.available === false` AND stream ended < 30 min ago | **⏳ RECORDING PROCESSING** | Orange | No | CF is processing the recording |
| `recording.available === false` AND stream ended > 30 min ago | **❌ NO RECORDING** | Gray | No | Recording failed or unavailable |
| `recording.available === true` AND `recording.status === "ready"` AND no Bunny GUID | **✅ READY TO TRANSFER** | Yellow/Green Bold | ✅ Yes | Recording ready, click to transfer |
| `recording.available === true` AND transfer stuck > 15 min | **🔥 TRANSFER STUCK** | Red Bold | ✅ Yes | Recording available but transfer failed |
| Has Bunny GUID | **✅ COMPLETED** | Green | No | Successfully transferred |

### For Videos with `cfv` (video UID only):

| Condition | Status Display | Color | Retry Button |
|-----------|----------------|-------|--------------|
| Has Bunny GUID | **✅ VOD** | Green | No |
| CF video exists, no Bunny GUID, transfer attempted > 15 min | **🔥 TRANSFER STUCK** | Red Bold | ✅ Yes |
| CF video exists, no transfer attempted | **⚠️ TRANSFER NOT STARTED** | Red | ✅ Yes |
| CF video doesn't exist | **🗑️ CF VIDEO DELETED** | Gray | No |

## Implementation Plan

### 1. Add Live Input API Function
```php
// includes/helpers/cloudflare.php
function sm_cf_get_live_input($account_id, $token, $live_input_uid) {
    $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/stream/live_inputs/{$live_input_uid}";
    $res = wp_remote_get($url, array('headers' => sm_cf_headers($token), 'timeout' => 10));
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    if ($code >= 200 && $code < 300) {
        return json_decode(wp_remote_retrieve_body($res), true);
    }
    return new WP_Error('cf_api_failed', "CF API returned {$code}");
}
```

### 2. Update Dashboard Status Detection
```php
// views/admin/dashboard.php

if ($cf_live_input && !$bg) {
    // Get live input status from CF API
    $live_info = sm_cf_get_live_input($cf_acc, $cf_tok, $cf_live_input);

    if (!is_wp_error($live_info) && isset($live_info['result'])) {
        $state = isset($live_info['result']['status']['state']) ? $live_info['result']['status']['state'] : '';
        $connected = isset($live_info['result']['status']['connected']) ? $live_info['result']['status']['connected'] : false;
        $rec_available = isset($live_info['result']['recording']['available']) ? $live_info['result']['recording']['available'] : false;
        $rec_status = isset($live_info['result']['recording']['status']) ? $live_info['result']['recording']['status'] : '';

        if ($state === 'live-inprogress' && $connected) {
            $display_status = '🔴 LIVE NOW';
            $status_color = 'color:blue;font-weight:bold;';
        } elseif ($state === 'live-inprogress' && !$connected) {
            $display_status = '⏸️ LIVE PAUSED';
            $status_color = 'color:orange;';
        } elseif ($rec_available && $rec_status === 'ready') {
            if ($transfer_done) {
                $transfer_time = strtotime($transfer_done);
                $time_elapsed = time() - $transfer_time;
                if ($time_elapsed > 900) {
                    $display_status = '🔥 TRANSFER STUCK';
                    $status_color = 'color:red;font-weight:bold;';
                    $show_retry = true;
                } else {
                    $display_status = '⏳ TRANSFERRING';
                    $status_color = 'color:orange;';
                }
            } else {
                $display_status = '✅ READY TO TRANSFER';
                $status_color = 'color:#d4af37;font-weight:bold;'; // Gold color
                $show_retry = true;
            }
        } elseif (!$rec_available) {
            // Check how long ago stream ended
            $modified = isset($live_info['result']['modified']) ? strtotime($live_info['result']['modified']) : 0;
            $time_since_end = time() - $modified;

            if ($time_since_end < 1800) { // 30 minutes
                $display_status = '⏳ RECORDING PROCESSING';
                $status_color = 'color:orange;';
            } else {
                $display_status = '❌ NO RECORDING';
                $status_color = 'color:gray;';
            }
        }
    }
}
```

### 3. Cache API Responses
To avoid hitting CF API rate limits, cache the live input status for 30 seconds:

```php
$cache_key = 'sm_live_input_' . $cf_live_input;
$live_info = get_transient($cache_key);

if ($live_info === false) {
    $live_info = sm_cf_get_live_input($cf_acc, $cf_tok, $cf_live_input);
    if (!is_wp_error($live_info)) {
        set_transient($cache_key, $live_info, 30); // Cache for 30 seconds
    }
}
```

## Benefits

1. **Clear Live Status** - Users can see if stream is actively live vs ended
2. **Recording Readiness** - Know when CF recording is ready for transfer
3. **Better Error Detection** - Identify when recordings failed on CF side
4. **Actionable Retry** - Only show retry when recording actually exists
5. **No False Positives** - Don't show retry for streams that never recorded

## Visual Improvements

Add emojis/icons for quick visual scanning:
- 🔴 **LIVE NOW** - Streaming right now
- ⏸️ **LIVE PAUSED** - Temporarily disconnected
- ⏳ **RECORDING PROCESSING** - Wait for CF to finish
- ✅ **READY TO TRANSFER** - Action needed: Click retry to transfer
- 🔥 **TRANSFER STUCK** - Action needed: Transfer failed, retry
- ❌ **NO RECORDING** - Dead end: No recording available
- 🗑️ **CF VIDEO DELETED** - Dead end: Video removed from CF
- ✅ **COMPLETED** - Success: Video on Bunny CDN

## Performance Considerations

- Cache API responses for 30 seconds (avoid rate limits)
- Only call API for videos without Bunny GUID (already completed ones skip check)
- Batch check multiple live inputs if needed (future optimization)
