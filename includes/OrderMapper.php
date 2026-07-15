<?php
/**
 * Turns a WC_Order into a OneBox OS orderSet payload (/api/v2/order/set/).
 *
 * The payload is flat: externalid (used by OneBox to upsert), name, content,
 * client fields (clientname/clientemail/clientphone/clientaddress) and a
 * products[] array whose rows carry name/count/price plus a nested productinfo
 * (catalog product: name, articul=SKU, externalid) so OneBox can find-or-create
 * the catalog product. Optional workflowid/statusid/sourceid routing ids come
 * from the settings and are attached only when a positive value is configured.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync;

use CatCode\OneboxSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class OrderMapper {

	/** externalid prefix for orders; makes /order/set/ an idempotent upsert. */
	public const EXTERNAL_PREFIX = 'wc-';

	/**
	 * Build the OneBox orderSet payload for a WooCommerce order.
	 *
	 * @param \WC_Order $order Order object (HPOS-safe CRUD access only).
	 * @return array
	 */
	public static function build( \WC_Order $order ): array {
		$cfg       = Settings::all();
		$skip_zero = 'yes' === ( $cfg['skip_zero_price'] ?? 'yes' );

		$products = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$price = round( (float) $order->get_item_total( $item, true, false ), 2 );
			if ( $skip_zero && $price * $qty <= 0.0001 ) {
				continue;
			}

			$name    = (string) $item->get_name();
			$product = $item->get_product();

			$productinfo = array(
				'name' => $name,
			);
			$sku = $product ? (string) $product->get_sku() : '';
			if ( '' !== $sku ) {
				$productinfo['articul'] = $sku;
			}
			if ( $product ) {
				$productinfo['externalid'] = self::EXTERNAL_PREFIX . 'p' . $product->get_id();
				// Without findbyArray a repeat send fails with "Продукт із таким
				// зовнішнім id вже існує" — OneBox must LOOK UP the catalog
				// product by externalid instead of recreating it.
				$productinfo['findbyArray'] = array( 'externalid' );
			}

			$products[] = array(
				'name'        => $name,
				'count'       => $qty,
				'price'       => $price,
				'productinfo' => $productinfo,
			);
		}

		// Shipping cost becomes its own order line so the total reconciles.
		if ( 'yes' === ( $cfg['include_shipping'] ?? 'yes' ) ) {
			$ship_cost = round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2 );
			if ( $ship_cost > 0 ) {
				$ship_title = (string) $order->get_shipping_method();
				$ship_title = '' !== $ship_title ? $ship_title : __( 'Доставка', 'onebox-sync-for-woocommerce' );
				$products[] = array(
					'name'        => $ship_title,
					'count'       => 1,
					'price'       => $ship_cost,
					'productinfo' => array(
						'name'        => $ship_title,
						'externalid'  => self::EXTERNAL_PREFIX . 'shipping',
						'findbyArray' => array( 'externalid' ),
					),
				);
			}
		}

		$first = trim( (string) ( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) );
		$last  = trim( (string) ( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) );
		$person = trim( $first . ' ' . $last );
		if ( '' === $person ) {
			$person = (string) $order->get_billing_email();
		}
		if ( '' === $person ) {
			$person = __( 'Клієнт', 'onebox-sync-for-woocommerce' );
		}

		// Delivery / payment details ride in the order content field.
		$content_parts = array();
		$note          = (string) $order->get_customer_note();
		if ( '' !== $note ) {
			$content_parts[] = $note;
		}
		$ship_method = (string) $order->get_shipping_method();
		if ( '' !== $ship_method ) {
			/* translators: %s: shipping method name. */
			$content_parts[] = sprintf( __( 'Доставка: %s', 'onebox-sync-for-woocommerce' ), $ship_method );
		}
		$pay = (string) $order->get_payment_method_title();
		if ( '' !== $pay ) {
			/* translators: %s: payment method name. */
			$content_parts[] = sprintf( __( 'Оплата: %s', 'onebox-sync-for-woocommerce' ), $pay );
		}
		// Only a real online payment counts: date_paid is stamped by
		// payment_complete(), and offline methods (COD & friends) are excluded —
		// WC's is_paid() is true for ANY processing order, which false-flagged
		// cash-on-delivery orders as paid.
		$offline_method = in_array( (string) $order->get_payment_method(), array( 'cod', 'cheque', 'bacs' ), true );
		if ( 'yes' === ( $cfg['pass_payment_status'] ?? 'yes' ) && ! $offline_method && null !== $order->get_date_paid() ) {
			$content_parts[] = html_entity_decode(
				sprintf(
					/* translators: %s: order total */
					__( '✅ Оплачено онлайн (%s)', 'onebox-sync-for-woocommerce' ),
					wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) )
				),
				ENT_QUOTES,
				'UTF-8'
			);
		}

		$payload = array(
			'externalid' => self::EXTERNAL_PREFIX . $order->get_id(),
			/* translators: 1: order number, 2: shop host. */
			'name'       => sprintf( __( 'Замовлення #%1$s (%2$s)', 'onebox-sync-for-woocommerce' ), $order->get_order_number(), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'clientname' => $person,
			'products'   => $products,
		);

		$email = (string) $order->get_billing_email();
		if ( '' !== $email ) {
			$payload['clientemail'] = $email;
		}
		$phone = self::phone( (string) $order->get_billing_phone() );
		if ( '' !== $phone ) {
			$payload['clientphone'] = $phone;
		}
		$address = self::address_line( $order );
		if ( '' !== $address ) {
			$payload['clientaddress'] = $address;
		}
		if ( $content_parts ) {
			$payload['content'] = implode( ' | ', $content_parts );
		}

		// OneBox REQUIRES workflowid + statusid when creating a new process.
		// Configured values win; otherwise fall back to the first business
		// process in the box and its first status (id 1).
		$workflowid = (int) ( $cfg['workflowid'] ?? 0 );
		$statusid   = (int) ( $cfg['statusid'] ?? 0 );
		if ( $workflowid <= 0 ) {
			$workflowid = self::default_workflow_id();
		}
		if ( $workflowid > 0 ) {
			$payload['workflowid'] = $workflowid;
			$payload['statusid']   = $statusid > 0 ? $statusid : 1;
		}
		$sourceid = (int) ( $cfg['sourceid'] ?? 0 );
		if ( $sourceid > 0 ) {
			$payload['sourceid'] = $sourceid;
		}

		/**
		 * Allow site-specific payload adjustments before sending to OneBox.
		 *
		 * @param array     $payload OneBox orderSet payload.
		 * @param \WC_Order $order   Source WooCommerce order.
		 */
		return (array) apply_filters( 'cc_onebox_order_payload', $payload, $order );
	}

	/**
	 * First business process id in the box, cached for an hour. Used only when
	 * no workflowid is configured.
	 */
	private static function default_workflow_id(): int {
		$cached = get_transient( 'cc_onebox_default_workflow' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$rows = ( new \CatCode\OneboxSync\Api\Client() )->get_workflows();
		$id   = $rows ? (int) ( $rows[0]['id'] ?? 0 ) : 0;
		if ( $id > 0 ) {
			set_transient( 'cc_onebox_default_workflow', $id, HOUR_IN_SECONDS );
		}
		return $id;
	}

	/** Compose a single delivery address line, preferring the shipping address. */
	private static function address_line( \WC_Order $order ): string {
		$parts = array_filter(
			array(
				(string) $order->get_shipping_city(),
				(string) $order->get_shipping_address_1(),
				(string) $order->get_shipping_address_2(),
			)
		);
		if ( ! $parts ) {
			$parts = array_filter(
				array(
					(string) $order->get_billing_city(),
					(string) $order->get_billing_address_1(),
					(string) $order->get_billing_address_2(),
				)
			);
		}
		return trim( implode( ', ', $parts ) );
	}

	/**
	 * Normalize a phone number to E.164-ish (+380...).
	 */
	private static function phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits || null === $digits ) {
			return '';
		}
		if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = '38' . $digits;
		}
		return '+' . $digits;
	}
}
