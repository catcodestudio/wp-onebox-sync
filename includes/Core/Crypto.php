<?php
/**
 * Encrypt/decrypt sensitive option values (API keys).
 * Uses sodium_crypto_secretbox when available, falls back to HMAC-stream cipher.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Core;

defined( 'ABSPATH' ) || exit;

class Crypto {

	private const PREFIX_SODIUM = 'ccob1:';
	private const PREFIX_HMAC   = 'ccob2:';

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, substr( $key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
		}

		$nonce  = random_bytes( 16 );
		$stream = self::hmac_stream( $key, $nonce, strlen( $plain ) );
		$cipher = $plain ^ $stream;
		$mac    = hash_hmac( 'sha256', $nonce . $cipher, $key, true );
		return self::PREFIX_HMAC . base64_encode( $nonce . $mac . $cipher );
	}

	public static function decrypt( string $cipher ): string {
		if ( '' === $cipher ) {
			return '';
		}
		$key = self::key();

		if ( 0 === strncmp( $cipher, self::PREFIX_SODIUM, strlen( self::PREFIX_SODIUM ) ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $cipher, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ct    = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $ct, $nonce, substr( $key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
			return false === $plain ? '' : $plain;
		}

		if ( 0 === strncmp( $cipher, self::PREFIX_HMAC, strlen( self::PREFIX_HMAC ) ) ) {
			$raw = base64_decode( substr( $cipher, strlen( self::PREFIX_HMAC ) ), true );
			if ( false === $raw || strlen( $raw ) < 48 ) {
				return '';
			}
			$nonce = substr( $raw, 0, 16 );
			$mac   = substr( $raw, 16, 32 );
			$ct    = substr( $raw, 48 );
			$calc  = hash_hmac( 'sha256', $nonce . $ct, $key, true );
			if ( ! hash_equals( $mac, $calc ) ) {
				return '';
			}
			$stream = self::hmac_stream( $key, $nonce, strlen( $ct ) );
			return $ct ^ $stream;
		}

		// Plaintext legacy value — return as-is.
		return $cipher;
	}

	private static function key(): string {
		$key = get_option( 'cc_onebox_crypto_key' );
		if ( ! is_string( $key ) || '' === $key ) {
			$key = base64_encode( random_bytes( 32 ) );
			add_option( 'cc_onebox_crypto_key', $key, '', false );
		}
		$decoded = base64_decode( $key, true );
		if ( false === $decoded || strlen( $decoded ) < 32 ) {
			$decoded = hash( 'sha256', (string) $key, true );
		}
		return $decoded;
	}

	private static function hmac_stream( string $key, string $nonce, int $len ): string {
		$out     = '';
		$counter = 0;
		while ( strlen( $out ) < $len ) {
			$out .= hash_hmac( 'sha256', $nonce . pack( 'N', $counter++ ), $key, true );
		}
		return substr( $out, 0, $len );
	}
}
