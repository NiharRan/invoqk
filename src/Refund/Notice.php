<?php
namespace Invoqk\Refund;

/** One batched admin notice for all orders with open refunds. */
final class Notice {
	public static function boot(): void {
		add_action( 'admin_notices', static function (): void {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in html()
			}
		} );
	}

	public static function html(): string {
		$n = Open::count();
		if ( $n < 1 ) {
			return '';
		}
		$first = wc_get_order( Open::orders()[0] );
		$link  = $first instanceof \WC_Order ? ' <a href="' . esc_url( $first->get_edit_order_url() ) . '">' . esc_html__( 'Open first order', 'invoqk' ) . '</a>' : '';
		return '<div class="notice notice-warning"><p>' . esc_html( sprintf(
			/* translators: %d: number of orders */
			_n( '%d order has a refund without a corrective e-invoice.', '%d orders have a refund without a corrective e-invoice.', $n, 'invoqk' ),
			$n
		) ) . $link . '</p></div>';
	}
}
