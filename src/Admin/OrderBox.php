<?php
namespace Invoqk\Admin;

use Invoqk\Detection\Result;

final class OrderBox {
	public static function boot(): void {
		add_action( 'add_meta_boxes', static function () {
			foreach ( [ 'woocommerce_page_wc-orders', 'shop_order' ] as $screen ) {
				add_meta_box( 'invoqk-einvoice', esc_html__( 'E-Rechnung (Invoqk)', 'invoqk' ), [ self::class, 'show' ], $screen, 'normal' );
			}
		} );
	}

	/** @param \WP_Post|\WC_Order $post */
	public static function show( $post ): void {
		$order = $post instanceof \WC_Order ? $post : wc_get_order( $post->ID );
		if ( ! $order ) {
			return;
		}
		echo '<p>' . OrderColumn::render( $order ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render()
		echo Report::html( $order, get_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Report
		$r = Result::fromOrder( $order );
		if ( $r && $r->file ) {
			printf( '<p>%s: %s</p>', esc_html__( 'Format', 'invoqk' ), esc_html( $r->file->format ) );
		}
		if ( $r ) {
			printf( '<p>%s: %s</p>', esc_html__( 'Checked', 'invoqk' ), esc_html( wp_date( 'Y-m-d H:i', $r->detectedAt ) ) );
		}
	}
}
