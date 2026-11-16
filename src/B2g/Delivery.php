<?php
namespace Invoqk\B2g;

use Invoqk\Detection\Result;
use Invoqk\Sources\BuyerReferenceAware;
use Invoqk\Sources\Registry;
use Invoqk\Sources\SourceAdapter;
use Invoqk\Validation\Limit;

/** Whether the stored buyer reference (BT-10) reaches the invoice through the source plugin. */
final class Delivery {
	/** `none` (no reference stored), `delivered` (source adapter takes it) or `unsupported`. */
	public static function status( \WC_Order $o ): string {
		if ( '' === Reference::get( $o ) ) {
			return 'none';
		}
		return self::supports( self::adapter( $o ) ) ? 'delivered' : 'unsupported';
	}

	/** Escaped order-box notice when the reference cannot reach the invoice, else ''. Pro only. */
	public static function notice( \WC_Order $o ): string {
		if ( ! Limit::isPro() || 'unsupported' !== self::status( $o ) ) {
			return '';
		}
		$adapter = self::adapter( $o );
		$label   = (string) ( Result::fromOrder( $o )->label ?? '' );
		$label   = '' !== $label ? $label : ( $adapter ? $adapter->label() : '' );
		if ( '' !== $label && $adapter instanceof BuyerReferenceAware ) { // the plugin could take it, but not in this format
			/* translators: %1$s: invoice plugin name */
			$text = sprintf( __( '%1$s cannot write the Leitweg-ID into its current e-invoice format. If the buyer needs a Leitweg-ID, switch the e-invoice format in %1$s to UBL or PEPPOL BIS before the e-invoice is sent.', 'invoqk' ), $label );
		} elseif ( '' !== $label ) {
			/* translators: %1$s: invoice plugin name */
			$text = sprintf( __( '%1$s cannot take the Leitweg-ID automatically. It must be entered in %1$s as the buyer reference before the e-invoice is sent.', 'invoqk' ), $label );
		} else {
			$text = __( 'No invoice plugin that takes the Leitweg-ID automatically was found. It must be entered in your invoice plugin as the buyer reference before the e-invoice is sent.', 'invoqk' );
		}
		return '<div class="notice notice-warning inline"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** The detected source's adapter; before the first detection the available adapter most likely to be used, a supporting one first. */
	private static function adapter( \WC_Order $o ): ?SourceAdapter {
		$r = Result::fromOrder( $o );
		if ( $r ) {
			return Registry::default()->get( $r->source );
		}
		$list = array_values( array_filter( Registry::default()->plugins(), static function ( $a ) {
			try {
				return $a->isAvailable();
			} catch ( \Throwable $e ) {
				return false;
			}
		} ) );
		usort( $list, static fn( $a, $b ) => self::supports( $b ) <=> self::supports( $a ) );
		return $list[0] ?? null;
	}

	private static function supports( ?SourceAdapter $a ): bool {
		return $a instanceof BuyerReferenceAware && $a->supportsBuyerReference();
	}
}
