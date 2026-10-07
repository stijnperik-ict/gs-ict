<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GS_ICT_TOTP {
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generate_secret( $bytes = 20 ) {
        return self::base32_encode( random_bytes( max( 10, (int) $bytes ) ) );
    }

    public static function verify( $secret, $code, $window = 1, $period = 30 ) {
        $code = preg_replace( '/\s+/', '', (string) $code );
        if ( ! preg_match( '/^\d{6}$/', $code ) ) {
            return false;
        }

        $counter = (int) floor( time() / $period );
        for ( $i = -abs( (int) $window ); $i <= abs( (int) $window ); $i++ ) {
            $candidate = self::code_for_counter( $secret, $counter + $i );
            if ( false !== $candidate && hash_equals( $candidate, $code ) ) {
                return $counter + $i;
            }
        }

        return false;
    }

    private static function code_for_counter( $secret, $counter ) {
        $key = self::base32_decode( $secret );
        if ( false === $key ) {
            return false;
        }

        $high   = (int) floor( $counter / 4294967296 );
        $low    = (int) ( $counter % 4294967296 );
        $binary = pack( 'N2', $high, $low );
        $hash   = hash_hmac( 'sha1', $binary, $key, true );
        $offset = ord( substr( $hash, -1 ) ) & 0x0f;
        $value  = unpack( 'N', substr( $hash, $offset, 4 ) );
        $value  = $value[1] & 0x7fffffff;

        return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
    }

    private static function base32_encode( $data ) {
        $bits = '';
        foreach ( str_split( $data ) as $char ) {
            $bits .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
        }

        $encoded = '';
        foreach ( str_split( $bits, 5 ) as $chunk ) {
            if ( strlen( $chunk ) < 5 ) {
                $chunk = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
            }
            $encoded .= self::ALPHABET[ bindec( $chunk ) ];
        }

        return $encoded;
    }

    private static function base32_decode( $encoded ) {
        $encoded = strtoupper( preg_replace( '/[^A-Z2-7]/', '', (string) $encoded ) );
        if ( '' === $encoded ) {
            return false;
        }

        $bits = '';
        foreach ( str_split( $encoded ) as $char ) {
            $pos = strpos( self::ALPHABET, $char );
            if ( false === $pos ) {
                return false;
            }
            $bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
        }

        $data = '';
        foreach ( str_split( $bits, 8 ) as $byte ) {
            if ( strlen( $byte ) < 8 ) {
                break;
            }
            $data .= chr( bindec( $byte ) );
        }

        return $data;
    }
}
