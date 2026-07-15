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
	}

	public function register(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'ccob_sync_box',
			__( 'OneBox', 'onebox-sync-for-woocommerce' ),
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
			echo '<p>' . esc_html__( 'Замовлення не знайдено.', 'onebox-sync-for-woocommerce' ) . '</p>';
			return;
		}

		$onebox_id = (string) $order->get_meta( Sender::META_ORDER_ID );
		$status    = (string) $order->get_meta( Sender::META_STATUS );
		$error     = (string) $order->get_meta( Sender::META_LAST_ERROR );
		$sent_at   = (string) $order->get_meta( Sender::META_SENT_AT );

		if ( '' !== $onebox_id ) {
			echo '<p style="color:#1a7f37;font-weight:600;margin:0 0 6px"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Відправлено в OneBox', 'onebox-sync-for-woocommerce' ) . '</p>';
			echo '<p style="margin:4px 0"><strong>' . esc_html__( 'OneBox ID:', 'onebox-sync-for-woocommerce' ) . '</strong> <code>' . esc_html( $onebox_id ) . '</code></p>';
			if ( '' !== $sent_at ) {
				echo '<p style="margin:4px 0"><strong>' . esc_html__( 'Коли:', 'onebox-sync-for-woocommerce' ) . '</strong> ' . esc_html( $sent_at ) . '</p>';
			}
			echo '<p style="font-size:12px;color:#666;margin:8px 0 0">' . esc_html__( 'Повторна відправка оновить замовлення в OneBox.', 'onebox-sync-for-woocommerce' ) . '</p>';
		} elseif ( 'failed' === $status ) {
			echo '<p style="color:#a00;font-weight:600;margin:0 0 6px"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Помилка відправки', 'onebox-sync-for-woocommerce' ) . '</p>';
			if ( '' !== $error ) {
				echo '<p style="margin:4px 0;font-size:12px"><code>' . esc_html( mb_substr( $error, 0, 200 ) ) . '</code></p>';
			}
		} else {
			echo '<p style="color:#666;font-weight:600;margin:0 0 6px"><span class="dashicons dashicons-minus"></span> ' . esc_html__( 'Ще не відправлено', 'onebox-sync-for-woocommerce' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		echo '<input type="hidden" name="action" value="ccob_resend"/>';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '"/>';
		echo '<input type="hidden" name="redirect" value="' . esc_attr( (string) admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ) ) . '"/>';
		wp_nonce_field( 'ccob_resend_' . $order->get_id() );
		submit_button(
			'' === $onebox_id ? __( 'Відправити в OneBox', 'onebox-sync-for-woocommerce' ) : __( 'Відправити повторно', 'onebox-sync-for-woocommerce' ),
			'primary',
			'submit',
			false,
			array( 'style' => 'width:100%' )
		);
		echo '</form>';
		echo '<p style="font-size:12px;color:#666;margin:8px 0 0">' . wp_kses_post(
			sprintf(
				/* translators: %s — link to the log page. */
				__( 'Журнал подій: <a href="%s">WooCommerce → OneBox Sync</a>.', 'onebox-sync-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=onebox-sync-for-woocommerce&tab=log' ) )
			)
		) . '</p>';
	}

	public function handle_resend(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Немає прав', 'onebox-sync-for-woocommerce' ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		check_admin_referer( 'ccob_resend_' . $order_id );

		$redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect'] ) ) : admin_url();

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			( new Sender() )->resend( $order );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
