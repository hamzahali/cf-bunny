<?php
/**
 * Stream Keys Registry Database Functions
 */
if (!defined('ABSPATH')) exit;

/**
 * Get all stream keys from registry
 *
 * @return array Array of stream key objects
 */
function sm_get_all_stream_keys() {
    global $wpdb;
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;
    return $wpdb->get_results("SELECT * FROM {$table} ORDER BY name ASC");
}

/**
 * Get stream key by ID
 *
 * @param int $id Stream key ID
 * @return object|null Stream key object or null
 */
function sm_get_stream_key_by_id($id) {
    global $wpdb;
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
}

/**
 * Get stream key by live input UID
 *
 * @param string $live_input_uid Cloudflare live input UID
 * @return object|null Stream key object or null
 */
function sm_get_stream_key_by_uid($live_input_uid) {
    global $wpdb;
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE live_input_uid = %s", $live_input_uid));
}

/**
 * Create new stream key in registry
 *
 * @param array $data Stream key data
 * @return int|false Stream key ID or false on failure
 */
function sm_create_stream_key($data) {
    global $wpdb;
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;

    $insert_data = array(
        'name' => sanitize_text_field($data['name']),
        'live_input_uid' => sanitize_text_field($data['live_input_uid']),
        'stream_key' => sanitize_text_field($data['stream_key']),
        'default_subject' => isset($data['default_subject']) ? sanitize_text_field($data['default_subject']) : '',
        'default_category' => isset($data['default_category']) ? sanitize_text_field($data['default_category']) : '',
        'default_year' => isset($data['default_year']) ? sanitize_text_field($data['default_year']) : '',
        'default_batch' => isset($data['default_batch']) ? sanitize_text_field($data['default_batch']) : '',
        'created_at' => current_time('mysql')
    );

    $result = $wpdb->insert($table, $insert_data);

    if ($result === false) {
        return false;
    }

    return $wpdb->insert_id;
}

/**
 * Update stream key in registry
 *
 * @param int $id Stream key ID
 * @param array $data Stream key data to update
 * @return bool True on success, false on failure
 */
function sm_update_stream_key($id, $data) {
    global $wpdb;
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;

    $update_data = array();

    if (isset($data['name'])) {
        $update_data['name'] = sanitize_text_field($data['name']);
    }
    if (isset($data['default_subject'])) {
        $update_data['default_subject'] = sanitize_text_field($data['default_subject']);
    }
    if (isset($data['default_category'])) {
        $update_data['default_category'] = sanitize_text_field($data['default_category']);
    }
    if (isset($data['default_year'])) {
        $update_data['default_year'] = sanitize_text_field($data['default_year']);
    }
    if (isset($data['default_batch'])) {
        $update_data['default_batch'] = sanitize_text_field($data['default_batch']);
    }
    if (isset($data['last_used_at'])) {
        $update_data['last_used_at'] = $data['last_used_at'];
    }
    if (isset($data['recording_count'])) {
        $update_data['recording_count'] = intval($data['recording_count']);
    }
    if (isset($data['total_duration'])) {
        $update_data['total_duration'] = intval($data['total_duration']);
    }

    $result = $wpdb->update($table, $update_data, array('id' => $id));

    return $result !== false;
}

/**
 * Delete stream key from registry
 *
 * @param int $id Stream key ID
 * @return bool True on success, false on failure
 */
function sm_delete_stream_key($id) {
    global $wpdb;
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;

    // Check if any recordings exist for this stream key
    $count = sm_get_recording_count_for_stream_key($id);
    if ($count > 0) {
        return new WP_Error('has_recordings', sprintf(__('Cannot delete: %d recordings exist for this stream key', 'stream-manager'), $count));
    }

    $result = $wpdb->delete($table, array('id' => $id));

    return $result !== false;
}

/**
 * Get recording count for a stream key
 *
 * @param int $stream_key_id Stream key ID
 * @return int Number of recordings
 */
function sm_get_recording_count_for_stream_key($stream_key_id) {
    global $wpdb;

    $stream_key = sm_get_stream_key_by_id($stream_key_id);
    if (!$stream_key) {
        return 0;
    }

    return $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
        WHERE meta_key = '_sm_cf_live_input_uid'
        AND meta_value = %s",
        $stream_key->live_input_uid
    ));
}

/**
 * Update recording count and total duration for a stream key
 *
 * @param string $live_input_uid Live input UID
 * @return bool True on success
 */
function sm_update_stream_key_stats($live_input_uid) {
    global $wpdb;

    // Get all recordings for this stream key
    $post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
        WHERE meta_key = '_sm_cf_live_input_uid'
        AND meta_value = %s",
        $live_input_uid
    ));

    $recording_count = count($post_ids);
    $total_duration = 0;

    // Calculate total duration (this would require duration meta to be stored)
    // For now, we'll just update the count

    // Update registry
    $table = $wpdb->prefix . SM_REGISTRY_TABLE;
    $wpdb->update(
        $table,
        array(
            'recording_count' => $recording_count,
            'last_used_at' => current_time('mysql')
        ),
        array('live_input_uid' => $live_input_uid)
    );

    return true;
}

/**
 * Get stream key options for dropdowns
 *
 * @return array Array of id => name pairs
 */
function sm_get_stream_key_options() {
    $stream_keys = sm_get_all_stream_keys();
    $options = array();

    foreach ($stream_keys as $key) {
        $options[$key->id] = $key->name;
    }

    return $options;
}
