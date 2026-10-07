<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub Releases updater for GS ICT.
 *
 * The repository is public, so no token is stored in WordPress. Results are
 * cached to keep GitHub API usage low. Release assets must contain gs-ict.zip.
 */
final class GS_ICT_GitHub_Updater {
    const REPOSITORY       = 'stijnperik-ict/gs-ict';
    const UPDATE_URI       = 'https://github.com/stijnperik-ict/gs-ict';
    const API_LATEST       = 'https://api.github.com/repos/stijnperik-ict/gs-ict/releases/latest';
    const RELEASES_URL     = 'https://github.com/stijnperik-ict/gs-ict/releases';
    const PLUGIN_SLUG      = 'gs-ict';
    const PLUGIN_FILE      = 'gs-ict/gs-ict.php';
    const RELEASE_ZIP_NAME = 'gs-ict.zip';
    const CACHE_KEY        = 'gs_ict_github_latest_release';

    public static function init() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_for_update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache_after_update' ), 10, 2 );
    }

    public static function check_for_update( $update, $plugin_data, $plugin_file, $locales ) {
        unset( $locales );

        if ( self::PLUGIN_FILE !== $plugin_file ) {
            return $update;
        }

        if ( empty( $plugin_data['UpdateURI'] ) || self::UPDATE_URI !== $plugin_data['UpdateURI'] ) {
            return $update;
        }

        $release = self::get_latest_release();
        if ( is_wp_error( $release ) || empty( $release['version'] ) ) {
            return false;
        }

        if ( version_compare( GS_ICT_VERSION, $release['version'], '>=' ) ) {
            return false;
        }

        return array(
            'id'           => self::UPDATE_URI,
            'slug'         => self::PLUGIN_SLUG,
            'version'      => $release['version'],
            'url'          => $release['html_url'],
            'package'      => $release['package'],
            'requires_php' => '7.4',
            'autoupdate'   => false,
        );
    }

    public static function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( is_wp_error( $release ) ) {
            return $result;
        }

        return (object) array(
            'name'          => 'GS ICT',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://gs-ict.nl">GS ICT</a>',
            'homepage'      => self::UPDATE_URI,
            'requires'      => '6.4',
            'requires_php'  => '7.4',
            'download_link' => $release['package'],
            'external'      => true,
            'sections'      => array(
                'description' => 'Beveiligings- en beheerfuncties voor WordPress, waaronder TOTP-2FA voor administrators en controle over automatische updates.',
                'changelog'   => self::release_notes_to_html( $release['body'] ),
            ),
        );
    }

    public static function clear_cache_after_update( $upgrader, $options ) {
        unset( $upgrader );

        if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
            return;
        }

        delete_site_transient( self::CACHE_KEY );
    }

    private static function get_latest_release() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            self::API_LATEST,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'GS-ICT-WordPress/' . GS_ICT_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            return new WP_Error( 'gs_ict_github_http', sprintf( 'GitHub antwoordde met HTTP-status %d.', $status ) );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
            return new WP_Error( 'gs_ict_github_release', 'Er is geen geldige stabiele GitHub-release gevonden.' );
        }

        $package = '';
        if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::RELEASE_ZIP_NAME === $asset['name'] ) {
                    $package = esc_url_raw( $asset['browser_download_url'] );
                    break;
                }
            }
        }

        if ( ! $package ) {
            return new WP_Error( 'gs_ict_github_asset', 'De release bevat geen gs-ict.zip updatepakket.' );
        }

        $release = array(
            'version'  => ltrim( sanitize_text_field( $data['tag_name'] ), "vV" ),
            'html_url' => isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : self::RELEASES_URL,
            'package'  => $package,
            'body'     => isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : '',
        );

        set_site_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );

        return $release;
    }

    private static function release_notes_to_html( $notes ) {
        if ( ! is_string( $notes ) || '' === trim( $notes ) ) {
            return '<p>Bekijk de GitHub-release voor de wijzigingen in deze versie.</p>';
        }

        return wpautop( esc_html( $notes ) );
    }
}
