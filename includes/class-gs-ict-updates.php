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

        // De oude handmatig ingestelde onderhoudsdatum is vanaf 0.5.0 niet meer nodig.
        if ( false !== get_option( 'gs_ict_planned_update_date', false ) ) {
            delete_option( 'gs_ict_planned_update_date' );
        }

        add_action( 'admin_notices', array( __CLASS__, 'maintenance_day_notice' ) );
    }

    public static function auto_updates_disabled() {
        return '1' === get_option( 'gs_ict_disable_auto_updates', '1' );
    }

    public static function next_maintenance_date() {
        $today = current_datetime()->setTime( 0, 0, 0 );
        $year  = (int) $today->format( 'Y' );
        $month = (int) $today->format( 'n' );

        $candidate = self::first_monday_of_month( $year, $month );

        if ( $candidate < $today ) {
            $next_month = $today->modify( 'first day of next month' );
            $candidate  = self::first_monday_of_month(
                (int) $next_month->format( 'Y' ),
                (int) $next_month->format( 'n' )
            );
        }

        return $candidate;
    }

    public static function days_until_maintenance() {
        $today = current_datetime()->setTime( 0, 0, 0 );
        return (int) $today->diff( self::next_maintenance_date() )->format( '%a' );
    }

    public static function maintenance_day_notice() {
        if ( ! current_user_can( 'update_core' ) ) {
            return;
        }

        $today       = current_datetime()->setTime( 0, 0, 0 );
        $maintenance = self::first_monday_of_month(
            (int) $today->format( 'Y' ),
            (int) $today->format( 'n' )
        );

        if ( $today->format( 'Y-m-d' ) !== $maintenance->format( 'Y-m-d' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(
            sprintf(
                __( '<strong>GS ICT:</strong> vandaag is de vaste maandelijkse onderhoudsdag. <a href="%s">Controleer en voer de WordPress-updates handmatig uit</a>.', 'gs-ict' ),
                esc_url( admin_url( 'update-core.php' ) )
            )
        );
        echo '</p></div>';
    }

    private static function first_monday_of_month( $year, $month ) {
        $timezone = wp_timezone();
        $first    = new DateTimeImmutable(
            sprintf( '%04d-%02d-01 00:00:00', (int) $year, (int) $month ),
            $timezone
        );
        $weekday = (int) $first->format( 'N' );
        $offset  = ( 8 - $weekday ) % 7;

        return $offset ? $first->modify( '+' . $offset . ' days' ) : $first;
    }
}
