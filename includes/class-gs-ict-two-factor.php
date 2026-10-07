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

    const CHALLENGE_TTL      = 300;
    const CHALLENGE_ATTEMPTS = 5;

    public static function init() {
        add_filter( 'authenticate', array( __CLASS__, 'start_challenge_after_password' ), 50, 3 );
        add_action( 'login_form_gs_ict_2fa', array( __CLASS__, 'handle_login_challenge' ) );
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

    public static function start_challenge_after_password( $user, $username, $password ) {
        unset( $username, $password );

        if ( is_wp_error( $user ) || ! self::is_admin_user( $user ) || ! self::is_enabled( $user->ID ) ) {
            return $user;
        }

        if ( ! self::is_interactive_login_request() ) {
            return new WP_Error(
                'gs_ict_2fa_interactive_required',
                __( '<strong>Fout:</strong> Voor dit administratoraccount is tweestapsverificatie vereist. Log interactief in via wp-login.php.', 'gs-ict' )
            );
        }

        $token = bin2hex( random_bytes( 32 ) );
        $data  = array(
            'user_id'     => (int) $user->ID,
            'remember'    => ! empty( $_POST['rememberme'] ),
            'redirect_to' => self::requested_redirect(),
            'attempts'    => 0,
            'created_at'  => time(),
        );

        set_transient( self::challenge_key( $token ), $data, self::CHALLENGE_TTL );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'action'    => 'gs_ict_2fa',
                    'challenge' => rawurlencode( $token ),
                ),
                site_url( 'wp-login.php', 'login_post' )
            )
        );
        exit;
    }

    public static function handle_login_challenge() {
        $token = isset( $_REQUEST['challenge'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['challenge'] ) ) : '';
        $data  = self::get_challenge( $token );
        $error = null;

        if ( ! $data ) {
            self::render_challenge_page(
                $token,
                null,
                new WP_Error(
                    'gs_ict_challenge_expired',
                    __( 'Deze 2FA-aanvraag is verlopen of ongeldig. Log opnieuw in.', 'gs-ict' )
                )
            );
        }

        if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
            $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'gs_ict_2fa_challenge_' . $token ) ) {
                $error = new WP_Error( 'gs_ict_challenge_nonce', __( 'De beveiligingscontrole is verlopen. Probeer opnieuw.', 'gs-ict' ) );
            } elseif ( (int) $data['attempts'] >= self::CHALLENGE_ATTEMPTS ) {
                $error = new WP_Error( 'gs_ict_challenge_locked', __( 'Te veel onjuiste 2FA-pogingen. Log opnieuw in.', 'gs-ict' ) );
            } else {
                $submitted = isset( $_POST['gs_ict_otp'] ) ? sanitize_text_field( wp_unslash( $_POST['gs_ict_otp'] ) ) : '';
                $result    = self::verify_login_code( (int) $data['user_id'], $submitted );

                if ( true === $result ) {
                    delete_transient( self::challenge_key( $token ) );

                    $user = get_userdata( (int) $data['user_id'] );
                    if ( ! $user ) {
                        self::render_challenge_page(
                            $token,
                            null,
                            new WP_Error( 'gs_ict_user_missing', __( 'Het gebruikersaccount kon niet worden geladen. Log opnieuw in.', 'gs-ict' ) )
                        );
                    }

                    wp_set_current_user( $user->ID );
                    wp_set_auth_cookie( $user->ID, ! empty( $data['remember'] ), is_ssl() );
                    do_action( 'wp_login', $user->user_login, $user );

                    $redirect_to = wp_validate_redirect(
                        isset( $data['redirect_to'] ) ? $data['redirect_to'] : '',
                        admin_url()
                    );

                    wp_safe_redirect( $redirect_to );
                    exit;
                }

                $data['attempts'] = (int) $data['attempts'] + 1;
                self::save_challenge( $token, $data );

                $error = $result instanceof WP_Error
                    ? $result
                    : new WP_Error( 'gs_ict_2fa_invalid', __( 'De authenticatorcode of herstelcode is ongeldig.', 'gs-ict' ) );
            }
        }

        self::render_challenge_page( $token, $data, $error );
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
        $page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

        if ( 'admin.php' === $pagenow && 'gs-ict-2fa-setup' === $page ) {
            return;
        }

        if ( 'admin-post.php' === $pagenow && 'gs_ict_enable_2fa' === $action ) {
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

        $recovery_result = self::store_new_recovery_codes( $user_id );
        if ( is_wp_error( $recovery_result ) ) {
            return $recovery_result;
        }

        update_user_meta( $user_id, self::META_SECRET, $encrypted );
        update_user_meta( $user_id, self::META_ENABLED, '1' );
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
        $stored = get_user_meta( (int) $user_id, self::META_RECOVERY_SHOW, true );
        if ( empty( $stored ) ) {
            return array();
        }

        if ( is_array( $stored ) ) {
            delete_user_meta( (int) $user_id, self::META_RECOVERY_SHOW );
            return $stored;
        }

        $json = GS_ICT_Crypto::decrypt( $stored );
        if ( false === $json ) {
            return array();
        }

        $codes = json_decode( $json, true );
        if ( ! is_array( $codes ) ) {
            return array();
        }

        delete_user_meta( (int) $user_id, self::META_RECOVERY_SHOW );
        return array_values( array_filter( array_map( 'sanitize_text_field', $codes ) ) );
    }

    public static function otpauth_uri( $user, $secret ) {
        $site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $account = $user->user_email ? $user->user_email : $user->user_login;
        $label   = rawurlencode( $site . ':' . $account );

        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode( $secret ) . '&issuer=' . rawurlencode( $site ) . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function verify_login_code( $user_id, $submitted ) {
        $rate_key = self::rate_key( $user_id );
        $attempts = (int) get_transient( $rate_key );

        if ( $attempts >= self::CHALLENGE_ATTEMPTS ) {
            self::audit_auth_failure( $user_id, 'rate_limited' );
            return new WP_Error( 'gs_ict_2fa_rate_limited', __( 'Te veel onjuiste 2FA-pogingen. Log opnieuw in en probeer het later nogmaals.', 'gs-ict' ) );
        }

        $submitted = trim( (string) $submitted );
        if ( '' === $submitted ) {
            self::bump_rate_limit( $rate_key, $attempts );
            self::audit_auth_failure( $user_id, 'missing_code' );
            return new WP_Error( 'gs_ict_2fa_required', __( 'Vul je authenticatorcode of herstelcode in.', 'gs-ict' ) );
        }

        $secret = self::get_secret( $user_id );
        if ( $secret ) {
            $step = GS_ICT_TOTP::verify( $secret, $submitted, 1 );
            if ( false !== $step ) {
                $last_step = (int) get_user_meta( $user_id, self::META_LAST_STEP, true );
                if ( $step <= $last_step ) {
                    self::bump_rate_limit( $rate_key, $attempts );
                    self::audit_auth_failure( $user_id, 'replayed_code' );
                    return new WP_Error( 'gs_ict_2fa_replayed', __( 'Deze authenticatorcode is al gebruikt. Wacht op een nieuwe code.', 'gs-ict' ) );
                }

                update_user_meta( $user_id, self::META_LAST_STEP, $step );
                delete_transient( $rate_key );
                return true;
            }
        }

        if ( self::consume_recovery_code( $user_id, $submitted ) ) {
            delete_transient( $rate_key );
            if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
                GS_ICT_Audit_Log::log( 'recovery_code_used', 'Een 2FA-herstelcode is gebruikt om in te loggen.', 'warning', array(), $user_id );
            }
            return true;
        }

        self::bump_rate_limit( $rate_key, $attempts );
        self::audit_auth_failure( $user_id, 'invalid_code' );

        return new WP_Error( 'gs_ict_2fa_invalid', __( 'De authenticatorcode of herstelcode is ongeldig.', 'gs-ict' ) );
    }

    private static function render_challenge_page( $token, $data, $error = null ) {
        $errors = new WP_Error();

        if ( $error instanceof WP_Error ) {
            foreach ( $error->get_error_messages() as $message ) {
                $errors->add( 'gs_ict_2fa', $message );
            }
        }

        login_header( __( 'Tweestapsverificatie', 'gs-ict' ), '', $errors );
        ?>
        <form name="gs-ict-2fa-form" id="gs-ict-2fa-form" action="<?php echo esc_url( site_url( 'wp-login.php?action=gs_ict_2fa', 'login_post' ) ); ?>" method="post" autocomplete="off">
            <p><?php esc_html_e( 'Voer de 6-cijferige code uit je authenticator-app in. Je kunt ook een eenmalige herstelcode gebruiken.', 'gs-ict' ); ?></p>
            <p>
                <label for="gs_ict_otp">
                    <?php esc_html_e( 'Authenticatiecode of herstelcode', 'gs-ict' ); ?><br>
                    <input type="text" name="gs_ict_otp" id="gs_ict_otp" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" autofocus required>
                </label>
            </p>
            <input type="hidden" name="challenge" value="<?php echo esc_attr( $token ); ?>">
            <?php wp_nonce_field( 'gs_ict_2fa_challenge_' . $token ); ?>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verifiëren', 'gs-ict' ); ?>">
            </p>
        </form>
        <p id="nav"><a href="<?php echo esc_url( wp_login_url() ); ?>">&larr; <?php esc_html_e( 'Terug naar inloggen', 'gs-ict' ); ?></a></p>
        <?php
        login_footer();
        exit;
    }

    private static function get_challenge( $token ) {
        if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $token ) ) {
            return false;
        }

        $data = get_transient( self::challenge_key( $token ) );
        if ( ! is_array( $data ) || empty( $data['user_id'] ) || empty( $data['created_at'] ) ) {
            return false;
        }

        if ( (int) $data['created_at'] + self::CHALLENGE_TTL < time() ) {
            delete_transient( self::challenge_key( $token ) );
            return false;
        }

        return $data;
    }

    private static function save_challenge( $token, $data ) {
        $created_at = isset( $data['created_at'] ) ? (int) $data['created_at'] : time();
        $remaining  = max( 1, ( $created_at + self::CHALLENGE_TTL ) - time() );
        set_transient( self::challenge_key( $token ), $data, $remaining );
    }

    private static function challenge_key( $token ) {
        return 'gsict_2fa_ch_' . hash( 'sha256', (string) $token );
    }

    private static function requested_redirect() {
        $requested = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';
        return wp_validate_redirect( $requested, admin_url() );
    }

    private static function is_interactive_login_request() {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return false;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return false;
        }

        if ( wp_doing_ajax() ) {
            return false;
        }

        return 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' )
            && isset( $_POST['log'], $_POST['pwd'] );
    }

    private static function store_new_recovery_codes( $user_id ) {
        $codes  = self::generate_recovery_codes( 8 );
        $hashes = array();

        foreach ( $codes as $recovery_code ) {
            $hashes[] = wp_hash_password( self::normalize_recovery_code( $recovery_code ) );
        }

        $encrypted_display = GS_ICT_Crypto::encrypt( wp_json_encode( $codes ) );
        if ( false === $encrypted_display ) {
            return new WP_Error( 'gs_ict_recovery_crypto', __( 'De herstelcodes konden niet veilig worden opgeslagen.', 'gs-ict' ) );
        }

        update_user_meta( (int) $user_id, self::META_RECOVERY, $hashes );
        update_user_meta( (int) $user_id, self::META_RECOVERY_SHOW, $encrypted_display );

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
        if ( 12 !== strlen( $normalized ) ) {
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
