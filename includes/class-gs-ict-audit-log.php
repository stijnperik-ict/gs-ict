<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Audit_Log {
    const DB_VERSION = '1';
    const RETENTION_DAYS = 90;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
        add_action( 'wp_login', array( __CLASS__, 'log_login' ), 10, 2 );
        add_action( 'wp_login_failed', array( __CLASS__, 'log_failed_login' ), 10, 2 );
        add_action( 'activated_plugin', array( __CLASS__, 'log_plugin_activated' ), 10, 2 );
        add_action( 'deactivated_plugin', array( __CLASS__, 'log_plugin_deactivated' ), 10, 2 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'log_upgrade' ), 20, 2 );
        add_action( 'set_user_role', array( __CLASS__, 'log_role_change' ), 20, 3 );
        add_action( 'delete_user', array( __CLASS__, 'log_user_deleted' ), 10, 3 );
        self::maybe_cleanup();
    }

    public static function maybe_install() {
        if ( self::DB_VERSION === get_option( 'gs_ict_audit_db_version' ) ) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(100) NOT NULL,
            severity varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            context longtext NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY user_id (user_id),
            KEY event (event)
        ) {$charset_collate};";

        dbDelta( $sql );
        update_option( 'gs_ict_audit_db_version', self::DB_VERSION, false );
    }

    public static function log( $event, $message, $severity = 'info', $context = array(), $user_id = null ) {
        global $wpdb;

        self::maybe_install();

        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $wpdb->insert(
            self::table_name(),
            array(
                'created_at' => current_time( 'mysql', true ),
                'user_id'    => absint( $user_id ),
                'event'      => sanitize_key( $event ),
                'severity'   => in_array( $severity, array( 'info', 'warning', 'critical' ), true ) ? $severity : 'info',
                'message'    => sanitize_text_field( $message ),
                'ip_address' => self::current_ip(),
                'context'    => empty( $context ) ? null : wp_json_encode( $context ),
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public static function menu() {
        add_submenu_page(
            'gs-ict',
            __( 'Beveiligingslogboek', 'gs-ict' ),
            __( 'Beveiligingslogboek', 'gs-ict' ),
            'manage_options',
            'gs-ict-audit-log',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Onvoldoende rechten.', 'gs-ict' ) );
        }

        global $wpdb;

        $per_page = 50;
        $page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
        $offset = ( $page - 1 ) * $per_page;
        $table = self::table_name();

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        $pages = max( 1, (int) ceil( $total / $per_page ) );
        ?>
        <div class="wrap gs-ict-wrap">
            <div class="gs-ict-page-header">
                <div>
                    <h1>GS ICT <span><?php esc_html_e( 'Beveiligingslogboek', 'gs-ict' ); ?></span></h1>
                    <p><?php echo esc_html( sprintf( __( 'Beveiligingsgebeurtenissen worden maximaal %d dagen bewaard.', 'gs-ict' ), self::RETENTION_DAYS ) ); ?></p>
                </div>
                <span class="gs-ict-version">v<?php echo esc_html( GS_ICT_VERSION ); ?></span>
            </div>
            <div class="gs-ict-panel">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Datum', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'Niveau', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'Gebruiker', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'Gebeurtenis', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'Omschrijving', 'gs-ict' ); ?></th>
                        <th><?php esc_html_e( 'IP-adres', 'gs-ict' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'Nog geen gebeurtenissen geregistreerd.', 'gs-ict' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $user = $row->user_id ? get_userdata( (int) $row->user_id ) : false;
                        $user_label = $user ? $user->user_login : ( $row->user_id ? '#' . (int) $row->user_id : '—' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'd-m-Y H:i:s' ) ); ?></td>
                            <td><strong><?php echo esc_html( strtoupper( $row->severity ) ); ?></strong></td>
                            <td><?php echo esc_html( $user_label ); ?></td>
                            <td><code><?php echo esc_html( $row->event ); ?></code></td>
                            <td><?php echo esc_html( $row->message ); ?></td>
                            <td><code><?php echo esc_html( $row->ip_address ?: '—' ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(
                        paginate_links(
                            array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $page,
                                'total'     => $pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            )
                        )
                    );
                    ?>
                </div></div>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public static function count_recent( $days = 7 ) {
        global $wpdb;
        $days = max( 1, absint( $days ) );
        $since = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table_name() . ' WHERE created_at >= %s',
                $since
            )
        );
    }

    public static function log_login( $user_login, $user ) {
        if ( $user instanceof WP_User ) {
            self::log( 'login_success', sprintf( 'Succesvolle login voor %s.', $user_login ), 'info', array(), $user->ID );
        }
    }

    public static function log_failed_login( $username, $error = null ) {
        unset( $error );
        self::log(
            'login_failed',
            sprintf( 'Mislukte loginpoging voor gebruikersnaam %s.', sanitize_user( $username ) ),
            'warning',
            array(),
            0
        );
    }

    public static function log_plugin_activated( $plugin, $network_wide ) {
        self::log( 'plugin_activated', sprintf( 'Plugin geactiveerd: %s.', $plugin ), 'info', array( 'network_wide' => (bool) $network_wide ) );
    }

    public static function log_plugin_deactivated( $plugin, $network_wide ) {
        self::log( 'plugin_deactivated', sprintf( 'Plugin gedeactiveerd: %s.', $plugin ), 'warning', array( 'network_wide' => (bool) $network_wide ) );
    }

    public static function log_upgrade( $upgrader, $options ) {
        unset( $upgrader );

        if ( empty( $options['type'] ) || empty( $options['action'] ) ) {
            return;
        }

        $targets = array();
        if ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
            $targets = array_values( $options['plugins'] );
        } elseif ( ! empty( $options['themes'] ) && is_array( $options['themes'] ) ) {
            $targets = array_values( $options['themes'] );
        }

        self::log(
            'update_completed',
            sprintf( '%s-update uitgevoerd (%s).', ucfirst( sanitize_key( $options['type'] ) ), sanitize_key( $options['action'] ) ),
            'info',
            array( 'targets' => $targets )
        );
    }

    public static function log_role_change( $user_id, $role, $old_roles ) {
        self::log(
            'user_role_changed',
            sprintf( 'Gebruikersrol gewijzigd naar %s.', $role ?: 'geen rol' ),
            in_array( 'administrator', (array) $old_roles, true ) || 'administrator' === $role ? 'warning' : 'info',
            array( 'old_roles' => array_values( (array) $old_roles ), 'new_role' => $role ),
            $user_id
        );
    }

    public static function log_user_deleted( $user_id, $reassign, $user ) {
        unset( $reassign );
        $label = $user instanceof WP_User ? $user->user_login : '#' . (int) $user_id;
        self::log( 'user_deleted', sprintf( 'Gebruiker verwijderd: %s.', $label ), 'warning', array(), get_current_user_id() );
    }

    private static function maybe_cleanup() {
        if ( get_site_transient( 'gs_ict_audit_cleanup' ) ) {
            return;
        }

        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * self::RETENTION_DAYS ) );
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s',
                $cutoff
            )
        );
        set_site_transient( 'gs_ict_audit_cleanup', '1', DAY_IN_SECONDS );
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'gs_ict_audit_log';
    }

    private static function current_ip() {
        if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return '';
        }

        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }
}
