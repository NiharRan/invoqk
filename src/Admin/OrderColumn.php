<?php
namespace Invoqk\Admin;

use Invoqk\Detection\Result;

/** Reads cached order meta only; never touches adapters. */
final class OrderColumn {
	public static function boot(): void {
		// HPOS list and legacy post-type list.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ self::class, 'addColumn' ] );
		add_filter( 'manage_edit-shop_order_columns', [ self::class, 'addColumn' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', static fn( $col, $order ) => self::echoCell( $col, $order ), 10, 2 );
		add_action( 'manage_shop_order_posts_custom_column', static fn( $col, $id = null ) => self::echoCell( $col, wc_get_order( $id ?? get_the_ID() ) ), 10, 2 );
	}

	public static function addColumn( array $cols ): array {
		$cols['invoqk'] = esc_html__( 'E-Rechnung', 'invoqk' );
		return $cols;
	}

	private static function echoCell( $col, $order ): void {
		if ( 'invoqk' === $col && $order instanceof \WC_Order ) {
			echo self::render( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render()
		}
	}

	public static function render( \WC_Order $order ): string {
		switch ( Flag::forOrder( $order ) ) {
			case 'source':
				return esc_html( Result::fromOrder( $order )->label ?? Result::fromOrder( $order )->source );
			case 'missing':
				return '<mark class="order-status status-failed"><span>' . esc_html__( 'No e-invoice', 'invoqk' ) . '</span></mark>';
			default:
				return '&ndash;';
		}
	}
}
