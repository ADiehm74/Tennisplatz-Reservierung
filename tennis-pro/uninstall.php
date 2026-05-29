<?php
/**
 * Runs automatically when the plugin is deleted via the WordPress admin.
 *
 * Data is only removed when the admin has explicitly enabled the
 * "Daten beim Löschen entfernen" option on the plugin's options page.
 * By default this flag is OFF, so all bookings, courts and settings are
 * preserved across re-installations.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

$settings = get_option( 'tennis_pro_settings', [] );

if ( empty( $settings['delete_on_uninstall'] ) ) {
    // Option not set – keep all data (default behaviour).
    return;
}

// Admin explicitly opted in → remove everything.
global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tennis_bookings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tennis_courts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tennis_categories" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tennis_blocked_slots" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tennis_waitlist" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tennis_recurring_groups" );

delete_option( 'tennis_pro_db_ver' );
delete_option( 'tennis_pro_settings' );

// Remove custom role and capability added by the plugin.
remove_role( 'tennis_backend_admin' );
$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
    $admin_role->remove_cap( 'tennis_manage' );
}
