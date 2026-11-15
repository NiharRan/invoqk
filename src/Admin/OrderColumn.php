<?php
namespace Invoqk\Admin;

use Invoqk\Classification\Classifier;
use Invoqk\Detection\Result;
use Invoqk\Guard\Hold;
use Invoqk\Validation\Record;

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
		$tag = Classifier::isOverridden( $order ) ? ' <small>(' . esc_html__( 'manual', 'invoqk' ) . ')</small>' : '';
		$held = 'held' === ( Hold::get( $order )['state'] ?? '' ) ? Badge::html( 'held' ) . ' ' : '';
		$refund = \Invoqk\Refund\Open::ids( $order ) ? Badge::html( 'refund' ) . ' ' : '';
		return $refund . $held . self::cell( $order, $tag );
	}

	private static function cell( \WC_Order $order, string $tag ): string {
		switch ( Flag::forOrder( $order ) ) {
			case 'source':
				$r = Result::fromOrder( $order );
				return esc_html( $r->label ?? $r->source ) . ' ' . Badge::html( Record::status( $order ) ) . $tag;
			case 'missing':
				return '<mark class="order-status status-failed"><span>' . esc_html__( 'No e-invoice', 'invoqk' ) . '</span></mark>' . $tag;
			case 'not_required':
				return '<span class="description">' . esc_html__( 'Not required', 'invoqk' ) . '</span>' . $tag;
			default:
				return '&ndash;';
		}
	}
}
