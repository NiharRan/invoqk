<?php
namespace Invoqk\Fallback;

use Invoqk\Classification\Classifier;
use Invoqk\Detection\Detector;
use Invoqk\Detection\Result;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Limit;

/** Whether the order box may offer to generate a fallback e-invoice. Runs no expensive work beyond one detection of a never-checked order. */
final class Offer {
	public static function available( \WC_Order $o ): bool {
		if ( ! Limit::isPro() || ! in_array( $o->get_status(), [ 'processing', 'completed' ], true ) || ! Classifier::isRequired( $o ) ) {
			return false;
		}
		if ( ! Result::fromOrder( $o ) ) {
			( new Detector( Registry::default() ) )->run( $o->get_id(), false );
		}
		$r = Result::fromOrder( $o );
		return $r && 'none' === $r->source && ! Store::get( $o );
	}
}
