<?php
/**
 * Meta box on the WooCommerce order edit screen (HPOS + legacy).
 * Shows OneBox sync status and a "resend" button.
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Admin;

use CatCode\OneboxSync\Sender;

defined( 'ABSPATH' ) || exit;

class OrderMetaBox {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_post_ccob_resend', array( $this, 'handle_resend' ) );
		add_action( 'admin_notices', array( $this, 'resend_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
	}

	public function register(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'ccob_sync_box',
			__( 'OneBox', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
			return;
		}

		$onebox_id = (string) $order->get_meta( Sender::META_ORDER_ID );
		$status    = (string) $order->get_meta( Sender::META_STATUS );
		$error     = (string) $order->get_meta( Sender::META_LAST_ERROR );
		$sent_at   = (string) $order->get_meta( Sender::META_SENT_AT );

		if ( '' !== $onebox_id ) {
			echo '<p class="ccob-ok"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Sent to OneBox', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
			echo '<p class="ccob-row"><strong>' . esc_html__( 'OneBox ID:', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</strong> <code>' . esc_html( $onebox_id ) . '</code></p>';
			if ( '' !== $sent_at ) {
				echo '<p class="ccob-row"><strong>' . esc_html__( 'When:', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</strong> ' . esc_html( $sent_at ) . '</p>';
			}
			echo '<p class="ccob-hint">' . esc_html__( 'Resending updates the same process in OneBox.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
		} elseif ( 'failed' === $status ) {
			echo '<p class="ccob-fail"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Sending failed', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
			if ( '' !== $error ) {
				echo '<p class="ccob-row"><code>' . esc_html( mb_substr( $error, 0, 200 ) ) . '</code></p>';
			}
		} else {
			echo '<p class="ccob-none"><span class="dashicons dashicons-minus"></span> ' . esc_html__( 'Not sent yet', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
		}

		// An attempt that got no answer may still have created the process. We
		// look it up by externalid before retrying — say so while the order is
		// still flagged (the lookup itself may have failed too).
		if ( '' !== (string) $order->get_meta( Sender::META_UNCERTAIN ) ) {
			echo '<p class="ccob-warn">' . esc_html__( 'One attempt got no response from OneBox (timeout) and the order could not be looked up. It may have been created anyway — check the CRM before resending.', 'catcode-order-sync-with-onebox-for-woocommerce' ) . '</p>';
		}

		// A metabox is rendered INSIDE the WooCommerce order form, and browsers
		// drop a nested <form> — the button then submitted the order form and
		// the resend never happened. A nonced link has no such problem.
		$resend_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'ccob_resend',
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'ccob_resend_' . $order->get_id()
		);
		echo '<p class="ccob-row"><a class="button button-primary ccob-btn" href="' . esc_url( $resend_url ) . '">'
			. esc_html( '' === $onebox_id ? __( 'Send to OneBox', 'catcode-order-sync-with-onebox-for-woocommerce' ) : __( 'Resend', 'catcode-order-sync-with-onebox-for-woocommerce' ) )
			. '</a></p>';
		echo '<p class="ccob-hint">' . wp_kses_post(
			sprintf(
				/* translators: %s — link to the log page. */
				__( 'Event log: <a href="%s">WooCommerce → OneBox Sync</a>.', 'catcode-order-sync-with-onebox-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=catcode-order-sync-with-onebox-for-woocommerce&tab=log' ) )
			)
		) . '</p>';
	}

	public function handle_resend(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'catcode-order-sync-with-onebox-for-woocommerce' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( 'ccob_resend_' . $order_id );

		$result = array(
			'ok'      => false,
			'message' => __( 'Order not found.', 'catcode-order-sync-with-onebox-for-woocommerce' ),
		);
		$order  = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			$result = ( new Sender() )->resend( $order );
		}

		// Back where the manager pressed the button — HPOS and the legacy
		// post.php screen have different URLs.
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		$back = add_query_arg(
			array_filter(
				array(
					'ccob_msg' => rawurlencode( (string) $result['message'] ),
					'ccob_err' => $result['ok'] ? null : '1',
				)
			),
			$back
		);

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Show the result of a manual resend on the order screen we came back to.
	 */
	public function resend_notice(): void {
		if ( ! isset( $_GET['ccob_msg'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		// The settings page prints its own notice for the same query arg.
		if ( ! $this->is_order_screen() ) {
			return;
		}
		$msg = sanitize_text_field( wp_unslash( (string) $_GET['ccob_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $msg ) {
			return;
		}
		$type = isset( $_GET['ccob_err'] ) ? 'notice-error' : 'notice-success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/** Are we on the order edit screen (HPOS or the legacy post screen)? */
	private function is_order_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		return in_array( $screen->id, array( $order_screen, 'shop_order' ), true );
	}

	/**
	 * Metabox styles, attached to an enqueued handle instead of an inline
	 * <style> block so that they can be cached and filtered like any other CSS.
	 */
	public function styles(): void {
		if ( ! $this->is_order_screen() ) {
			return;
		}

		$handle = 'ccob-order-metabox';
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), CCOB_VERSION );
		}
		if ( ! wp_style_is( $handle, 'enqueued' ) ) {
			wp_enqueue_style( $handle );
			wp_add_inline_style(
				$handle,
				'#ccob_sync_box .ccob-ok{color:#1a7f37;font-weight:600;margin:0 0 6px}'
				. '#ccob_sync_box .ccob-fail{color:#a00;font-weight:600;margin:0 0 6px}'
				. '#ccob_sync_box .ccob-none{color:#666;font-weight:600;margin:0 0 6px}'
				. '#ccob_sync_box .ccob-row{margin:4px 0}'
				. '#ccob_sync_box .ccob-warn{margin:6px 0;font-size:12px;color:#8a6d3b}'
				. '#ccob_sync_box .ccob-hint{font-size:12px;color:#666;margin:8px 0 0}'
				. '#ccob_sync_box .ccob-btn{width:100%;text-align:center}'
			);
		}
	}
}
