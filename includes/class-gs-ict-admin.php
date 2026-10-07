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
            array( __CLASS__, 'overview_page' ),
            'dashicons-shield-alt',
            81
        );

        add_submenu_page(
            'gs-ict',
            __( 'Overzicht', 'gs-ict' ),
            __( 'Overzicht', 'gs-ict' ),
            'manage_options',
            'gs-ict',
            array( __CLASS__, 'overview_page' )
        );

        add_submenu_page(
            'gs-ict',
            __( '2FA', 'gs-ict' ),
            __( '2FA', 'gs-ict' ),
            'manage_options',
            'gs-ict-2fa',
            array( __CLASS__, 'two_factor_page' )
        );

        add_submenu_page(
            'gs-ict',
            __( 'Updates', 'gs-ict' ),
            __( 'Updates', 'gs-ict' ),
            'manage_options',
            'gs-ict-updates',
            array( __CLASS__, 'updates_page' )
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
        unset( $hook_suffix );

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $plugin_pages = array( 'gs-ict', 'gs-ict-2fa', 'gs-ict-updates', 'gs-ict-audit-log', 'gs-ict-2fa-setup' );

        if ( ! in_array( $page, $plugin_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'gs-ict-admin',
            plugins_url( 'assets/gs-ict-admin.css', GS_ICT_FILE ),
            array(),
            GS_ICT_VERSION
        );

        if ( in_array( $page, array( 'gs-ict-2fa', 'gs-ict-2fa-setup' ), true ) ) {
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
    }

    public static function action_links( $links ) {
        array_unshift(
            $links,
            '<a href="' . esc_url( admin_url( 'admin.php?page=gs-ict' ) ) . '">' . esc_html__( 'Overzicht', 'gs-ict' ) . '</a>'
        );
        return $links;
    }

    public static function overview_page() {
        self::require_manage_options();
        ?>
        <div class="wrap gs-ict-wrap">
            <?php self::page_header( __( 'Overzicht', 'gs-ict' ), __( 'Beveiliging en technisch beheer voor deze WordPress-installatie.', 'gs-ict' ) ); ?>
            <?php self::render_current_message(); ?>
            <?php GS_ICT_Dashboard::render_overview(); ?>
        </div>
        <?php
    }

    public static function two_factor_page() {
        self::require_manage_options();

        $current_user = wp_get_current_user();
        $enabled      = GS_ICT_Two_Factor::is_enabled( $current_user->ID );
        $recovery     = GS_ICT_Two_Factor::get_recovery_codes_for_display( $current_user->ID );
        ?>
        <div class="wrap gs-ict-wrap">
            <?php self::page_header( __( '2FA', 'gs-ict' ), __( 'Beheer tweestapsverificatie voor administratoraccounts.', 'gs-ict' ) ); ?>
            <?php self::render_current_message(); ?>
            <?php self::render_recovery_codes( $recovery ); ?>

            <div class="gs-ict-grid gs-ict-grid-2">
                <?php GS_ICT_Dashboard::render_two_factor_controls(); ?>
            </div>

            <div class="gs-ict-panel">
                <div class="gs-ict-panel-header">
                    <div>
                        <h2><?php esc_html_e( 'Mijn administratoraccount', 'gs-ict' ); ?></h2>
                        <p><?php esc_html_e( 'Beheer 2FA voor je huidige administratoraccount.', 'gs-ict' ); ?></p>
                    </div>
                </div>

                <?php if ( ! GS_ICT_Two_Factor::is_admin_user( $current_user ) ) : ?>
                    <p><?php esc_html_e( 'Je huidige account heeft niet de rol administrator.', 'gs-ict' ); ?></p>
                <?php elseif ( $enabled ) : ?>
                    <div class="gs-ict-state-row">
                        <span class="gs-ict-badge gs-ict-badge-success"><?php esc_html_e( '2FA actief', 'gs-ict' ); ?></span>
                        <span><?php esc_html_e( 'Bij het inloggen is een TOTP-code of herstelcode vereist.', 'gs-ict' ); ?></span>
                    </div>
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

            <div class="gs-ict-panel">
                <div class="gs-ict-panel-header">
                    <div>
                        <h2><?php esc_html_e( 'Administratoraccounts', 'gs-ict' ); ?></h2>
                        <p><?php esc_html_e( 'Bekijk de 2FA-status van alle administrators en verplicht 2FA waar nodig.', 'gs-ict' ); ?></p>
                    </div>
                </div>
                <?php self::render_admin_table( $current_user->ID ); ?>
            </div>
        </div>
        <?php
    }

    public static function updates_page() {
        self::require_manage_options();

        $maintenance = GS_ICT_Updates::next_maintenance_date();
        $days        = GS_ICT_Updates::days_until_maintenance();
        ?>
        <div class="wrap gs-ict-wrap">
            <?php self::page_header( __( 'Updates', 'gs-ict' ), __( 'Beheer het updatebeleid en de vaste maandelijkse onderhoudsdag.', 'gs-ict' ) ); ?>
            <?php self::render_current_message(); ?>

            <div class="gs-ict-panel">
                <div class="gs-ict-panel-header">
                    <div>
                        <h2><?php esc_html_e( 'Automatische updates', 'gs-ict' ); ?></h2>
                        <p><?php esc_html_e( 'Handmatige updates blijven altijd beschikbaar via WordPress.', 'gs-ict' ); ?></p>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="gs_ict_save_updates">
                    <?php wp_nonce_field( 'gs_ict_save_updates' ); ?>

                    <label class="gs-ict-checkbox-row">
                        <input type="checkbox" name="disable_auto_updates" value="1" <?php checked( GS_ICT_Updates::auto_updates_disabled() ); ?>>
                        <span>
                            <strong><?php esc_html_e( 'Schakel alle automatische WordPress-updates uit', 'gs-ict' ); ?></strong>
                            <small><?php esc_html_e( 'Dit blokkeert achtergrondupdates van WordPress core, plugins, thema’s en vertalingen.', 'gs-ict' ); ?></small>
                        </span>
                    </label>

                    <?php submit_button( __( 'Update-instellingen opslaan', 'gs-ict' ), 'primary', 'submit', false ); ?>
                </form>
            </div>

            <div class="gs-ict-panel gs-ict-maintenance-panel">
                <div class="gs-ict-maintenance-date"><?php echo esc_html( wp_date( 'd-m-Y', $maintenance->getTimestamp(), wp_timezone() ) ); ?></div>
                <div>
                    <h2><?php esc_html_e( 'Volgende onderhoudsdag', 'gs-ict' ); ?></h2>
                    <p>
                        <?php
                        if ( 0 === $days ) {
                            esc_html_e( 'Vandaag is de vaste onderhoudsdag.', 'gs-ict' );
                        } else {
                            echo esc_html(
                                sprintf(
                                    _n( 'Over %d dag is de vaste onderhoudsdag.', 'Over %d dagen is de vaste onderhoudsdag.', $days, 'gs-ict' ),
                                    $days
                                )
                            );
                        }
                        ?>
                    </p>
                    <p class="description"><?php esc_html_e( 'GS ICT plant handmatig onderhoud standaard op de eerste maandag van iedere maand.', 'gs-ict' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    public static function setup_page() {
        $current_user = wp_get_current_user();

        if ( ! GS_ICT_Two_Factor::is_admin_user( $current_user ) ) {
            wp_die( esc_html__( 'Deze 2FA-installatie is alleen beschikbaar voor administratoraccounts.', 'gs-ict' ) );
        }

        $enabled  = GS_ICT_Two_Factor::is_enabled( $current_user->ID );
        $recovery = GS_ICT_Two_Factor::get_recovery_codes_for_display( $current_user->ID );
        ?>
        <div class="wrap gs-ict-wrap gs-ict-setup-wrap">
            <?php self::page_header( __( 'Tweestapsverificatie instellen', 'gs-ict' ), __( 'Rond de 2FA-configuratie af om verder te gaan.', 'gs-ict' ) ); ?>
            <?php self::render_current_message(); ?>
            <?php self::render_recovery_codes( $recovery ); ?>

            <?php if ( $enabled ) : ?>
                <div class="gs-ict-panel">
                    <span class="gs-ict-badge gs-ict-badge-success"><?php esc_html_e( '2FA actief', 'gs-ict' ); ?></span>
                    <h2><?php esc_html_e( 'De configuratie is voltooid', 'gs-ict' ); ?></h2>
                    <p><?php esc_html_e( 'Vanaf je volgende login heb je je authenticatorcode nodig.', 'gs-ict' ); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Naar het dashboard', 'gs-ict' ); ?></a>
                </div>
            <?php else : ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php esc_html_e( '2FA is verplicht voor dit administratoraccount.', 'gs-ict' ); ?></strong> <?php esc_html_e( 'Rond onderstaande installatie af om verder te gaan naar het WordPress-dashboard.', 'gs-ict' ); ?></p>
                </div>
                <div class="gs-ict-panel">
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
        <ol class="gs-ict-steps">
            <li><?php esc_html_e( 'Open je authenticator-app.', 'gs-ict' ); ?></li>
            <li><?php esc_html_e( 'Scan de QR-code of voer de TOTP-sleutel handmatig in.', 'gs-ict' ); ?></li>
            <li><?php esc_html_e( 'Vul de actuele 6-cijferige code in om 2FA te activeren.', 'gs-ict' ); ?></li>
        </ol>

        <div class="gs-ict-setup-grid">
            <div>
                <div class="gs-ict-qrcode" data-otpauth="<?php echo esc_attr( $uri ); ?>"></div>
                <p class="description"><?php esc_html_e( 'De QR-code wordt lokaal in je browser opgebouwd.', 'gs-ict' ); ?></p>
            </div>
            <div>
                <p><strong><?php esc_html_e( 'Handmatige TOTP-sleutel', 'gs-ict' ); ?></strong></p>
                <code class="gs-ict-secret"><?php echo esc_html( $secret ); ?></code>
                <p class="description"><?php esc_html_e( 'TOTP · 6 cijfers · SHA-1 · 30 seconden', 'gs-ict' ); ?></p>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-ict-inline-form">
            <input type="hidden" name="action" value="gs_ict_enable_2fa">
            <input type="hidden" name="setup_context" value="<?php echo $forced ? 'forced' : 'settings'; ?>">
            <?php wp_nonce_field( 'gs_ict_enable_2fa' ); ?>
            <label for="totp_code">
                <strong><?php esc_html_e( 'Authenticatorcode', 'gs-ict' ); ?></strong>
                <input type="text" id="totp_code" name="totp_code" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
            </label>
            <?php submit_button( __( '2FA activeren', 'gs-ict' ), 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_recovery_codes( $recovery ) {
        if ( empty( $recovery ) ) {
            return;
        }
        ?>
        <div class="notice notice-success gs-ict-recovery-notice">
            <h2><?php esc_html_e( 'Bewaar je herstelcodes nu', 'gs-ict' ); ?></h2>
            <p><?php esc_html_e( 'Elke code kan één keer worden gebruikt. Bewaar ze buiten WordPress. Ze worden hierna niet opnieuw volledig getoond.', 'gs-ict' ); ?></p>
            <pre><?php echo esc_html( implode( "\n", $recovery ) ); ?></pre>
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
        <div class="gs-ict-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Gebruiker', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'E-mail', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( '2FA-status', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'Actie', 'gs-ict' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $users as $user ) : ?>
                    <?php
                    $enabled  = GS_ICT_Two_Factor::is_enabled( $user->ID );
                    $required = GS_ICT_Two_Factor::is_required( $user->ID );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $user->display_name ); ?></strong><br><span class="description"><?php echo esc_html( $user->user_login ); ?></span></td>
                        <td><?php echo esc_html( $user->user_email ); ?></td>
                        <td>
                            <?php if ( $enabled ) : ?>
                                <span class="gs-ict-badge gs-ict-badge-success"><?php esc_html_e( 'Aan', 'gs-ict' ); ?></span>
                            <?php elseif ( $required ) : ?>
                                <span class="gs-ict-badge gs-ict-badge-warning"><?php esc_html_e( 'Installatie vereist', 'gs-ict' ); ?></span>
                            <?php else : ?>
                                <span class="gs-ict-badge"><?php esc_html_e( 'Uit', 'gs-ict' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $enabled ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-ict-inline-action" onsubmit="return confirm('2FA uitschakelen voor deze gebruiker?');">
                                    <input type="hidden" name="action" value="gs_ict_disable_2fa">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                    <?php wp_nonce_field( 'gs_ict_disable_2fa_' . $user->ID ); ?>
                                    <button type="submit" class="button"><?php esc_html_e( 'Uitschakelen', 'gs-ict' ); ?></button>
                                </form>
                            <?php elseif ( $required ) : ?>
                                <?php if ( (int) $user->ID === (int) $current_user_id ) : ?>
                                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=gs-ict-2fa-setup' ) ); ?>"><?php esc_html_e( 'Nu instellen', 'gs-ict' ); ?></a>
                                <?php elseif ( GS_ICT_Dashboard::require_all_admins() ) : ?>
                                    <span class="description"><?php esc_html_e( 'Globaal verplicht', 'gs-ict' ); ?></span>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-ict-inline-action">
                                        <input type="hidden" name="action" value="gs_ict_cancel_required_2fa">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
                                        <?php wp_nonce_field( 'gs_ict_cancel_required_2fa_' . $user->ID ); ?>
                                        <button type="submit" class="button"><?php esc_html_e( 'Verplichting annuleren', 'gs-ict' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ( (int) $user->ID === (int) $current_user_id ) : ?>
                                <a class="button" href="#totp_code"><?php esc_html_e( 'Instellen', 'gs-ict' ); ?></a>
                            <?php else : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gs-ict-inline-action">
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
        </div>
        <?php
    }

    public static function save_updates() {
        self::require_manage_options();
        check_admin_referer( 'gs_ict_save_updates' );

        $disabled = isset( $_POST['disable_auto_updates'] ) ? '1' : '0';
        update_option( 'gs_ict_disable_auto_updates', $disabled );

        GS_ICT_Audit_Log::log(
            'update_policy_changed',
            'GS ICT update-instellingen gewijzigd.',
            'warning',
            array( 'automatic_updates_disabled' => '1' === $disabled )
        );

        self::redirect_to( 'gs-ict-updates', 'updates_saved' );
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
            self::redirect_to( 'gs-ict-2fa', '2fa_error' );
        }

        if ( 'forced' === $context ) {
            self::redirect_setup( '2fa_enabled' );
        }

        self::redirect_to( 'gs-ict-2fa', '2fa_enabled' );
    }

    public static function require_2fa() {
        self::require_manage_options();

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            self::redirect_to( 'gs-ict-2fa', '2fa_error' );
        }

        check_admin_referer( 'gs_ict_require_2fa_' . $user_id );
        $result = GS_ICT_Two_Factor::require_setup( $user_id );

        self::redirect_to( 'gs-ict-2fa', is_wp_error( $result ) ? '2fa_error' : '2fa_required' );
    }

    public static function cancel_required_2fa() {
        self::require_manage_options();

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            self::redirect_to( 'gs-ict-2fa', '2fa_error' );
        }

        check_admin_referer( 'gs_ict_cancel_required_2fa_' . $user_id );

        $user = get_userdata( $user_id );
        if ( ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            self::redirect_to( 'gs-ict-2fa', '2fa_error' );
        }

        $result = GS_ICT_Two_Factor::cancel_required_setup( $user_id );
        self::redirect_to( 'gs-ict-2fa', is_wp_error( $result ) ? '2fa_global_required' : '2fa_requirement_cancelled' );
    }

    public static function disable_2fa() {
        self::require_manage_options();

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $user_id ) {
            self::redirect_to( 'gs-ict-2fa', '2fa_error' );
        }

        check_admin_referer( 'gs_ict_disable_2fa_' . $user_id );

        $user = get_userdata( $user_id );
        if ( ! GS_ICT_Two_Factor::is_admin_user( $user ) ) {
            self::redirect_to( 'gs-ict-2fa', '2fa_error' );
        }

        GS_ICT_Two_Factor::disable( $user_id );

        self::redirect_to(
            'gs-ict-2fa',
            GS_ICT_Dashboard::require_all_admins() ? '2fa_reconfigure_required' : '2fa_disabled'
        );
    }

    private static function page_header( $title, $description ) {
        ?>
        <div class="gs-ict-page-header">
            <div>
                <h1>GS ICT <span><?php echo esc_html( $title ); ?></span></h1>
                <p><?php echo esc_html( $description ); ?></p>
            </div>
            <span class="gs-ict-version">v<?php echo esc_html( GS_ICT_VERSION ); ?></span>
        </div>
        <?php
    }

    private static function render_current_message() {
        $message = isset( $_GET['gsict_message'] ) ? sanitize_key( wp_unslash( $_GET['gsict_message'] ) ) : '';
        self::render_message( $message );
    }

    private static function redirect_to( $page, $message ) {
        wp_safe_redirect(
            add_query_arg(
                'gsict_message',
                sanitize_key( $message ),
                admin_url( 'admin.php?page=' . sanitize_key( $page ) )
            )
        );
        exit;
    }

    private static function redirect_setup( $message ) {
        self::redirect_to( 'gs-ict-2fa-setup', $message );
    }

    private static function require_manage_options() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Je hebt onvoldoende rechten om deze pagina te bekijken.', 'gs-ict' ) );
        }
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
