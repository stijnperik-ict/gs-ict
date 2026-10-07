<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Updates {
    public static function init() {
        if ( self::auto_updates_disabled() ) {
            add_filter( 'automatic_updater_disabled', '__return_true', 999 );
            add_filter( 'auto_update_core', '__return_false', 999 );
            add_filter( 'auto_update_plugin', '__return_false', 999 );
            add_filter( 'auto_update_theme', '__return_false', 999 );
            add_filter( 'auto_update_translation', '__return_false', 999 );
            add_filter( 'plugins_auto_update_enabled', '__return_false', 999 );
            add_filter( 'themes_auto_update_enabled', '__return_false', 999 );
        }

        add_action( 'admin_notices', array( __CLASS__, 'planned_update_notice' ) );
    }

    public static function auto_updates_disabled() {
        return '1' === get_option( 'gs_ict_disable_auto_updates', '1' );
    }

    public static function planned_update_notice() {
        if ( ! current_user_can( 'update_core' ) ) {
            return;
        }

        $date = get_option( 'gs_ict_planned_update_date', '' );
        if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return;
        }

        $today = wp_date( 'Y-m-d' );
        if ( $date > $today ) {
            return;
        }

        $url = admin_url( 'update-core.php' );
        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(
            sprintf(
                __( '<strong>GS ICT:</strong> de geplande handmatige updatedatum (%1$s) is bereikt. <a href="%2$s">Controleer en voer updates handmatig uit</a>.', 'gs-ict' ),
                esc_html( $date ),
                esc_url( $url )
            )
        );
        echo '</p></div>';
    }
}
