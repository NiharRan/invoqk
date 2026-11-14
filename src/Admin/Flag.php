<?php
namespace Invoqk\Admin;

use Invoqk\Classification\Classifier;
use Invoqk\Detection\Result;

final class Flag {
	/** @return 'source'|'missing'|'not_required'|'pending' */
	public static function forOrder( \WC_Order $order ): string {
		$r = Result::fromOrder( $order );
		if ( ! $r ) {
			return 'pending';
		}
		// First argument is the classified value (E4); returning false from the filter still opts an order out.
		if ( ! apply_filters( 'invoqk_order_requires_einvoice', Classifier::isRequired( $order ), $order ) ) {
			return 'not_required';
		}
		return 'none' !== $r->source ? 'source' : 'missing';
	}
}
