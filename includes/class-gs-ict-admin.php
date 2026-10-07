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
               