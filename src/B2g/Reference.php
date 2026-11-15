<?php
namespace Invoqk\B2g;

/** The buyer reference (Leitweg-ID, BT-10) stored on an order. */
final class Reference {
	public const META = '_invoqk_buyer_reference';
	/** Where the block checkout keeps an additional field with location "order" (see Field). */
	public const BLOCK_META = '_wc_other/' . Field::BLOCK_ID;

	public static function get( \WC_Order $o ): string {
		$v = (string) $o->get_meta( self::META );
		return '' !== $v ? $v : LeitwegId::normalise( (string) $o->get_meta( self::BLOCK_META ) );
	}

	public static function set( \WC_Order $o, string $raw ): void {
		$v = LeitwegId::normalise( $raw );
		if ( '' === $v ) {
			$o->delete_meta_data( self::META );
		} else {
			$o->update_meta_data( self::META, $v );
		}
		// An order without an ID (classic checkout, before WooCommerce saves it) would get meta rows for ID 0.
		if ( $o->get_id() ) {
			$o->save_meta_data();
		}
	}
}
