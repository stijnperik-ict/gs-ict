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

    public static function render_overview() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! function_exists( 'get_core_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $admins   = get_users( array( 'role' => 'administrator' ) );
        $enabled  = 0;
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

        $core_updates   = get_core_updates( array( 'dismissed' => false ) );
        $core_available = false;
        if ( is_array( $core_updates ) ) {
            foreach ( $core_updates as $core_update ) {
                if ( isset( $core_update->response ) && 'upgrade' === $core_update->response ) {
                    $core_available = true;
                    break;
                }
            }
        }

        $recent_events      = GS_ICT_Audit_Log::count_recent( 7 );
        $manual_maintenance = GS_ICT_Updates::auto_updates_disabled();
        $maintenance_value  = '';
        $maintenance_desc   = '';

        if ( $manual_maintenance ) {
            $maintenance       = GS_ICT_Updates::next_maintenance_date();
            $days              = GS_ICT_Updates::days_until_maintenance();
            $maintenance_value = wp_date( 'd-m-Y', $maintenance->getTimestamp(), wp_timezone() );
            $maintenance_desc  = 0 === $days
                ? __( 'Vandaag is de vaste onderhoudsdag', 'gs-ict' )
                : sprintf(
                    _n( 'Over %d dag', 'Over %d dagen', $days, 'gs-ict' ),
                    $days
                );
        }
        ?>
        <div class="gs-ict-panel">
            <div class="gs-ict-panel-header">
                <div>
                    <h2><?php esc_html_e( 'Security status', 'gs-ict' ); ?></h2>
                    <p><?php esc_html_e( 'Actuele beveiligings- en onderhoudsstatus van deze WordPress-installatie.', 'gs-ict' ); ?></p>
                </div>
            </div>

            <div class="gs-ict-status-grid">
                <?php self::status_card( 'WordPress', get_bloginfo( 'version' ), $core_available ? 'warning' : 'ok', $core_available ? 'Core-update beschikbaar' : 'Core is actueel' ); ?>
                <?php self::status_card( 'PHP', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '<' ) ? 'warning' : 'ok', version_compare( PHP_VERSION, '8.1', '<' ) ? 'Controleer PHP-versie' : 'Ondersteunde moderne versie' ); ?>
                <?php self::status_card( 'Pluginupdates', (string) $plugin_count, $plugin_count ? 'warning' : 'ok', $plugin_count ? 'Updates beschikbaar' : 'Geen openstaande updates' ); ?>
                <?php self::status_card( 'Thema-updates', (string) $theme_count, $theme_count ? 'warning' : 'ok', $theme_count ? 'Updates beschikbaar' : 'Geen openstaande updates' ); ?>
                <?php self::status_card( '2FA admins', $enabled . '/' . count( $admins ), $enabled === count( $admins ) ? 'ok' : 'warning', $required ? $required . ' installatie(s) vereist' : 'Administratorbeveiliging' ); ?>
                <?php self::status_card( 'Auditlog 7 dagen', (string) $recent_events, 'info', 'Geregistreerde gebeurtenissen' ); ?>
                <?php self::status_card( 'Automatische updates', GS_ICT_Updates::auto_updates_disabled() ? 'Uit' : 'Aan', GS_ICT_Updates::auto_updates_disabled() ? 'ok' : 'warning', GS_ICT_Updates::auto_updates_disabled() ? 'Handmatig beheer' : 'Automatisch toegestaan' ); ?>
                <?php if ( $manual_maintenance ) : ?>
                    <?php self::status_card( 'Volgend onderhoud', $maintenance_value, 'info', $maintenance_desc ); ?>
                <?php endif; ?>
            </div>

            <div class="gs-ict-panel-actions">
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gs-ict-audit-log' ) ); ?>"><?php esc_html_e( 'Beveiligingslogboek bekijken', 'gs-ict' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gs-ict-updates' ) ); ?>"><?php esc_html_e( 'Update-instellingen', 'gs-ict' ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gs-ict-2fa' ) ); ?>"><?php esc_html_e( '2FA beheren', 'gs-ict' ); ?></a>
            </div>
        </div>
        <?php
    }

    public static function render_two_factor_controls() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="gs-ict-panel">
            <div class="gs-ict-panel-header">
                <div>
                    <h2><?php esc_html_e( '2FA-beleid', 'gs-ict' ); ?></h2>
                    <p><?php esc_html_e( 'Bepaal of tweestapsverificatie voor alle administratoraccounts verplicht is.', 'gs-ict' ); ?></p>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gs_ict_save_security_policy">
                <?php wp_nonce_field( 'gs_ict_save_security_policy' ); ?>
                <label class="gs-ict-checkbox-row">
                    <input type="checkbox" name="require_all_admins" value="1" <?php checked( self::require_all_admins() ); ?>>
                    <span>
                        <strong><?php esc_html_e( 'Verplicht 2FA voor alle administratoraccounts', 'gs-ict' ); ?></strong>
                        <small><?php esc_html_e( 'Administrators zonder actieve 2FA worden bij hun eerstvolgende login naar het installatiescherm gestuurd. Nieuwe administrators vallen automatisch onder dit beleid.', 'gs-ict' ); ?></small>
                    </span>
                </label>
                <?php submit_button( __( '2FA-beleid opslaan', 'gs-ict' ), 'primary', 'submit', false ); ?>
            </form>
        </div>

        <?php
        $current_user = wp_get_current_user();
        if ( GS_ICT_Two_Factor::is_enabled( $current_user->ID ) ) :
        ?>
            <div class="gs-ict-panel">
                <div class="gs-ict-panel-header">
                    <div>
                        <h2><?php esc_html_e( 'Herstelcodes', 'gs-ict' ); ?></h2>
                        <p><?php esc_html_e( 'Maak een nieuwe set van 8 herstelcodes. De bestaande herstelcodes worden dan direct ongeldig.', 'gs-ict' ); ?></p>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gs_ict_regenerate_recovery_codes">
                    <?php wp_nonce_field( 'gs_ict_regenerate_recovery_codes' ); ?>
                    <div class="gs-ict-inline-form">
                        <label for="gs_ict_recovery_totp">
                            <strong><?php esc_html_e( 'Huidige authenticatorcode', 'gs-ict' ); ?></strong>
                            <input type="text" id="gs_ict_recovery_totp" name="totp_code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required>
                        </label>
                        <?php submit_button( __( 'Nieuwe herstelcodes genereren', 'gs-ict' ), 'secondary', 'submit', false ); ?>
                    </div>
                </form>
            </div>
        <?php
        endif;
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

        self::redirect_to_2fa( 'security_policy_saved' );
    }

    public static function regenerate_recovery_codes() {
        $user = wp_get_current_user();
        if ( ! current_user_can( 'manage_options' ) || ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }
        check_admin_referer( 'gs_ict_regenerate_recovery_codes' );

        $code   = isset( $_POST['totp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['totp_code'] ) ) : '';
        $result = GS_ICT_Two_Factor::regenerate_recovery_codes( get_current_user_id(), $code );

        if ( is_wp_error( $result ) ) {
            self::redirect_to_2fa( 'recovery_error' );
        }

        GS_ICT_Audit_Log::log( 'recovery_codes_regenerated', 'Nieuwe 2FA-herstelcodes gegenereerd.', 'warning' );
        self::redirect_to_2fa( 'recovery_regenerated' );
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
        ?>
        <div class="gs-ict-status-card gs-ict-status-<?php echo esc_attr( $status ); ?>">
            <div class="gs-ict-status-title"><?php echo esc_html( $title ); ?></div>
            <div class="gs-ict-status-value"><?php echo esc_html( $value ); ?></div>
            <div class="gs-ict-status-description"><?php echo esc_html( $description ); ?></div>
        </div>
        <?php
    }

    private static function redirect_to_2fa( $message ) {
        wp_safe_redirect(
            add_query_arg(
                'gsict_message',
                sanitize_key( $message ),
                admin_url( 'admin.php?page=gs-ict-2fa' )
            )
        );
        exit;
    }
}
