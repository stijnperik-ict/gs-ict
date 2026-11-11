<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Extra_Code {
    const OPTION = 'gs_ict_extra_code_snippets';

    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'render_frontend_head' ), 99 );
        add_action( 'admin_head', array( __CLASS__, 'render_admin_head' ), 99 );
        add_action( 'admin_post_gs_ict_save_extra_code', array( __CLASS__, 'save_snippet' ) );
        add_action( 'admin_post_gs_ict_delete_extra_code', array( __CLASS__, 'delete_snippet' ) );
        add_action( 'admin_post_gs_ict_toggle_extra_code', array( __CLASS__, 'toggle_snippet' ) );
    }

    public static function get_snippets() {
        $snippets = get_option( self::OPTION, array() );
        if ( ! is_array( $snippets ) ) {
            return array();
        }

        uasort(
            $snippets,
            static function ( $a, $b ) {
                return (int) ( $a['created_at'] ?? 0 ) <=> (int) ( $b['created_at'] ?? 0 );
            }
        );

        return $snippets;
    }

    public static function get_snippet( $id ) {
        $snippets = self::get_snippets();
        return isset( $snippets[ $id ] ) && is_array( $snippets[ $id ] ) ? $snippets[ $id ] : null;
    }

    public static function render_frontend_head() {
        if ( is_admin() ) {
            return;
        }
        self::render_location( 'frontend' );
    }

    public static function render_admin_head() {
        self::render_location( 'admin' );
    }

    private static function render_location( $location ) {
        foreach ( self::get_snippets() as $snippet ) {
            if ( empty( $snippet['enabled'] ) || $location !== ( $snippet['location'] ?? '' ) ) {
                continue;
            }

            $type = $snippet['type'] ?? '';
            $code = isset( $snippet['code'] ) ? (string) $snippet['code'] : '';
            if ( '' === trim( $code ) ) {
                continue;
            }

            echo "\n<!-- GS ICT Extra code: " . esc_html( $snippet['name'] ?? 'Snippet' ) . " -->\n";

            if ( 'css' === $type ) {
                echo "<style id=\"gs-ict-extra-" . esc_attr( $snippet['id'] ) . "\">\n";
                echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted unfiltered_html administrators only.
                echo "\n</style>\n";
            } elseif ( 'js' === $type ) {
                echo "<script id=\"gs-ict-extra-" . esc_attr( $snippet['id'] ) . "\">\n";
                echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted unfiltered_html administrators only.
                echo "\n</script>\n";
            } elseif ( 'html' === $type ) {
                echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional raw head HTML for trusted administrators.
                echo "\n";
            }
        }
    }

    public static function save_snippet() {
        self::require_code_capability();
        check_admin_referer( 'gs_ict_save_extra_code' );

        $id       = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
        $name     = isset( $_POST['snippet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['snippet_name'] ) ) : '';
        $type     = isset( $_POST['snippet_type'] ) ? sanitize_key( wp_unslash( $_POST['snippet_type'] ) ) : '';
        $location = isset( $_POST['snippet_location'] ) ? sanitize_key( wp_unslash( $_POST['snippet_location'] ) ) : '';
        $code     = isset( $_POST['snippet_code'] ) ? wp_unslash( $_POST['snippet_code'] ) : '';
        $enabled  = isset( $_POST['snippet_enabled'] ) ? 1 : 0;

        if ( '' === $name || ! in_array( $type, array( 'css', 'js', 'html' ), true ) || ! in_array( $location, array( 'frontend', 'admin' ), true ) ) {
            self::redirect( 'extra_code_error' );
        }

        $snippets = self::get_snippets();
        $now      = time();

        if ( '' === $id || ! isset( $snippets[ $id ] ) ) {
            $id = 'snippet_' . str_replace( '-', '', wp_generate_uuid4() );
            $created_at = $now;
        } else {
            $created_at = isset( $snippets[ $id ]['created_at'] ) ? (int) $snippets[ $id ]['created_at'] : $now;
        }

        $snippets[ $id ] = array(
            'id'         => $id,
            'name'       => $name,
            'type'       => $type,
            'location'   => $location,
            'code'       => (string) $code,
            'enabled'    => $enabled,
            'created_at' => $created_at,
            'updated_at' => $now,
        );

        update_option( self::OPTION, $snippets, false );

        if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
            GS_ICT_Audit_Log::log(
                'extra_code_saved',
                sprintf( 'Extra code-snippet opgeslagen: %s.', $name ),
                'warning',
                array( 'snippet_id' => $id, 'type' => $type, 'location' => $location, 'enabled' => (bool) $enabled )
            );
        }

        self::redirect( 'extra_code_saved' );
    }

    public static function delete_snippet() {
        self::require_code_capability();

        $id = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
        check_admin_referer( 'gs_ict_delete_extra_code_' . $id );

        $snippets = self::get_snippets();
        if ( isset( $snippets[ $id ] ) ) {
            $name = $snippets[ $id ]['name'] ?? $id;
            unset( $snippets[ $id ] );
            update_option( self::OPTION, $snippets, false );

            if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
                GS_ICT_Audit_Log::log( 'extra_code_deleted', sprintf( 'Extra code-snippet verwijderd: %s.', $name ), 'warning', array( 'snippet_id' => $id ) );
            }
        }

        self::redirect( 'extra_code_deleted' );
    }

    public static function toggle_snippet() {
        self::require_code_capability();

        $id = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
        check_admin_referer( 'gs_ict_toggle_extra_code_' . $id );

        $snippets = self::get_snippets();
        if ( isset( $snippets[ $id ] ) ) {
            $snippets[ $id ]['enabled']    = empty( $snippets[ $id ]['enabled'] ) ? 1 : 0;
            $snippets[ $id ]['updated_at'] = time();
            update_option( self::OPTION, $snippets, false );

            if ( class_exists( 'GS_ICT_Audit_Log' ) ) {
                GS_ICT_Audit_Log::log(
                    'extra_code_toggled',
                    sprintf( 'Extra code-snippet %s: %s.', $snippets[ $id ]['enabled'] ? 'ingeschakeld' : 'uitgeschakeld', $snippets[ $id ]['name'] ),
                    'warning',
                    array( 'snippet_id' => $id, 'enabled' => (bool) $snippets[ $id ]['enabled'] )
                );
            }
        }

        self::redirect( 'extra_code_toggled' );
    }

    public static function can_manage_code() {
        return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
    }

    private static function require_code_capability() {
        if ( ! self::can_manage_code() ) {
            wp_die( esc_html__( 'Je hebt onvoldoende rechten om onbeperkte CSS, JavaScript of HTML op te slaan.', 'gs-ict' ) );
        }
    }

    private static function redirect( $message ) {
        wp_safe_redirect(
            add_query_arg(
                'gsict_message',
                sanitize_key( $message ),
                admin_url( 'admin.php?page=gs-ict-extra-code' )
            )
        );
        exit;
    }
}
