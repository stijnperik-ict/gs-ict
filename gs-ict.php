<?php
/**
 * Plugin Name: GS ICT
 * Description: Beveiligings- en beheerfuncties voor WordPress, waaronder TOTP-2FA voor administrators en controle over automatische updates.
 * Version: 0.6.0
 * Update URI: https://github.com/stijnperik-ict/gs-ict
 * Author: GS ICT
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: gs-ict
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GS_ICT_VERSION', '0.6.0' );
define( 'GS_ICT_FILE', __FILE__ );
define( 'GS_ICT_DIR', plugin_dir_path( __FILE__ ) );

require_once GS_ICT_DIR . 'includes/class-gs-ict-crypto.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-totp.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-audit-log.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-two-factor.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-updates.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-dashboard.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-github-updater.php';
require_once GS_ICT_DIR . 'includes/class-gs-ict-admin.php';

register_activation_hook( __FILE__, function () {
    if ( false === get_option( 'gs_ict_disable_auto_updates', false ) ) {
        add_option( 'gs_ict_disable_auto_updates', '1' );
    }
    GS_ICT_Audit_Log::maybe_install();
} );

add_action( 'plugins_loaded', function () {
    GS_ICT_Audit_Log::maybe_install();
    GS_ICT_Two_Factor::init();
    GS_ICT_Updates::init();
    GS_ICT_Dashboard::init();
    GS_ICT_GitHub_Updater::init();
    GS_ICT_Admin::init();
    GS_ICT_Audit_Log::init();
} );
