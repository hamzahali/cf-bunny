<?php
/**
 * Notifications System
 */
if (!defined('ABSPATH')) exit;

/**
 * Create a new notification
 *
 * @param string $type Notification type (success, error, warning, info)
 * @param string $title Notification title
 * @param string $message Notification message
 * @param int $post_id Optional post ID
 * @param string $cf_uid Optional Cloudflare video UID
 * @return int|false Notification ID or false on failure
 */
function sm_create_notification($type, $title, $message = '', $post_id = null, $cf_uid = '') {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;

    $data = array(
        'type' => sanitize_text_field($type),
        'title' => sanitize_text_field($title),
        'message' => sanitize_textarea_field($message),
        'post_id' => $post_id ? intval($post_id) : null,
        'cf_uid' => sanitize_text_field($cf_uid),
        'is_read' => 0,
        'created_at' => current_time('mysql')
    );

    $result = $wpdb->insert($table, $data);

    if ($result === false) {
        return false;
    }

    return $wpdb->insert_id;
}

/**
 * Get all notifications
 *
 * @param array $args Query arguments
 * @return array Array of notification objects
 */
function sm_get_notifications($args = array()) {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;

    $defaults = array(
        'limit' => 50,
        'offset' => 0,
        'type' => '',
        'is_read' => null,
        'order' => 'DESC'
    );

    $args = wp_parse_args($args, $defaults);

    $where = array('1=1');
    $where_values = array();

    if (!empty($args['type'])) {
        $where[] = 'type = %s';
        $where_values[] = $args['type'];
    }

    if ($args['is_read'] !== null) {
        $where[] = 'is_read = %d';
        $where_values[] = intval($args['is_read']);
    }

    $where_clause = implode(' AND ', $where);
    $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

    $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at {$order} LIMIT %d OFFSET %d";
    $where_values[] = intval($args['limit']);
    $where_values[] = intval($args['offset']);

    if (!empty($where_values)) {
        $query = $wpdb->prepare($query, $where_values);
    }

    return $wpdb->get_results($query);
}

/**
 * Get unread notification count
 *
 * @return int Number of unread notifications
 */
function sm_get_unread_count() {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;
    return intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_read = 0"));
}

/**
 * Mark notification as read
 *
 * @param int $notification_id Notification ID
 * @return bool True on success
 */
function sm_mark_notification_read($notification_id) {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;

    $result = $wpdb->update(
        $table,
        array('is_read' => 1),
        array('id' => intval($notification_id))
    );

    return $result !== false;
}

/**
 * Mark all notifications as read
 *
 * @return bool True on success
 */
function sm_mark_all_notifications_read() {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;

    $result = $wpdb->query("UPDATE {$table} SET is_read = 1 WHERE is_read = 0");

    return $result !== false;
}

/**
 * Delete notification
 *
 * @param int $notification_id Notification ID
 * @return bool True on success
 */
function sm_delete_notification($notification_id) {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;

    $result = $wpdb->delete($table, array('id' => intval($notification_id)));

    return $result !== false;
}

/**
 * Delete old notifications (older than N days)
 *
 * @param int $days Number of days to keep
 * @return int|false Number of deleted rows or false
 */
function sm_delete_old_notifications($days = 30) {
    global $wpdb;
    $table = $wpdb->prefix . SM_NOTIFICATIONS_TABLE;

    $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    return $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE created_at < %s",
        $date
    ));
}

/**
 * Log sync event
 *
 * @param string $sync_type Type of sync (webhook, manual, cron)
 * @param int $found Number of recordings found
 * @param int $imported Number of recordings imported
 * @param string $status Status (success, error)
 * @param string $message Optional message
 * @return int|false Sync log ID or false on failure
 */
function sm_log_sync_event($sync_type, $found, $imported, $status, $message = '') {
    global $wpdb;
    $table = $wpdb->prefix . SM_SYNC_LOG_TABLE;

    $data = array(
        'sync_type' => sanitize_text_field($sync_type),
        'sync_time' => current_time('mysql'),
        'recordings_found' => intval($found),
        'recordings_imported' => intval($imported),
        'status' => sanitize_text_field($status),
        'message' => sanitize_textarea_field($message)
    );

    $result = $wpdb->insert($table, $data);

    if ($result === false) {
        return false;
    }

    return $wpdb->insert_id;
}

/**
 * Get sync logs
 *
 * @param int $limit Number of logs to retrieve
 * @return array Array of sync log objects
 */
function sm_get_sync_logs($limit = 50) {
    global $wpdb;
    $table = $wpdb->prefix . SM_SYNC_LOG_TABLE;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} ORDER BY sync_time DESC LIMIT %d",
        $limit
    ));
}
