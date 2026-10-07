<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_gs_ict_save_updates', array( __CLASS__, 'save_updates' ) );
        add_action( 'admin_post_gs_ict_enable_2fa', array( __CLASS__, 'enable_2fa' ) );
        add_action( 'admin_post_gs_ict_require_2fa', array( __CLASS__, 'require_2fa' ) );
        add_action( 'admin_post_gs_ict_cancel_required_2fa', array( __CLASS__, 'cancel_required_2fa' ) );
        add_action( 'admin_post_gs_ict_disable_2fa', array( __CLASS__, 'disable_2fa' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( GS_ICT_FILE ), array( __CLASS__, 'action_links' ) );
    }

    public static function menu() {
        add_menu_page(
            'GS ICT',
            'GS ICT',
            'manage_options',
            'gs-ict',
            array( __CLASS__, 'page' ),
            'dashicons-shield-alt',
            81
        );

        add_submenu_page(
            null,
            __( '2FA instellen', 'gs-ict' ),
            __( '2FA instellen', 'gs-ict' ),
            'read',
            'gs-ict-2fa-setup',
            array( __CLASS__, 'setup_page' )
        );
    }

    public static function enqueue_assets( $hook_suffix ) {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! in_array( $page, array( 'gs-ict', 'gs-ict-2fa-setup' ), true ) ) {
            return;
        }

        // QRCode.js is alleen een statische client-side library. De TOTP-sleutel wordt niet naar de CDN gestuurd.
        wp_enqueue_script(
            'gs-ict-qrcode',
            'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
            array(),
            '1.0.0',
            true
        );
        wp_enqueue_script(
            'gs-ict-2fa',
            plugins_url( 'assets/gs-ict-2fa.js', GS_ICT_FILE ),
            array( 'gs-ict-qrcode' ),
            GS_ICT_VERSION,
            true
        );
    }

    public static function action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=gs-ict' ) ) . '">' . esc_html__( 'Instellingen', 'gs-ict' ) . '</a>' );
        return $links;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'gs-ict' ) );
        }

        $current_user = wp_get_current_user();
        $enabled      = GS_ICT_Two_Factor::is_enabled( $current_user->ID );
        $recovery     = GS_ICT_Two_Factor::get_recovery_codes_for_display( $current_user->ID );
        $message      = isset( $_GET['gsict_message'] ) ? sanitize_key( wp_unslash( $_GET['gsict_message'] ) ) : '';
        ?>
        <div class="wrap">
            <h1>GS ICT</h1>
            <p><?php esc_html_e( 'Beveiliging en technisch beheer voor deze WordPress-installatie.', 'gs-ict' ); ?></p>

            <?php self::render_message( $message ); ?>
            <?php self::render_recovery_codes( $recovery ); ?>
            <?php GS_ICT_Dashboard::render(); ?>

            <div class="card" style="max-width:900px">
                <h2><?php esc_html_e( 'Automatische updates', 'gs-ict' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gs_ict_save_updates">
                    <?php wp_nonce_field( 'gs_ict_save_updates' ); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="disable_auto_updates" value="1" <?php checked( GS_ICT_Updates::auto_updates_disabled() ); ?>>
                            <strong><?php esc_html_e( 'Schakel alle automatische WordPress-updates uit', 'gs-ict' ); ?></strong>
                        </label>
                    </p>
                    <p class="description"><?php esc_html_e( 'Dit blokkeert achtergrondupdates van WordPress core, plugins, thema’s en vertalingen. Handmatig updaten blijft mogelijk.', 'gs-ict' ); ?></p>
                    <p>
                        <label for="planned_update_date"><strong><?php esc_html_e( 'Geplande datum voor handmatig onderhoud', 'gs-ict' ); ?></strong></label><br>
                        <input type="date" id="planned_update_date" name="planned_update_date" value="<?php echo esc_attr( get_option( 'gs_ict_planned_update_date', '' ) ); ?>">
                    </p>
                    <p class="description"><?php esc_html_e( 'Op of na deze datum toont GS ICT een herinnering. De plugin voert updates niet automatisch uit.', 'gs-ict' ); ?></p>
                    <?php submit_button( __( 'Update-instellingen opslaan', 'gs-ict' ) ); ?>
                </form>
            </div>

            <div class="card" style="max-width:900px">
                <h2><?php esc_html_e( '2FA voor mijn administratoraccount', 'gs-ict' ); ?></h2>
                <?php if ( ! GS_ICT_Two_Factor::is_admin_user( $current_user ) ) : ?>
                    <p><?php esc_html_e( 'Je huidige account heeft niet de rol administrator.', 'gs-ict' ); ?></p>
                <?php elseif ( $enabled ) : ?>
                    <p><strong><?php esc_html_e( 'Status: ingeschakeld', 'gs-ict' ); ?></strong></p>
                    <p><?php esc_html_e( 'Bij het inloggen moet je naast je wachtwoord een 6-cijferige TOTP-code of een herstelcode invoeren.', 'gs-ict' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('2FA echt uitschakelen voor je account?');">
                        <input type="hidden" name="action" value="gs_ict_disable_2fa">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $current_user->ID ); ?>">
                        <?php wp_nonce_field( 'gs_ict_disable_2fa_' . $current_user->ID ); ?>
                        <?php submit_button( __( '2FA uitschakelen', 'gs-ict' ), 'secondary', 'submit', false ); ?>
                    </form>
                <?php else : ?>
                    <?php self::render_setup_form( $current_user, false ); ?>
                <?php endif; ?>
            </div>

            <div class="card" style="max-width:1100px">
                <h2><?php esc_html_e( 'Administratoraccounts', 'gs-ict' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Je kunt 2FA voor een andere administrator verplichten. Bij de eerstvolgende login wordt die gebruiker naar een apart installatiescherm gestuurd om zelf de QR-code te scannen en de configuratie te bevestigen.', 'gs-ict' ); ?></p>
                <?php self::render_admin_table( $current_user->ID ); ?>
            </div>
        </div>
        <?php
    }

    public static function setup_page() {
        $current_user = wp_get_current_user();
        if ( ! GS_ICT_Two_Factor::is_admin_user( $current_user ) ) {
            wp_die( esc_html__( 'Deze 2FA-installatie is alleen beschikbaar voor administratoraccounts.', 'gs-ict' ) );
        }

        $message  = isset( $_GET['gsict_message'] ) ? sanitize_key( wp_unslash( $_GET['gsict_message'] ) ) : '';
        $enabled  = GS_ICT_Two_Factor::is_enabled( $current_user->ID );
        $recovery = GS_ICT_Two_Factor::get_recovery_codes_for_display( $current_user->ID );
        ?>
        <div class="wrap" style="max-width:850px">
            <h1><?php esc_html_e( 'GS ICT – Tweestapsverificatie instellen', 'gs-ict' ); ?></h1>
            <?php self::render_message( $message ); ?>
            <?php self::render_recovery_codes( $recovery ); ?>

            <?php if ( $enabled ) : ?>
                <div class="card" style="max-width:800px">
                    <h2><?php esc_html_e( '2FA is actief', 'gs-ict' ); ?></h2>
                    <p><?php esc_html_e( 'De configuratie is voltooid. Vanaf je volgende login heb je je authenticatorcode nodig.', 'gs-ict' ); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Naar het dashboard', 'gs-ict' ); ?></a></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php esc_html_e( '2FA is verplicht voor dit administratoraccount.', 'gs-ict' ); ?></strong> <?php esc_html_e( 'Rond onderstaande installatie af om verder te gaan naar het WordPress-dashboard.', 'gs-ict' ); ?></p>
                </div>
                <div class="card" style="max-width:800px">
                    <?php self::render_setup_form( $current_user, true ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_setup_form( $user, $forced ) {
        $secret = GS_ICT_Two_Factor::get_or_create_pending_secret( $user->ID );
        if ( ! $secret ) {
            echo '<p>' . esc_html__( '2FA kan niet worden geactiveerd omdat de server geen ondersteunde versleuteling aanbiedt.', 'gs-ict' ) . '</p>';
            return;
        }
        $uri = GS_ICT_Two_Factor::otpauth_uri( $user, $secret );
        ?>
        <h2><?php echo $forced ? esc_html__( 'Authenticator koppelen', 'gs-ict' ) : esc_html__( 'Authenticator instellen', 'gs-ict' ); ?></h2>
        <ol>
            <li><?php esc_html_e( 'Open je authenticator-app, bijvoorbeeld Microsoft Authenticator, Google Authenticator, 1Password of Authy.', 'gs-ict' ); ?></li>
            <li><?php esc_html_e( 'Scan de QR-code hieronder. Je kunt ook de geheime sleutel handmatig invoeren.', 'gs-ict' ); ?></li>
            <li><?php esc_html_e( 'Vul daarna de actuele 6-cijferige code uit je authenticator-app in.', 'gs-ict' ); ?></li>
        </ol>

        <div style="display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap;margin:20px 0">
            <div>
                <div class="gs-ict-qrcode" data-otpauth="<?php echo esc_attr( $uri ); ?>" style="width:220px;min-height:220px;background:#fff;padding:10px;border:1px solid #dcdcde"></div>
                <p class="description"><?php esc_html_e( 'De QR-code wordt lokaal in je browser opgebouwd.', 'gs-ict' ); ?></p>
            </div>
            <div style="min-width:280px;max-width:460px">
                <p><strong><?php esc_html_e( 'Handmatige TOTP-sleutel', 'gs-ict' ); ?></strong></p>
                <p><code style="font-size:16px;user-select:all;word-break:break-all"><?php echo esc_html( $secret ); ?></code></p>
                <p class="description"><?php esc_html_e( 'Instellingen: TOTP, 6 cijfers, SHA-1, periode 30 seconden.', 'gs-ict' ); ?></p>
                <details>
                    <summary><?php esc_html_e( 'otpauth-URI tonen', 'gs-ict' ); ?></summary>
                    <code style="word-break:break-all;user-select:all"><?php echo esc_html( $uri ); ?></code>
                </details>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px">
            <input type="hidden" name="action" value="gs_ict_enable_2fa">
            <input type="hidden" name="setup_context" value="<?php echo $forced ? 'forced' : 'settings'; ?>">
            <?php wp_nonce_field( 'gs_ict_enable_2fa' ); ?>
            <label for="totp_code"><strong><?php esc_html_e( 'Authenticatorcode', 'gs-ict' ); ?></strong></label><br>
            <input type="text" id="totp_code" name="totp_code" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required style="font-size:20px;letter-spacing:3px;width:150px">
            <?php submit_button( __( '2FA activeren', 'gs-ict' ), 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_recovery_codes( $recovery ) {
        if ( empty( $recovery ) ) {
            return;
        }
        ?>
        <div class="notice notice-success">
            <h2><?php esc_html_e( 'Bewaar je herstelcodes nu', 'gs-ict' ); ?></h2>
            <p><?php esc_html_e( 'Elke code kan één keer worden gebruikt. Bewaar ze buiten WordPress, bijvoorbeeld in een wachtwoordmanager. Ze worden hierna niet opnieuw volledig getoond.', 'gs-ict' ); ?></p>
            <pre style="font-size:15px;line-height:1.7"><?php echo esc_html( implode( "\n", $recovery ) ); ?></pre>
        </div>
        <?php
    }

    private static function render_admin_table( $current_user_id ) {
        $users = get_users(
            array(
                'role'    => 'administrator',
                'orderby' => 'display_name',
                'order'   => 'ASC',
            )
        );
        ?>
        <table class="widefat striped" style="margin-top:16px">
            <thead><tr><th><?php esc_html_e( 'Gebruiker', 'gs-ict' ); ?></th><th><?php esc_html_e( 'E-mail', 'gs-ict' ); ?></th><th><?php esc_html_e( '2FA-status', 'gs-ict' ); ?></th><th><?php esc_html_e( 'Actie', 'gs-ict' ); ?></th></tr></thead>
            <tbody>
                <?php foreach ( $users as $user ) : ?>
                    <?php
                    $enabled  = GS_ICT_Two_Factor::is_enabled( $user->ID );
                    $required = GS_ICT_Two_Factor::is_required( $user->ID );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></td>
                        <td><?php echo esc_html( $user->user_email ); ?></td>
                        <td>
                            <?php if ( $enabled ) : ?>
                                <strong><?php esc_html_e( 'Aan', 'gs-ict' ); ?></strong>
                            <?php elseif ( $required ) : ?>
                                <strong><?php esc_html_e( 'Installatie vereist', 'gs-ict' ); ?></strong>
                            <?php else : ?>
                                <?php esc_html_e( 'Uit', 'gs-ict' ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $enabled ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('2FA uitschakelen voor deze gebruiker?');">
                                    <input type="hidden" name="action" value="gs_ict_disable_2fa">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                    <?php wp_nonce_field( 'gs_ict_disable_2fa_' . $user->ID ); ?>
                                    <button type="submit" class="button"><?php esc_html_e( 'Uitschakelen', 'gs-ict' ); ?></button>
                                </form>
                            <?php elseif ( $required ) : ?>
                                <?php if ( (int) $user->ID === (int) $current_user_id ) : ?>
                                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=gs-ict-2fa-setup' ) ); ?>"><?php esc_html_e( 'Nu instellen', 'gs-ict' ); ?></a>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Verplichte 2FA-installatie voor deze gebruiker annuleren?');">
                                        <input type="hidden" name="action" value="gs_ict_cancel_required_2fa">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                        <?php wp_nonce_field( 'gs_ict_cancel_required_2fa_' . $user->ID ); ?>
                                        <button type="submit" class="button"><?php esc_html_e( 'Verplichting annuleren', 'gs-ict' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ( (int) $user->ID === (int) $current_user_id ) : ?>
                                <a class="button" href="#totp_code"><?php esc_html_e( 'Hierboven instellen', 'gs-ict' ); ?></a>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="gs_ict_require_2fa">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                    <?php wp_nonce_field( 'gs_ict_require_2fa_' . $user->ID ); ?>
                                    <button type="submit" class="button button-primary"><?php esc_html_e( '2FA verplichten', 'gs-ict' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function save_updates() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }
        check_admin_referer( 'gs_ict_save_updates' );

        update_option( 'gs_ict_disable_auto_updates', isset( $_POST['disable_auto_updates'] ) ? '1' : '0' );

        $date = isset( $_POST['planned_update_date'] ) ? sanitize_text_field( wp_unslash( $_POST['planned_update_date'] ) ) : '';
        if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = '';
        }
        update_option( 'gs_ict_planned_update_date', $date );

        GS_ICT_Audit_Log::log(
            'update_policy_changed',
            'GS ICT update-instellingen gewijzigd.',
            'warning',
            array(
                'automatic_updates_disabled' => isset( $_POST['disable_auto_updates'] ),
                'planned_update_date' => $date,
            )
        );

        self::redirect( 'updates_saved' );
    }

    public static function enable_2fa() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }
        check_admin_referer( 'gs_ict_enable_2fa' );

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );
        if ( ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            wp_die( esc_html__( '2FA kan hier alleen voor administratoraccounts worden ingesteld.', 'gs-ict' ) );
        }

        $code    = isset( $_POST['totp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['totp_code'] ) ) : '';
        $context = isset( $_POST['setup_context'] ) ? sanitize_key( wp_unslash( $_POST['setup_context'] ) ) : 'settings';
        $result  = GS_ICT_Two_Factor::enable( $user_id, $code );

        if ( is_wp_error( $result ) ) {
            if ( 'forced' === $context ) {
                self::redirect_setup( '2fa_error' );
            }
            self::redirect( '2fa_error' );
        }

        if ( 'forced' === $context ) {
            self::redirect_setup( '2fa_enabled' );
        }
        self::redirect( '2fa_enabled' );
    }

    public static function require_2fa() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            self::redirect( '2fa_error' );
        }
        check_admin_referer( 'gs_ict_require_2fa_' . $user_id );

        $result = GS_ICT_Two_Factor::require_setup( $user_id );
        if ( is_wp_error( $result ) ) {
            self::redirect( '2fa_error' );
        }
        self::redirect( '2fa_required' );
    }

    public static function cancel_required_2fa() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            self::redirect( '2fa_error' );
        }
        check_admin_referer( 'gs_ict_cancel_required_2fa_' . $user_id );

        $user = get_userdata( $user_id );
        if ( ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            self::redirect( '2fa_error' );
        }

        $result = GS_ICT_Two_Factor::cancel_required_setup( $user_id );
        if ( is_wp_error( $result ) ) {
            self::redirect( '2fa_global_required' );
        }
        self::redirect( '2fa_requirement_cancelled' );
    }

    public static function disable_2fa() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            self::redirect( '2fa_error' );
        }

        check_admin_referer( 'gs_ict_disable_2fa_' . $user_id );
        $user = get_userdata( $user_id );
        if ( ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            self::redirect( '2fa_error' );
        }

        GS_ICT_Two_Factor::disable( $user_id );
        if ( GS_ICT_Dashboard::require_all_admins() ) {
            self::redirect( '2fa_reconfigure_required' );
        }
        self::redirect( '2fa_disabled' );
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

    private static function redirect_setup( $message ) {
        wp_safe_redirect(
            add_query_arg(
                'gsict_message',
                sanitize_key( $message ),
                admin_url( 'admin.php?page=gs-ict-2fa-setup' )
            )
        );
        exit;
    }

    private static function render_message( $message ) {
        $messages = array(
            'updates_saved'             => array( 'success', __( 'Update-instellingen zijn opgeslagen.', 'gs-ict' ) ),
            '2fa_enabled'               => array( 'success', __( '2FA is ingeschakeld. Bewaar de herstelcodes die hieronder eenmalig worden getoond.', 'gs-ict' ) ),
            '2fa_required'              => array( 'success', __( '2FA is verplicht gemaakt. Deze administrator krijgt bij de eerstvolgende login het installatiescherm te zien.', 'gs-ict' ) ),
            '2fa_requirement_cancelled' => array( 'warning', __( 'De verplichte 2FA-installatie is geannuleerd voor het gekozen account.', 'gs-ict' ) ),
            '2fa_disabled'              => array( 'warning', __( '2FA is uitgeschakeld voor het gekozen account.', 'gs-ict' ) ),
            '2fa_reconfigure_required'  => array( 'warning', __( '2FA is uitgeschakeld, maar het globale beleid verplicht deze administrator om 2FA opnieuw in te stellen.', 'gs-ict' ) ),
            '2fa_global_required'       => array( 'error', __( 'Deze 2FA-verplichting kan niet worden geannuleerd zolang het globale 2FA-beleid actief is.', 'gs-ict' ) ),
            'security_policy_saved'     => array( 'success', __( 'Het 2FA-beleid is opgeslagen.', 'gs-ict' ) ),
            'recovery_regenerated'      => array( 'success', __( 'Nieuwe herstelcodes zijn aangemaakt. Bewaar de hieronder getoonde codes direct; de oude codes zijn ongeldig.', 'gs-ict' ) ),
            'recovery_error'            => array( 'error', __( 'De herstelcodes konden niet worden vernieuwd. Controleer je authenticatorcode en probeer opnieuw.', 'gs-ict' ) ),
            '2fa_error'                 => array( 'error', __( 'De 2FA-wijziging kon niet worden uitgevoerd. Controleer de code en probeer opnieuw.', 'gs-ict' ) ),
        );

        if ( isset( $messages[ $message ] ) ) {
            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                esc_attr( $messages[ $message ][0] ),
                esc_html( $messages[ $message ][1] )
            );
        }
    }
}
