<?php
/**
 * Sync event log storage helpers.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Core;

defined( 'ABSPATH' ) || exit;

class Logger {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ccob_log';
	}

	public static function log( int $order_id, string $event, int $attempt_no, ?int $http, string $message, bool $success ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->insert(
			self::table(),
			array(
				'order_id'    => $order_id,
				'event'       => $event,
				'attempt_no'  => $attempt_no,
				'http_status' => $http,
				'message'     => mb_substr( $message, 0, 2000 ),
				'success'     => $success ? 1 : 0,
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	public static function latest( int $limit = 100 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
				self::table(),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	public static function for_order( int $order_id, int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT %d',
				self::table(),
				$order_id,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return $rows ?: array();
	}
}
