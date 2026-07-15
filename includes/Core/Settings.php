<?php
/**
 * Settings repository. Transparently encrypts the API key at rest.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Core;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const SECRET_KEYS = array( 'api_password' );

	/** @var array|null */
	private static $cache = null;

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( 'cc_onebox_settings', Installer::default_settings() );
		if ( ! is_array( $raw ) ) {
			$raw = Installer::default_settings();
		}
		$raw = wp_parse_args( $raw, Installer::default_settings() );

		foreach ( self::SECRET_KEYS as $secret ) {
			if ( isset( $raw[ $secret ] ) && '' !== $raw[ $secret ] ) {
				$raw[ $secret ] = Crypto::decrypt( (string) $raw[ $secret ] );
			}
		}

		if ( ! is_array( $raw['trigger_statuses'] ) ) {
			$raw['trigger_statuses'] = Installer::default_settings()['trigger_statuses'];
		}

		self::$cache = $raw;
		return $raw;
	}

	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	public static function save( array $values ): void {
		foreach ( self::SECRET_KEYS as $secret ) {
			if ( isset( $values[ $secret ] ) && '' !== $values[ $secret ] ) {
				$values[ $secret ] = Crypto::encrypt( (string) $values[ $secret ] );
			}
		}
		update_option( 'cc_onebox_settings', $values, true );
		self::$cache = null;
	}

	public static function is_configured(): bool {
		return '' !== (string) self::get( 'domain', '' )
			&& '' !== (string) self::get( 'api_login', '' )
			&& '' !== (string) self::get( 'api_password', '' );
	}
}
