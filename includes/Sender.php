<?php
/**
 * Order sync orchestrator: listens to checkout / status-change hooks,
 * deduplicates via order meta, retries failures with backoff.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync;

use CatCode\OneboxSync\Api\Client;
use CatCode\OneboxSync\Core\Logger;
use CatCode\OneboxSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Sender {

	public const META_ORDER_ID   = '_cc_onebox_order_id';
	public const META_STATUS     = '_cc_onebox_status';
	public const META_LAST_ERROR = '_cc_onebox_last_error';
	public const META_SENT_AT    = '_cc_onebox_sent_at';
	public const META_UNCERTAIN  = '_cc_onebox_uncertain';

	private const MAX_ATTEMPTS = 3;

	/** Backoff delays between attempt N and N+1, seconds: 5 min, 30 min, 2 h. */
	private const BACKOFF = array( 300, 1800, 7200 );

	/** Seconds after which a lock left by a dead request is considered stale. */
	private const LOCK_TTL = 120;

	/**
	 * Attach WooCommerce hooks. Called once from Plugin::boot(); plain
	 * `new Sender()` (e.g. for a manual resend) does not register anything.
	 */
	public function register_hooks(): void {
		// Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 20, 1 );
		// Blocks (Store API) checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_checkout' ), 20, 1 );
		// Transition into a trigger status (covers manual orders, payment callbacks).
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		// Scheduled retry.
		add_action( 'cc_onebox_retry_send', array( $this, 'on_retry' ), 10, 2 );
	}

	/**
	 * @param int|\WC_Order $order Order id (classic hook) or WC_Order (Store API hook).
	 */
	public function on_checkout( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order ) {
			return;
		}
		$this->maybe_send( $order );
	}

	/**
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order ) {
			return;
		}
		if ( ! in_array( (string) $to, $this->trigger_statuses(), true ) ) {
			return;
		}
		$this->maybe_send( $order );
	}

	/**
	 * Retry handler scheduled via wp_schedule_single_event.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  Attempt number to execute (2 or 3).
	 */
	public function on_retry( $order_id, $attempt = 2 ): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_ORDER_ID ) ) {
			return; // Sent meanwhile.
		}

		// A previous attempt may have reached OneBox without us seeing the
		// answer. The process carries our externalid, so ask before sending.
		if ( $this->adopt_existing( $order ) ) {
			return;
		}

		$this->send( $order, (int) $attempt );
	}

	/**
	 * Send if configured, in a trigger status and not sent yet.
	 */
	public function maybe_send( \WC_Order $order ): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_ORDER_ID ) ) {
			return; // Deduplication: already in OneBox.
		}
		if ( ! in_array( $order->get_status(), $this->trigger_statuses(), true ) ) {
			return;
		}

		// Checkout hook, status hook and a payment callback can land on the
		// same order in parallel. A transient guard is a read-then-write, so
		// both requests pass it and OneBox gets the order twice; the raw
		// INSERT below lets exactly one through.
		$lock = 'cc_onebox_lock_' . $order->get_id();
		if ( ! $this->lock_acquire( $lock ) ) {
			return;
		}

		try {
			// The winner may have written while we were taking the lock —
			// decide on fresh state, not on our in-request copy.
			$order = self::reload_order( $order->get_id(), $order );
			if ( '' !== (string) $order->get_meta( self::META_ORDER_ID ) ) {
				return;
			}
			$this->send( $order, 1 );
		} finally {
			$this->lock_release( $lock );
		}
	}

	/**
	 * Take the lock, or fail if somebody else holds it.
	 *
	 * add_option() cannot be used here: WordPress runs it as
	 * INSERT ... ON DUPLICATE KEY UPDATE, so it reports success even when the
	 * row already exists and both requests believe they hold the lock.
	 */
	private function lock_acquire( string $name ): bool {
		global $wpdb;

		// A request that died mid-flight must not block the order forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $name, time() - self::LOCK_TTL ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, $name, (string) time() ) );

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return (int) $rows > 0;
	}

	private function lock_release( string $name ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Re-read an order past every cache layer, including the HPOS order cache
	 * that classic invalidation does not touch.
	 */
	private static function reload_order( int $order_id, \WC_Order $fallback ): \WC_Order {
		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( $order_id, 'order-items' );
		clean_post_cache( $order_id );

		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			} catch ( \Throwable $e ) {
				unset( $e ); // Older WooCommerce without the order cache in its container.
			}
		}

		$fresh = wc_get_order( $order_id );

		return $fresh instanceof \WC_Order ? $fresh : $fallback;
	}

	/**
	 * Ask OneBox whether the process for this order already exists and adopt
	 * its id if so. Used before a retry, so that an attempt whose answer never
	 * came back cannot leave the shop without the OneBox id — the process is
	 * looked up by the externalid we always send.
	 *
	 * @return bool True when an existing OneBox process was found and stored.
	 */
	private function adopt_existing( \WC_Order $order ): bool {
		$client = new Client();
		$res    = $client->find_order_by_external( OrderMapper::EXTERNAL_PREFIX . $order->get_id() );
		if ( ! $res['ok'] || $res['id'] <= 0 ) {
			return false;
		}

		$order->update_meta_data( self::META_ORDER_ID, (string) $res['id'] );
		$order->update_meta_data( self::META_STATUS, 'sent' );
		$order->update_meta_data( self::META_SENT_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( self::META_LAST_ERROR );
		$order->delete_meta_data( self::META_UNCERTAIN );
		$order->save();

		/* translators: %d — OneBox order id. */
		$order->add_order_note( sprintf( __( 'The order was already in OneBox under ID %d — an earlier attempt got through. No second order created.', 'catcode-order-sync-with-onebox-for-woocommerce' ), $res['id'] ) );
		Logger::log( $order->get_id(), 'adopted', 1, $res['status'], 'OneBox ID ' . $res['id'], true );

		return true;
	}

	/**
	 * Manual (re)send from the order meta box. Creates the order in OneBox,
	 * or partially updates it when it is already there.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function resend( \WC_Order $order ): array {
		if ( ! Settings::is_configured() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The OneBox connection is not configured (domain, API login and password).', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			);
		}

		$existing = (int) $order->get_meta( self::META_ORDER_ID );
		if ( $existing > 0 ) {
			// /order/set/ is an upsert: passing the stored orderid makes OneBox
			// update the existing process (products are matched and updated,
			// not duplicated).
			$fields            = OrderMapper::build( $order );
			$fields['orderid'] = $existing;
			$client            = new Client();
			$res               = $client->set_orders( array( $fields ) );

			Logger::log( $order->get_id(), 'update', 1, $res['status'], $res['ok'] ? 'OK' : $res['error'] . ' ' . $res['body'], $res['ok'] );

			if ( $res['ok'] ) {
				$order->update_meta_data( self::META_STATUS, 'sent' );
				$order->delete_meta_data( self::META_LAST_ERROR );
				$order->save();
				return array(
					'ok'      => true,
					/* translators: %d — OneBox order id. */
					'message' => sprintf( __( 'Order updated in OneBox (ID %d).', 'catcode-order-sync-with-onebox-for-woocommerce' ), $existing ),
				);
			}
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}

		// Two managers pressing "Send" at the same moment must not create two
		// processes either — the create path takes the same lock as the hooks.
		$lock = 'cc_onebox_lock_' . $order->get_id();
		if ( ! $this->lock_acquire( $lock ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This order is already being sent to OneBox. Reload the page in a few seconds.', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			);
		}

		try {
			$order = self::reload_order( $order->get_id(), $order );
			$known = (int) $order->get_meta( self::META_ORDER_ID );
			if ( $known > 0 ) {
				return array(
					'ok'      => true,
					/* translators: %d — OneBox order id. */
					'message' => sprintf( __( 'The order is already in OneBox (ID %d).', 'catcode-order-sync-with-onebox-for-woocommerce' ), $known ),
				);
			}
			// An earlier attempt may have landed without us seeing the answer.
			if ( $this->adopt_existing( $order ) ) {
				return array(
					'ok'      => true,
					/* translators: %d — OneBox order id. */
					'message' => sprintf( __( 'The order was already in OneBox (ID %d) — no second order created.', 'catcode-order-sync-with-onebox-for-woocommerce' ), (int) $order->get_meta( self::META_ORDER_ID ) ),
				);
			}
			$ok = $this->send( $order, 1, false );
		} finally {
			$this->lock_release( $lock );
		}

		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( 'Order sent to OneBox.', 'catcode-order-sync-with-onebox-for-woocommerce' )
				: (string) $order->get_meta( self::META_LAST_ERROR ),
		);
	}

	/**
	 * Perform one send attempt.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $attempt        Attempt number (1..3).
	 * @param bool      $schedule_retry Whether to schedule the next attempt on failure.
	 * @return bool Success.
	 */
	private function send( \WC_Order $order, int $attempt, bool $schedule_retry = true ): bool {
		$payload = OrderMapper::build( $order );
		$client  = new Client();
		$res     = $client->set_orders( array( $payload ) );

		// OneBox returns {"status":1,"dataArray":[orderid]}.
		$onebox_id = 0;
		if ( $res['ok'] && is_array( $res['json'] ) && isset( $res['json']['dataArray'] ) && is_array( $res['json']['dataArray'] ) ) {
			$onebox_id = (int) ( reset( $res['json']['dataArray'] ) ?: 0 );
		}

		if ( $onebox_id > 0 ) {
			$order->update_meta_data( self::META_ORDER_ID, (string) $onebox_id );
			$order->update_meta_data( self::META_STATUS, 'sent' );
			$order->update_meta_data( self::META_SENT_AT, current_time( 'mysql' ) );
			$order->delete_meta_data( self::META_LAST_ERROR );
			$order->delete_meta_data( self::META_UNCERTAIN );
			$order->save();

			/* translators: %d — OneBox order id. */
			$order->add_order_note( sprintf( __( 'Order sent to OneBox, ID %d.', 'catcode-order-sync-with-onebox-for-woocommerce' ), $onebox_id ) );
			Logger::log( $order->get_id(), 'create', $attempt, $res['status'], 'OneBox ID ' . $onebox_id, true );

			/**
			 * Fires after an order is successfully created in OneBox.
			 *
			 * @param \WC_Order $order     Order.
			 * @param int       $onebox_id OneBox order id.
			 */
			do_action( 'cc_onebox_order_sent', $order, $onebox_id );
			return true;
		}

		$error = trim( $res['error'] . ' ' . mb_substr( $res['body'], 0, 500 ) );
		$order->update_meta_data( self::META_STATUS, 'failed' );
		$order->update_meta_data( self::META_LAST_ERROR, $error );

		// Status 0 = the request never came back (timeout, dropped connection).
		// The process may exist in OneBox all the same, so flag the order; the
		// retry looks it up by externalid before sending anything again.
		$uncertain = 0 === (int) $res['status'];
		if ( $uncertain ) {
			$order->update_meta_data( self::META_UNCERTAIN, '1' );
		}
		$order->save();
		Logger::log( $order->get_id(), $uncertain ? 'no_response' : 'create', $attempt, $res['status'], $error, false );

		if ( $schedule_retry && $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::BACKOFF[ $attempt - 1 ] ?? 7200;
			wp_schedule_single_event( time() + $delay, 'cc_onebox_retry_send', array( $order->get_id(), $attempt + 1 ) );
			Logger::log( $order->get_id(), 'retry_scheduled', $attempt, null, sprintf( 'attempt %d in %d s', $attempt + 1, $delay ), true );
		}

		return false;
	}

	/**
	 * @return string[] WC status slugs without the "wc-" prefix.
	 */
	private function trigger_statuses(): array {
		$statuses = Settings::get( 'trigger_statuses', array() );
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}
		return array_values( array_filter( array_map( 'strval', $statuses ) ) );
	}
}
