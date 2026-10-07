<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_Crypto {
    private static function key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|gs-ict-2fa', true );
    }

    public static function is_available() {
        return function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' );
    }

    public static function encrypt( $plaintext ) {
        $plaintext = (string) $plaintext;
        $key       = self::key();

        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
            return 'sodium:' . base64_encode( $nonce . $cipher );
        }

        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv  = random_bytes( 12 );
            $tag = '';
            $cipher = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                'gs-ict-2fa'
            );

            if ( false !== $cipher ) {
                return 'openssl:' . base64_encode( $iv . $tag . $cipher );
            }
        }

        return false;
    }

    public static function decrypt( $payload ) {
        if ( ! is_string( $payload ) || '' === $payload ) {
            return false;
        }

        $key = self::key();

        if ( 0 === strpos( $payload, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $raw = base64_decode( substr( $payload, 7 ), true );
            if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                return false;
            }

            $nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            return sodium_crypto_secretbox_open( $cipher, $nonce, $key );
        }

        if ( 0 === strpos( $payload, 'openssl:' ) && function_exists( 'openssl_decrypt' ) ) {
            $raw = base64_decode( substr( $payload, 8 ), true );
            if ( false === $raw || strlen( $raw ) <= 28 ) {
                return false;
            }

            $iv     = substr( $raw, 0, 12 );
            $tag    = substr( $raw, 12, 16 );
            $cipher = substr( $raw, 28 );

            return openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                'gs-ict-2fa'
            );
        }

        return false;
    }
}
