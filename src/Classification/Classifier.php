<?php
namespace Invoqk\Classification;

/**
 * Decides whether an order needs an e-invoice (German domestic B2B over the threshold) and reads
 * the stored result. Rules are pure: they read the order and do no I/O.
 *
 * Rules, first match wins:
 *   total <= 0 -> zero_total | no country -> no_country | country != DE -> foreign
 *   no VAT ID and no company -> private | total <= threshold -> small_amount
 *   VAT ID -> vat_id | company only -> company_only (hint no_vat_id)
 */
final class Classifier {
	public const CLASS_META = '_invoqk_class';
	public const OVERRIDE   = '_invoqk_override';
	/** Gross total, EUR. Kleinbetragsrechnung limit. Filter: invoqk_classifier_threshold. */
	public const THRESHOLD = 250.0;

	public static function threshold( \WC_Order $order ): float {
		return (float) apply_filters( 'invoqk_classifier_threshold', self::THRESHOLD, $order );
	}

	/** @return array{required:bool,reason:string,hint:?string,at:int} */
	public static function classify( \WC_Order $order ): array {
		$total   = (float) $order->get_total();
		$country = strtoupper( trim( $order->get_billing_country() ) );
		if ( '' === $country ) {
			$country = strtoupper( trim( $order->get_shipping_country() ) );
		}
		$company = trim( $order->get_billing_company() );
		$vat     = self::vatId( $order );
		$hint    = null;

		if ( $total <= 0 ) {
			$reason = 'zero_total';
		} elseif ( '' === $country ) {
			$reason = 'no_country';
		} elseif ( 'DE' !== $country ) {
			$reason = 'foreign';
		} elseif ( '' === $vat && '' === $company ) {
			$reason = 'private';
		} elseif ( $total <= self::threshold( $order ) ) {
			$reason = 'small_amount';
		} elseif ( '' !== $vat ) {
			$reason = 'vat_id';
		} else {
			$reason = 'company_only';
			$hint   = 'no_vat_id';
		}
		if ( '' !== $vat && 'DE' === $country && ! preg_match( '/^DE[0-9]{9}$/', self::normalize( $vat ) ) && ! in_array( $reason, [ 'zero_total', 'no_country', 'foreign' ], true ) ) {
			$hint = 'vat_id_format';
		}
		return [
			'required' => in_array( $reason, [ 'vat_id', 'company_only' ], true ),
			'reason'   => $reason,
			'hint'     => $hint,
			'at'       => time(),
		];
	}

	/** Classify and store the automatic result with its own meta save. Never touches the override. */
	public static function save( \WC_Order $order ): array {
		$r = self::classify( $order );
		$order->update_meta_data( self::CLASS_META, $r );
		$order->save_meta_data();
		\Invoqk\Admin\Readiness::flush();
		return $r;
	}

	public static function stored( \WC_Order $order ): ?array {
		$m = $order->get_meta( self::CLASS_META );
		return is_array( $m ) && isset( $m['required'], $m['reason'] ) ? $m : null;
	}

	public static function override( \WC_Order $order ): ?array {
		$m = $order->get_meta( self::OVERRIDE );
		return is_array( $m ) && isset( $m['required'] ) ? $m : null;
	}

	public static function isOverridden( \WC_Order $order ): bool {
		return null !== self::override( $order );
	}

	/** Automatic result: stored, else computed on the fly (never written by a read). */
	public static function auto( \WC_Order $order ): array {
		return self::stored( $order ) ?? self::classify( $order );
	}

	public static function isRequired( \WC_Order $order ): bool {
		$o = self::override( $order );
		return $o ? (bool) $o['required'] : (bool) self::auto( $order )['required'];
	}

	public static function reasonText( string $code, float $threshold ): string {
		$t = rtrim( rtrim( number_format( $threshold, 2, ',', '.' ), '0' ), ',' );
		switch ( $code ) {
			case 'vat_id':
				return sprintf( __( 'Domestic customer with VAT ID, amount over %s €.', 'invoqk' ), $t );
			case 'company_only':
				return sprintf( __( 'Domestic customer with company name, amount over %s €.', 'invoqk' ), $t );
			case 'private':
				return __( 'No VAT ID, no company name.', 'invoqk' );
			case 'foreign':
				return __( 'Billing country is not Germany.', 'invoqk' );
			case 'small_amount':
				return sprintf( __( 'Total of %s € or less (small amount invoice).', 'invoqk' ), $t );
			case 'zero_total':
				return __( 'Order total is 0 €.', 'invoqk' );
			case 'no_country':
				return __( 'No billing country given.', 'invoqk' );
		}
		return '';
	}

	public static function hintText( ?string $hint ): string {
		switch ( $hint ) {
			case 'no_vat_id':
				return __( 'Note: no VAT ID given.', 'invoqk' );
			case 'vat_id_format':
				return __( 'Note: the VAT ID does not have a German format (DE and 9 digits).', 'invoqk' );
		}
		return '';
	}

	/** @return string[] order meta keys that may hold the buyer's VAT ID */
	public static function vatKeys(): array {
		return array_map( 'strval', (array) apply_filters( 'invoqk_vat_id_meta_keys', [ '_billing_vat_id', '_billing_eu_vat_number', '_billing_vat_number', '_vat_number' ] ) );
	}

	public static function vatId( \WC_Order $order ): string {
		foreach ( self::vatKeys() as $k ) {
			$v = trim( (string) $order->get_meta( (string) $k ) );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	private static function normalize( string $vat ): string {
		return strtoupper( str_replace( [ ' ', '.', '-' ], '', $vat ) );
	}
}
