<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Dashboard {
    const OPTION_REQUIRE_ALL_ADMINS = 'gs_ict_require_2fa_all_admins';

    public static function init() {
        add_action( 'admin_post_gs_ict_save_security_policy', array( __CLASS__, 'save_security_policy' ) );
        add_action( 'admin_post_gs_ict_regenerate_recovery_codes', array( __CLASS__, 'regenerate_recovery_codes' ) );
        add_action( 'set_user_role', array( __CLASS__, 'handle_role_change' ), 30, 3 );
        add_action( 'admin_init', array( __CLASS__, 'enforce_global_policy' ), 2 );
    }

    public static function require_all_admins() {
        return '1' === get_option( self::OPTION_REQUIRE_ALL_ADMINS, '0' );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! function_exists( 'get_core_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $admins = get_users( array( 'role' => 'administrator' ) );
        $enabled = 0;
        $required = 0;

        foreach ( $admins as $admin ) {
            if ( GS_ICT_Two_Factor::is_enabled( $admin->ID ) ) {
                $enabled++;
            } elseif ( GS_ICT_Two_Factor::is_required( $admin->ID ) ) {
                $required++;
            }
        }

        $plugin_updates = get_site_transient( 'update_plugins' );
        $theme_updates  = get_site_transient( 'update_themes' );
        $plugin_count   = is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) ? count( $plugin_updates->response ) : 0;
        $theme_count    = is_object( $theme_updates ) && ! empty( $theme_updates->response ) ? count( $theme_updates->response ) : 0;

        $core_updates = get_core_updates( array( 'dismissed' => false ) );
        $core_available = false;
        if ( is_array( $core_updates ) ) {
            foreach ( $core_updates as $core_update ) {
                if ( isset( $core_update->response ) && 'upgrade' === $core_update->response ) {
                    $core_available = true;
                    break;
                }
            }
        }

        $recent_events = GS_ICT_Audit_Log::count_recent( 7 );
        $planned_date  = get_option( 'gs_ict_planned_update_date', '' );
        ?>
        <div class="card" style="max-width:1100px">
            <h2><?php esc_html_e( 'Security status', 'gs-ict' ); ?></h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
                <?php self::status_card( 'WordPress', get_bloginfo( 'version' ), $core_available ? 'warning' : 'ok', $core_available ? 'Core-update beschikbaar' : 'Core is actueel' ); ?>
                <?php self::status_card( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '<' ) ? 'warning' : 'ok', version_compare( PHP_VERSION, '8.1', '<' ) ? 'Controleer PHP-versie' : 'Ondersteunde moderne versie' ); ?>
                <?php self::status_card( 'Pluginupdates', (string) $plugin_count, $plugin_count ? 'warning' : 'ok', $plugin_count ? 'Updates beschikbaar' : 'Geen openstaande updates' ); ?>
                <?php self::status_card( 'Thema-updates', (string) $theme_count, $theme_count ? 'warning' : 'ok', $theme_count ? 'Updates beschikbaar' : 'Geen openstaande updates' ); ?>
                <?php self::status_card( '2FA admins', $enabled . '/' . count( $admins ), $enabled === count( $admins ) ? 'ok' : 'warning', $required ? $required . ' installatie(s) vereist' : 'Administratorbeveiliging' ); ?>
                <?php self::status_card( 'Auditlog 7 dagen', (string) $recent_events, 'info', 'Geregistreerde gebeurtenissen' ); ?>
                <?php self::status_card( 'Automatische updates', GS_ICT_Updates::auto_updates_disabled() ? 'Uit' : 'Aan', GS_ICT_Updates::auto_updates_disabled() ? 'ok' : 'warning', GS_ICT_Updates::auto_updates_disabled() ? 'Handmatig beheer' : 'Automatisch toegestaan' ); ?>
                <?php self::status_card( 'Onderhoud', $planned_date ?: 'Niet gepland', $planned_date ? 'info' : 'warning', 'Geplande update-datum' ); ?>
            </div>
            <p style="margin-top:16px"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gs-ict-audit-log' ) ); ?>"><?php esc_html_e( 'Beveiligingslogboek bekijken', 'gs-ict' ); ?></a></p>
        </div>

        <div class="card" style="max-width:1100px">
            <h2><?php esc_html_e( '2FA-beleid', 'gs-ict' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gs_ict_save_security_policy">
                <?php wp_nonce_field( 'gs_ict_save_security_policy' ); ?>
                <p>
                    <label>
                        <input type="checkbox" name="require_all_admins" value="1" <?php checked( self::require_all_admins() ); ?>>
                        <strong><?php esc_html_e( 'Verplicht 2FA voor alle administratoraccounts', 'gs-ict' ); ?></strong>
                    </label>
                </p>
                <p class="description"><?php esc_html_e( 'Administrators zonder actieve 2FA worden bij hun eerstvolgende login naar het installatiescherm gestuurd. Nieuwe administrators vallen automatisch onder dit beleid.', 'gs-ict' ); ?></p>
                <?php submit_button( __( '2FA-beleid opslaan', 'gs-ict' ), 'secondary' ); ?>
            </form>
        </div>

        <?php
        $current_user = wp_get_current_user();
        if ( GS_ICT_Two_Factor::is_enabled( $current_user->ID ) ) :
        ?>
        <div class="card" style="max-width:1100px">
            <h2><?php esc_html_e( 'Herstelcodes', 'gs-ict' ); ?></h2>
            <p><?php esc_html_e( 'Maak een nieuwe set van 8 herstelcodes. De bestaande herstelcodes worden dan direct ongeldig.', 'gs-ict' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gs_ict_regenerate_recovery_codes">
                <?php wp_nonce_field( 'gs_ict_regenerate_recovery_codes' ); ?>
                <label for="gs_ict_recovery_totp"><strong><?php esc_html_e( 'Huidige authenticatorcode', 'gs-ict' ); ?></strong></label><br>
                <input type="text" id="gs_ict_recovery_totp" name="totp_code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required style="font-size:18px;letter-spacing:2px;width:140px">
                <?php submit_button( __( 'Nieuwe herstelcodes genereren', 'gs-ict' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php endif;
    }

    public static function save_security_policy() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }
        check_admin_referer( 'gs_ict_save_security_policy' );

        $enabled = isset( $_POST['require_all_admins'] ) ? '1' : '0';
        update_option( self::OPTION_REQUIRE_ALL_ADMINS, $enabled );

        if ( '1' === $enabled ) {
            self::apply_policy_to_all_admins();
        } else {
            self::remove_global_requirements();
        }

        GS_ICT_Audit_Log::log(
            'security_policy_changed',
            'Globaal 2FA-beleid voor administrators gewijzigd naar ' . ( '1' === $enabled ? 'verplicht' : 'niet verplicht' ) . '.',
            'warning'
        );

        self::redirect( 'security_policy_saved' );
    }

    public static function regenerate_recovery_codes() {
        $user = wp_get_current_user();
        if ( ! current_user_can( 'manage_options' ) || ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }
        check_admin_referer( 'gs_ict_regenerate_recovery_codes' );

        $code = isset( $_POST['totp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['totp_code'] ) ) : '';
        $result = GS_ICT_Two_Factor::regenerate_recovery_codes( get_current_user_id(), $code );

        if ( is_wp_error( $result ) ) {
            self::redirect( 'recovery_error' );
        }

        GS_ICT_Audit_Log::log( 'recovery_codes_regenerated', 'Nieuwe 2FA-herstelcodes gegenereerd.', 'warning' );
        self::redirect( 'recovery_regenerated' );
    }

    public static function handle_role_change( $user_id, $role, $old_roles ) {
        unset( $old_roles );
        if ( 'administrator' === $role && self::require_all_admins() && ! GS_ICT_Two_Factor::is_enabled( $user_id ) ) {
            GS_ICT_Two_Factor::require_setup( $user_id, 'global' );
        }
    }

    public static function enforce_global_policy() {
        if ( self::require_all_admins() && current_user_can( 'manage_options' ) ) {
            self::apply_policy_to_all_admins();
        }
    }

    private static function apply_policy_to_all_admins() {
        $admins = get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) );
        foreach ( $admins as $user_id ) {
            if ( ! GS_ICT_Two_Factor::is_enabled( $user_id ) ) {
                GS_ICT_Two_Factor::require_setup( $user_id, 'global' );
            }
        }
    }

    private static function remove_global_requirements() {
        $admins = get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) );
        foreach ( $admins as $user_id ) {
            if ( 'global' === get_user_meta( $user_id, GS_ICT_Two_Factor::META_REQUIRED_SOURCE, true ) && ! GS_ICT_Two_Factor::is_enabled( $user_id ) ) {
                GS_ICT_Two_Factor::cancel_required_setup( $user_id, true );
            }
        }
    }

    private static function status_card( $title, $value, $status, $description ) {
        $border = '#72aee6';
        if ( 'ok' === $status ) {
            $border = '#00a32a';
        } elseif ( 'warning' === $status ) {
            $border = '#dba617';
        }
        ?>
        <div style="border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr( $border ); ?>;padding:12px;background:#fff">
            <div style="font-size:12px;color:#646970;text-transform:uppercase"><?php echo esc_html( $title ); ?></div>
            <div style="font-size:24px;font-weight:600;margin:4px 0"><?php echo esc_html( $value ); ?></div>
            <div style="font-size:12px;color:#646970"><?php echo esc_html( $description ); ?></div>
        </div>
        <?php
    }

    private static function redirect( $message ) {
        wp_safe_redirect(
            add_query_arg(
                'gsict_message',
                sanitize_key( $message ),
                admin_url( 'admin.php?page=gs-ict' )
            )
        );
        exit;
    }
}
