<?php
namespace Invoqk\B2g;

use Invoqk\Detection\Result;
use Invoqk\Sources\BuyerReferenceAware;
use Invoqk\Sources\Registry;
use Invoqk\Validation\Limit;

/** Whether the stored buyer reference (BT-10) reaches the invoice through the source plugin. */
final class Delivery {
	/** `none` (no reference stored), `delivered` (source adapter takes it) or `unsupported`. */
	public static function status( \WC_Order $o ): string {
		if ( '' === Reference::get( $o ) ) {
			return 'none';
		}
		$r       = Result::fromOrder( $o );
		$adapter = $r ? Registry::default()->get( $r->source ) : null;
		return $adapter instanceof BuyerReferenceAware && $adapter->supportsBuyerReference() ? 'delivered' : 'unsupported';
	}

	/** Escaped order-box notice when the reference cannot reach the invoice, else ''. Pro only. */
	public static function notice( \WC_Order $o ): string {
		if ( ! Limit::isPro() || 'unsupported' !== self::status( $o ) ) {
			return '';
		}
		$label = (string) ( Result::fromOrder( $o )->label ?? '' );
		$text  = '' !== $label
			/* translators: %1$s: invoice plugin name */
			? sprintf( __( '%1$s cannot take the Leitweg-ID automatically. It must be entered in %1$s as the buyer reference before the e-invoice is sent.', 'invoqk' ), $label )
			: __( 'No invoice plugin that takes the Leitweg-ID automatically was found. It must be entered in your invoice plugin as the buyer reference before the e-invoice is sent.', 'invoqk' );
		return '<div class="notice notice-warning inline"><p>' . esc_html( $text ) . '</p></div>';
	}
}
