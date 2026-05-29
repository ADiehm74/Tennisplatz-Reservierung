<?php
/**
 * Plugin Name: Tennisplatz-Reservierung Pro
 * Description: Tennisplatz-Reservierungssystem – Frontend-Buchung für eingeloggte Nutzer, Admin-Verwaltungspanel, Wochenansicht, wiederkehrende Buchungen, Warteliste, CSV/iCal-Export und mehr.
 * Version: 5.1.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Alexander Diehm
 * Author URI: mailto:a.diehm@cco-it.de
 * Text Domain: tennis-pro
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TENNIS_PRO_PATH',   plugin_dir_path( __FILE__ ) );
define( 'TENNIS_PRO_URL',    plugin_dir_url( __FILE__ ) );
define( 'TENNIS_PRO_VER',    '5.1.0' );
define( 'TENNIS_PRO_DB_VER', '3.0' );

require_once TENNIS_PRO_PATH . 'includes/db.php';
require_once TENNIS_PRO_PATH . 'includes/email.php';
require_once TENNIS_PRO_PATH . 'includes/settings.php';
require_once TENNIS_PRO_PATH . 'includes/export.php';
require_once TENNIS_PRO_PATH . 'includes/admin.php';
require_once TENNIS_PRO_PATH . 'includes/admin-users.php';
require_once TENNIS_PRO_PATH . 'includes/ajax.php';
require_once TENNIS_PRO_PATH . 'includes/frontend.php';
require_once TENNIS_PRO_PATH . 'includes/user-forms.php';

register_activation_hook( __FILE__, 'tennis_pro_activate' );
add_action( 'plugins_loaded', 'tennis_pro_maybe_upgrade_db' );

function tennis_pro_activate(): void {
    tennis_pro_create_tables();
    tennis_pro_setup_roles();
    update_option( 'tennis_pro_db_ver', TENNIS_PRO_DB_VER );
}

/**
 * Auto-upgrade DB schema for existing installations.
 * Runs once on plugins_loaded when the stored version differs.
 */
function tennis_pro_maybe_upgrade_db(): void {
    if ( get_option( 'tennis_pro_db_ver' ) !== TENNIS_PRO_DB_VER ) {
        tennis_pro_create_tables();
        tennis_pro_setup_roles();
        update_option( 'tennis_pro_db_ver', TENNIS_PRO_DB_VER );
    }
}

/**
 * Create/update custom roles and capabilities.
 * Called on activation and DB upgrade so existing installs are covered.
 */
function tennis_pro_setup_roles(): void {
    // Ensure the built-in administrator role has the tennis_manage cap.
    $admin_role = get_role( 'administrator' );
    if ( $admin_role && ! $admin_role->has_cap( 'tennis_manage' ) ) {
        $admin_role->add_cap( 'tennis_manage' );
    }
    // Create the tennis_backend_admin role if it doesn't exist yet.
    if ( ! get_role( 'tennis_backend_admin' ) ) {
        add_role(
            'tennis_backend_admin',
            __( 'Tennisplatz-Admin', 'tennis-pro' ),
            [ 'read' => true, 'tennis_manage' => true ]
        );
    }
}
