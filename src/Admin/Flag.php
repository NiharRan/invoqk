<?php
namespace Invoqk\Admin;

use Invoqk\Detection\Result;

final class Flag {
	/** @return 'source'|'missing'|'not_required'|'pending' */
	public static function forOrder( \WC_Order $order ): string {
		$r = Result::fromOrder( $order );
		if ( ! $r ) {
			return 'pending';
		}
		if ( 'none' !== $r->source ) {
			return 'source';
		}
		return apply_filters( 'invoqk_order_requires_einvoice', true, $order ) ? 'missing' : 'not_required';
	}
}
