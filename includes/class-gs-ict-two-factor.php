<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Two_Factor {
    const META_ENABLED         = '_gs_ict_2fa_enabled';
    const META_REQUIRED        = '_gs_ict_2fa_required';
    const META_REQUIRED_SOURCE = '_gs_ict_2fa_required_source';
    const META_SECRET          = '_gs_ict_2fa_secret';
    const META_PENDING_SECRET  = '_gs_ict_2fa_pending_secret';
    const META_RECOVERY        = '_gs_ict_2fa_recovery_codes';
    const META_RECOVERY_SHOW   = '_gs_ict_2fa_recovery_show';
    const META_LAST_STEP       = '_gs_ict_2fa_last_step';

    public static function init() {
        add_action( 'login_form', array( __CLASS__, 'render_login_field' ) );
        add_filter( 'wp_authenticate_user', array( __CLASS__, 'authenticate' ), 30, 2 );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
        add_action( 'admin_init', array( __CLASS__, 'enforce_setup_in_admin' ), 1 );
    }

    public static function is_admin_user( $user ) {
        return $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true );
    }

    public static function is_enabled( $user_id ) {
        return '1' === get_user_meta( (int) $user_id, self::META_ENABLED, true );
    }

    public static function is_required( $user_id ) {
        return '1' === get_user_meta( (int) $user_id, self::META_REQUIRED, true ) && ! self::is_enabled( $user_id );
    }

    public static function require_setup( $user_id, $source = 'manual' ) {
        $user = get_userdata( (int) $user_id );
        if ( ! self::is_admin_user( $user ) ) {
            return new WP_Error( 'gs_ict_not_admin', __( '2FA kan hier alleen voor administratoraccounts worden verplicht.', 'gs-ict' ) );
        }

        if ( self::is_enabled( $user_id ) ) {
            return true;
        }

        if ( ! GS_ICT_Crypto::is_available() ) {
            return new WP_Error( 'gs_ict_crypto', __( 'Versleuteling is niet beschikbaar op deze server.', 'gs-ict' ) );
        }

        if ( ! self::get_or_create_pending_secret( $user_id ) ) {
            return new WP_Error( 'gs_ict_secret', __( 'Er kon geen veilige 2FA-sleutel worden aangemaakt.', 'gs-ict' ) );
        }

        $was_required   = self::is_required( $user_id );
        $current_source = get_user_meta( (int) $user_id, self::META_REQUIRED_SOURCE, true );
        $source         = 'global' === $source ? 'global' : 'manual';

        update_user_meta( (int) $user_id, self::META_REQUIRED, '1' );

        if ( 'manual' === $source ) {
            update_user_meta( (int) $user_id, self::META_REQUIRED_SOURCE, 'manual' );
        } elseif ( ! $was_required ) {
            update_user_meta( (int) $user_id, self::META_REQUIRED_SOURCE, 'global' );
        } elseif ( '' === $current_source ) {
            // Verplichtingen uit 0.3.0 behandelen we als handmatig ingesteld.
            update_user_meta( (int) $user_id, self::META_REQUIRED_SOURCE, 'manual' );
        }

        if ( ! $was_required && class_exists( 'GS_ICT_Audit_Log' ) ) {
            GS_ICT_Audit_Log::log(
                '2fa_required',
                sprintf( '2FA-installatie verplicht voor administrator %s.', $user->user_login ),
                'warning',
                array( 'source' => $source, 'target_user_id' => (int) $user_id )
            );
        }

        return true;
    }

    public static function cancel_required_setup( $user_id, $force = false ) {
        if ( self::is_enabled( $user_id ) ) {
            return true;
        }

        if ( ! $force && class_exists( 'GS_ICT_Dashboard' ) && GS_ICT_Dashboard::require_all_admins() ) {
            return new WP_Error( 'gs_ict_global_2fa', __( 'De globale 2FA-verplichting staat aan. Schakel eerst het globale beleid uit.', 'gs-ict' ) );
        }

        delete_user_meta( (int) $user_id, self::META_REQUIRED );
        delete_user_meta( (int) $user_id, self::META_REQUIRED_SOURCE );
        delete_user_meta( (int) $user_id, self::META_PENDING_SECRET );

        if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
            GS_ICT_Audit_Log::log(
                '2fa_requirement_cancelled',
                'Verplichte 2FA-installatie geannuleerd.',
                'warning',
                array( 'target_user_id' => (int) $user_id )
            );
        }

        return true;
    }

    public static function render_login_field() {
        ?>
        <p>
            <label for="gs_ict_otp">
                <?php esc_html_e( 'Authenticatiecode (indien 2FA is ingeschakeld)', 'gs-ict' ); ?><br>
                <input type="text" name="gs_ict_otp" id="gs_ict_otp" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false">
            </label>
        </p>
        <?php
    }

    public static function authenticate( $user, $password ) {
        unset( $password );

        if ( is_wp_error( $user ) || ! self::is_admin_user( $user ) || ! self::is_enabled( $user->ID ) ) {
            return $user;
        }

        $rate_key = self::rate_key( $user->ID );
        $attempts = (int) get_transient( $rate_key );
        if ( $attempts >= 5 ) {
            self::audit_auth_failure( $user->ID, 'rate_limited' );
            return new WP_Error(
                'gs_ict_2fa_rate_limited',
                __( '<strong>Fout:</strong> Te veel onjuiste 2FA-pogingen. Probeer het over enkele minuten opnieuw.', 'gs-ict' )
            );
        }

        $submitted = isset( $_POST['gs_ict_otp'] ) ? sanitize_text_field( wp_unslash( $_POST['gs_ict_otp'] ) ) : '';
        if ( '' === $submitted ) {
            self::bump_rate_limit( $rate_key, $attempts );
            self::audit_auth_failure( $user->ID, 'missing_code' );
            return new WP_Error(
                'gs_ict_2fa_required',
                __( '<strong>Fout:</strong> Vul je 2FA-authenticatiecode of herstelcode in.', 'gs-ict' )
            );
        }

        $secret = self::get_secret( $user->ID );
        if ( $secret ) {
            $step = GS_ICT_TOTP::verify( $secret, $submitted, 1 );
            if ( false !== $step ) {
                $last_step = (int) get_user_meta( $user->ID, self::META_LAST_STEP, true );
                if ( $step <= $last_step ) {
                    self::bump_rate_limit( $rate_key, $attempts );
                    self::audit_auth_failure( $user->ID, 'replayed_code' );
                    return new WP_Error(
                        'gs_ict_2fa_replayed',
                        __( '<strong>Fout:</strong> Deze authenticatiecode is al gebruikt. Wacht op een nieuwe code.', 'gs-ict' )
                    );
                }

                update_user_meta( $user->ID, self::META_LAST_STEP, $step );
                delete_transient( $rate_key );
                return $user;
            }
        }

        if ( self::consume_recovery_code( $user->ID, $submitted ) ) {
            delete_transient( $rate_key );
            if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
                GS_ICT_Audit_Log::log( 'recovery_code_used', 'Een 2FA-herstelcode is gebruikt om in te loggen.', 'warning', array(), $user->ID );
            }
            return $user;
        }

        self::bump_rate_limit( $rate_key, $attempts );
        self::audit_auth_failure( $user->ID, 'invalid_code' );

        return new WP_Error(
            'gs_ict_2fa_invalid',
            __( '<strong>Fout:</strong> De 2FA-authenticatiecode of herstelcode is ongeldig.', 'gs-ict' )
        );
    }

    public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        unset( $requested_redirect_to );

        if ( self::is_admin_user( $user ) && self::is_required( $user->ID ) ) {
            return admin_url( 'admin.php?page=gs-ict-2fa-setup' );
        }

        return $redirect_to;
    }

    public static function enforce_setup_in_admin() {
        if ( ! is_user_logged_in() || wp_doing_ajax() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! self::is_admin_user( $user ) || ! self::is_required( $user->ID ) ) {
            return;
        }

        global $pagenow;
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        if ( 'admin.php' === $pagenow && 'gs-ict-2fa-setup' === $page ) {
            return;
        }

        if ( 'admin-post.php' === $pagenow ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=gs-ict-2fa-setup' ) );
        exit;
    }

    public static function get_or_create_pending_secret( $user_id ) {
        $encrypted = get_user_meta( (int) $user_id, self::META_PENDING_SECRET, true );
        if ( $encrypted ) {
            $secret = GS_ICT_Crypto::decrypt( $encrypted );
            if ( $secret ) {
                return $secret;
            }
        }

        if ( ! GS_ICT_Crypto::is_available() ) {
            return false;
        }

        $secret    = GS_ICT_TOTP::generate_secret();
        $encrypted = GS_ICT_Crypto::encrypt( $secret );
        if ( false === $encrypted ) {
            return false;
        }

        update_user_meta( (int) $user_id, self::META_PENDING_SECRET, $encrypted );
        return $secret;
    }

    public static function enable( $user_id, $code ) {
        $user = get_userdata( (int) $user_id );
        if ( ! self::is_admin_user( $user ) ) {
            return new WP_Error( 'gs_ict_not_admin', __( '2FA kan hier alleen voor administratoraccounts worden ingeschakeld.', 'gs-ict' ) );
        }

        $secret = self::get_or_create_pending_secret( $user_id );
        if ( ! $secret ) {
            return new WP_Error( 'gs_ict_crypto', __( 'Versleuteling is niet beschikbaar op deze server.', 'gs-ict' ) );
        }

        if ( false === GS_ICT_TOTP::verify( $secret, $code, 1 ) ) {
            return new WP_Error( 'gs_ict_bad_code', __( 'De ingevoerde authenticatorcode is niet geldig.', 'gs-ict' ) );
        }

        $encrypted = GS_ICT_Crypto::encrypt( $secret );
        if ( false === $encrypted ) {
            return new WP_Error( 'gs_ict_crypto', __( 'De 2FA-sleutel kon niet veilig worden opgeslagen.', 'gs-ict' ) );
        }

        update_user_meta( $user_id, self::META_SECRET, $encrypted );
        update_user_meta( $user_id, self::META_ENABLED, '1' );
        self::store_new_recovery_codes( $user_id );
        delete_user_meta( $user_id, self::META_REQUIRED );
        delete_user_meta( $user_id, self::META_REQUIRED_SOURCE );
        delete_user_meta( $user_id, self::META_PENDING_SECRET );
        delete_user_meta( $user_id, self::META_LAST_STEP );

        if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
            GS_ICT_Audit_Log::log( '2fa_enabled', sprintf( '2FA geactiveerd voor administrator %s.', $user->user_login ), 'info', array(), $user_id );
        }

        return true;
    }

    public static function disable( $user_id ) {
        $user = get_userdata( (int) $user_id );

        delete_user_meta( (int) $user_id, self::META_ENABLED );
        delete_user_meta( (int) $user_id, self::META_REQUIRED );
        delete_user_meta( (int) $user_id, self::META_REQUIRED_SOURCE );
        delete_user_meta( (int) $user_id, self::META_SECRET );
        delete_user_meta( (int) $user_id, self::META_PENDING_SECRET );
        delete_user_meta( (int) $user_id, self::META_RECOVERY );
        delete_user_meta( (int) $user_id, self::META_RECOVERY_SHOW );
        delete_user_meta( (int) $user_id, self::META_LAST_STEP );

        if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
            GS_ICT_Audit_Log::log(
                '2fa_disabled',
                '2FA uitgeschakeld voor een administratoraccount.',
                'warning',
                array( 'target_user_id' => (int) $user_id ),
                get_current_user_id()
            );
        }

        if ( $user && class_exists( 'GS_ICT_Dashboard' ) && GS_ICT_Dashboard::require_all_admins() ) {
            self::require_setup( $user_id, 'global' );
        }
    }

    public static function regenerate_recovery_codes( $user_id, $totp_code ) {
        if ( ! self::is_enabled( $user_id ) ) {
            return new WP_Error( 'gs_ict_2fa_disabled', __( '2FA is niet actief voor dit account.', 'gs-ict' ) );
        }

        $secret = self::get_secret( $user_id );
        if ( ! $secret || false === GS_ICT_TOTP::verify( $secret, $totp_code, 1 ) ) {
            return new WP_Error( 'gs_ict_bad_code', __( 'De ingevoerde authenticatorcode is niet geldig.', 'gs-ict' ) );
        }

        return self::store_new_recovery_codes( $user_id );
    }

    public static function get_secret( $user_id ) {
        $encrypted = get_user_meta( (int) $user_id, self::META_SECRET, true );
        return $encrypted ? GS_ICT_Crypto::decrypt( $encrypted ) : false;
    }

    public static function get_recovery_codes_for_display( $user_id ) {
        $codes = get_user_meta( (int) $user_id, self::META_RECOVERY_SHOW, true );
        if ( is_array( $codes ) && ! empty( $codes ) ) {
            delete_user_meta( (int) $user_id, self::META_RECOVERY_SHOW );
            return $codes;
        }
        return array();
    }

    public static function otpauth_uri( $user, $secret ) {
        $site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $account = $user->user_email ? $user->user_email : $user->user_login;
        $label   = rawurlencode( $site . ':' . $account );

        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode( $secret ) . '&issuer=' . rawurlencode( $site ) . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function store_new_recovery_codes( $user_id ) {
        $codes  = self::generate_recovery_codes( 8 );
        $hashes = array();

        foreach ( $codes as $recovery_code ) {
            $hashes[] = wp_hash_password( self::normalize_recovery_code( $recovery_code ) );
        }

        update_user_meta( (int) $user_id, self::META_RECOVERY, $hashes );
        update_user_meta( (int) $user_id, self::META_RECOVERY_SHOW, $codes );

        return true;
    }

    private static function generate_recovery_codes( $amount ) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes    = array();

        for ( $i = 0; $i < $amount; $i++ ) {
            $raw = '';
            for ( $j = 0; $j < 12; $j++ ) {
                $raw .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
            }
            $codes[] = substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 4 ) . '-' . substr( $raw, 8, 4 );
        }

        return $codes;
    }

    private static function normalize_recovery_code( $code ) {
        return strtoupper( preg_replace( '/[^A-Z0-9]/', '', (string) $code ) );
    }

    private static function consume_recovery_code( $user_id, $submitted ) {
        $normalized = self::normalize_recovery_code( $submitted );
        if ( strlen( $normalized ) !== 12 ) {
            return false;
        }

        $hashes = get_user_meta( (int) $user_id, self::META_RECOVERY, true );
        if ( ! is_array( $hashes ) ) {
            return false;
        }

        foreach ( $hashes as $index => $hash ) {
            if ( wp_check_password( $normalized, $hash ) ) {
                unset( $hashes[ $index ] );
                update_user_meta( (int) $user_id, self::META_RECOVERY, array_values( $hashes ) );
                return true;
            }
        }

        return false;
    }

    private static function audit_auth_failure( $user_id, $reason ) {
        if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
            GS_ICT_Audit_Log::log(
                '2fa_failed',
                'Mislukte 2FA-controle.',
                'warning',
                array( 'reason' => sanitize_key( $reason ) ),
                $user_id
            );
        }
    }

    private static function rate_key( $user_id ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        return 'gsict_2fa_' . (int) $user_id . '_' . md5( $ip );
    }

    private static function bump_rate_limit( $key, $attempts ) {
        set_transient( $key, (int) $attempts + 1, 5 * MINUTE_IN_SECONDS );
    }
}
